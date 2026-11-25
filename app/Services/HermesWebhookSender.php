<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WebhookOutbox;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HermesWebhookSender
{
    /** @return array{event_id: string, evaluation_id: int, umkm_id: int}|null */
    public function uatPin(): ?array
    {
        $raw = @file_get_contents(storage_path('app/private/hermes-uat-scope.json'), length: 4096);
        $scope = $raw === false ? null : json_decode($raw, true);
        if (! is_array($scope)
            || ($scope['mode'] ?? null) !== 'pinned'
            || ! is_string($scope['run_id'] ?? null)
            || ! preg_match('/^\d{8}T\d{6}Z$/D', $scope['run_id'])
            || ! is_int($scope['expires_at'] ?? null)
            || $scope['expires_at'] <= now()->getTimestamp()
            || ! is_string($scope['event_id'] ?? null)
            || ! preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $scope['event_id'])
            || ! is_int($scope['evaluation_id'] ?? null) || $scope['evaluation_id'] <= 0
            || ! is_int($scope['umkm_id'] ?? null) || $scope['umkm_id'] <= 0) {
            return null;
        }

        return $scope;
    }

    public function allows(WebhookOutbox $event): bool
    {
        if (! config('services.hermes_webhook.uat_scope_enabled')) {
            return true;
        }

        $pin = $this->uatPin();
        $payload = $event->payload;

        return $pin !== null && $event->event_id === $pin['event_id']
            && $event->event_type === 'evaluation.finalized' && $event->event_version === 2
            && $event->aggregate_id === $pin['evaluation_id']
            && ($payload['source'] ?? null) === 'joyumkm'
            && ($payload['event_id'] ?? null) === $pin['event_id']
            && ($payload['event_type'] ?? null) === 'evaluation.finalized'
            && ($payload['event_version'] ?? null) === 2
            && ($payload['data']['six_factor']['evaluation_id'] ?? null) === $pin['evaluation_id']
            && ($payload['data']['umkm_id'] ?? null) === $pin['umkm_id'];
    }

    public function configurationIssue(): ?string
    {
        if (config('services.hermes_webhook.enabled') !== true) {
            return 'disabled';
        }

        $url = (string) config('services.hermes_webhook.url');
        $allowedHost = strtolower(trim((string) config('services.hermes_webhook.allowed_host')));
        $secret = (string) config('services.hermes_webhook.secret');
        if ($url === '' || $allowedHost === '' || trim($secret) === '') {
            return 'missing_config';
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || str_contains($url, '\\')
            || $host !== $allowedHost
            || array_intersect(['user', 'pass', 'fragment', 'query'], array_keys($parts ?: []))) {
            return 'invalid_destination';
        }

        $scheme = strtolower($parts['scheme']);
        if (app()->environment(['local', 'testing']) && $scheme === 'http' && $host === '127.0.0.1') {
            return null;
        }

        return $scheme === 'https'
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && preg_match('/\.[a-z][a-z0-9-]*$/D', $host)
            && ! str_ends_with($host, '.localhost')
            ? null
            : 'invalid_destination';
    }

    public static function signature(int $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array{http_status: ?int, error: ?string, summary: ?string, retryable: bool, retry_after: int} */
    public function send(WebhookOutbox $event): array
    {
        if (! $this->allows($event)) {
            return ['http_status' => null, 'error' => 'uat_scope_denied', 'summary' => 'Event di luar cakupan UAT.', 'retryable' => true, 'retry_after' => 0];
        }

        $body = $event->getRawOriginal('payload');
        $timestamp = now()->getTimestamp();
        $secret = (string) config('services.hermes_webhook.secret');
        $timeout = max(1, min(15, (int) config('services.hermes_webhook.timeout', 5)));

        try {
            $response = Http::connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->withoutRedirecting()
                ->withOptions(['proxy' => ''])
                ->withHeaders([
                    'X-Request-ID' => $event->event_id,
                    'X-Webhook-Timestamp' => (string) $timestamp,
                    'X-Webhook-Signature-V2' => self::signature(
                        $timestamp,
                        $body,
                        $secret
                    ),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('services.hermes_webhook.url'));
        } catch (ConnectionException $e) {
            logger()->warning('Hermes webhook connection failure', [
                'event_id' => $event->event_id,
                'exception' => $e->getMessage(),
            ]);

            return [
                'http_status' => null,
                'error' => 'network_error',
                'summary' => 'Koneksi webhook gagal atau waktu tunggu habis.',
                'retryable' => true,
                'retry_after' => 0,
            ];
        }

        $status = $response->status();
        if (in_array($status, [200, 202], true)) {
            return $this->classifyAcknowledgement($event, $status, $response->body());
        }

        $retryable = in_array($status, [408, 429], true) || $response->serverError();
        $rejected = ! $retryable && ! $response->successful();

        return [
            'http_status' => $status,
            'error' => $retryable ? 'http_retryable' : ($rejected ? 'http_rejected' : 'invalid_ack'),
            'summary' => $retryable || $rejected
                ? 'Receiver tidak mengakui penerimaan webhook.'
                : 'Respons receiver bukan ACK Hermes yang valid.',
            'retryable' => $retryable,
            'retry_after' => in_array($status, [429, 503], true) ? $this->retryAfter($response->header('Retry-After')) : 0,
        ];
    }

    /**
     * Hanya `202 accepted`, `200 duplicate`, dan `200 delivered` (route deliver_only, tanpa agent) yang cocok
     * dengan event ini yang dianggap sukses.
     * ACK 2xx yang tidak sesuai kontrak tidak pernah di-retry otomatis karena Hermes mungkin sudah menerimanya.
     *
     * @return array{http_status: ?int, error: ?string, summary: ?string, retryable: bool, retry_after: int}
     */
    private function classifyAcknowledgement(WebhookOutbox $event, int $status, string $body): array
    {
        $ack = json_decode($body, true);
        $ack = is_array($ack) && ! array_is_list($ack) ? $ack : [];
        $state = $ack['status'] ?? null;
        $deliveryMatches = ($ack['delivery_id'] ?? null) === $event->event_id;
        $routeMatches = ($ack['route'] ?? null) === $this->expectedRoute();

        $accepted = $status === 202 && $state === 'accepted' && $deliveryMatches && $routeMatches
            && ($ack['event'] ?? null) === $event->event_type;
        $duplicate = $status === 200 && $state === 'duplicate' && $deliveryMatches;
        $delivered = $status === 200 && $state === 'delivered' && $deliveryMatches && $routeMatches;
        if ($accepted || $duplicate || $delivered) {
            return $this->acknowledgement($status, null, null);
        }

        return $status === 200 && $state === 'ignored'
            ? $this->acknowledgement($status, 'ack_ignored', 'Hermes mengabaikan event (route, filter, atau tipe event tidak cocok).')
            : $this->acknowledgement($status, 'invalid_ack', 'Respons receiver bukan ACK Hermes yang valid.');
    }

    /** @return array{http_status: ?int, error: ?string, summary: ?string, retryable: bool, retry_after: int} */
    private function acknowledgement(int $status, ?string $error, ?string $summary): array
    {
        return [
            'http_status' => $status,
            'error' => $error,
            'summary' => $summary,
            'retryable' => false,
            'retry_after' => 0,
        ];
    }

    private function expectedRoute(): string
    {
        return basename((string) parse_url((string) config('services.hermes_webhook.url'), PHP_URL_PATH));
    }

    private function retryAfter(string $header): int
    {
        $header = trim($header);
        if (ctype_digit($header)) {
            return (int) $header;
        }

        if (! preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $header)) {
            return 0;
        }

        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header, new \DateTimeZone('UTC'));

        return $date && $date->format(DATE_RFC7231) === $header
            ? max(0, $date->getTimestamp() - now()->getTimestamp())
            : 0;
    }
}
