<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class DeliverHermesOnboardingWebhook implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'hermes-onboarding';

    public int $tries = 3;

    public int $timeout = 10;

    public array $backoff = [15, 60];

    /**
     * @param  array{event_id: string, event_type: string, event_version: int, source: string, occurred_at: string, data: array{user_id: int, name: string, email: string, registered_at: string}}  $payload
     */
    public function __construct(private readonly array $payload)
    {
        $this->onConnection(self::QUEUE)->onQueue(self::QUEUE)->beforeCommit();
    }

    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('services.hermes_onboarding.enabled') === true;
    }

    public static function forRegistration(User $user): self
    {
        $registeredAt = $user->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        return new self([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'user.registered',
            'event_version' => 1,
            'source' => 'joyumkm',
            'occurred_at' => $registeredAt,
            'data' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'registered_at' => $registeredAt,
            ],
        ]);
    }

    public function handle(Factory $http): void
    {
        if (! self::enabled()) {
            $this->fail(new RuntimeException('Hermes onboarding is disabled.'));

            return;
        }

        $url = (string) config('services.hermes_onboarding.url');
        $secret = (string) config('services.hermes_onboarding.secret');
        if (strlen($secret) < 32 || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! preg_match('~\Ahttps?://127\.0\.0\.1:[1-9][0-9]{0,4}/joyumkm-onboarding\z~', $url)) {
            $this->fail(new RuntimeException('Hermes onboarding configuration is invalid.'));

            return;
        }

        $body = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        try {
            $response = $http->connectTimeout(2)->timeout(5)->withoutRedirecting()->acceptJson()
                ->withHeaders(['X-JoyUMKM-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret)])
                ->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException) {
            throw new RuntimeException('Hermes onboarding receiver unavailable.');
        }

        if ($response->tooManyRequests() || $response->serverError()) {
            throw new RuntimeException('Hermes onboarding receiver temporarily rejected the webhook.');
        }

        $status = $response->json('status');
        $runId = $response->json('run_id');
        if ($response->json('event_id') === $this->payload['event_id']
            && is_string($runId) && preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $runId)
            && (($response->status() === 202 && $status === 'accepted')
                || ($response->status() === 200 && $status === 'duplicate'))) {
            Log::info('Hermes onboarding webhook acknowledged.', [
                'event_id' => $this->payload['event_id'],
                'run_id' => $runId,
                'status' => $status,
            ]);

            return;
        }

        $this->fail(new RuntimeException('Hermes onboarding rejected the webhook or returned an invalid acknowledgement.'));
    }
}
