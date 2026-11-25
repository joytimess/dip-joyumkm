<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntelligenceArtifactManifest;
use App\Models\IntelligenceReport;
use App\Models\SixFactorEvaluation;
use App\Models\WebhookOutbox;
use App\Services\IntelligenceCallbackPayload;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IntelligenceDeliveryStatusController extends Controller
{
    public const MAPPING = [
        'waiting_package' => 'queued', 'processing' => 'queued', 'sending' => 'queued',
        'sent' => 'sent', 'failed_retryable' => 'failed_retryable', 'failed_permanent' => 'failed_permanent',
        'skipped_ineligible' => 'skipped_ineligible', 'recipient_changed' => 'skipped_ineligible',
        'blocked' => 'blocked', 'delivery_unknown' => 'delivery_unknown',
    ];

    private const HASHES = ['artifact_set_hash', 'transport_artifact_set_hash', 'source_report_sha256', 'report_hash'];

    private const PINNED = [...self::HASHES, 'recipient_fingerprint', 'consent_version', 'idempotency_key', 'message_id'];

    private const TERMINAL = ['sent', 'failed_permanent', 'skipped_ineligible', 'recipient_changed', 'blocked', 'delivery_unknown'];

    private const ERRORS = [
        'EMAIL_CONFIG_OR_PACKAGE_INVALID', 'EMAIL_INTERRUPTED_BEFORE_DATA', 'EMAIL_INTERRUPTED_DATA',
        'EMAIL_PACKAGE_CHANGED', 'EMAIL_PACKAGE_INVALID', 'EMAIL_PAUSED', 'EMAIL_RETRY_EXHAUSTED',
        'EMAIL_TOO_LARGE', 'EVENT_NOT_FOUND', 'RECIPIENT_CHANGED', 'RECIPIENT_CONFIG_INVALID',
        'RECIPIENT_EXPIRED', 'RECIPIENT_IDENTITY_CONFLICT', 'RECIPIENT_METHOD_INVALID',
        'RECIPIENT_REQUEST_INVALID', 'RECIPIENT_RESPONSE_INVALID', 'RECIPIENT_UNAUTHORIZED',
        'RECIPIENT_UNAVAILABLE', 'SMTP_AUTH_REJECTED', 'SMTP_CONNECTION_LOST', 'SMTP_PERMANENT_REJECTION',
        'SMTP_TEMPORARY_REJECTION', 'SMTP_TLS_OR_PROTOCOL', 'SMTP_UNEXPECTED_REPLY',
        'EVALUATION_NOT_FINAL', 'NO_CONSENT', 'NO_BUSINESS_EMAIL', 'INVALID_EMAIL', 'SUPPRESSED',
    ];

    public function store(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        try {
            $snapshot = IntelligenceCallbackPayload::fromJson($raw)->data;
        } catch (ValidationException) {
            return $this->error('invalid_payload', 422);
        }
        if (! $this->valid($snapshot)) {
            return $this->error('invalid_payload', 422);
        }

        try {
            return DB::transaction(fn () => $this->persist($snapshot, hash('sha256', $raw)), attempts: 3);
        } catch (QueryException) {
            return $this->error('delivery_unavailable', 503);
        }
    }

    private function valid(array $s): bool
    {
        $fields = [...self::PINNED, 'schema_version', 'event_id', 'channel', 'revision', 'status', 'attempt_no',
            'sent_at', 'occurred_at', 'next_retry_at', 'error_code'];
        if (count($s) !== count($fields) || array_diff($fields, array_keys($s)) !== []
            || $s['schema_version'] !== 1 || $s['channel'] !== 'email'
            || ! is_string($s['event_id']) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $s['event_id']) !== 1
            || ! is_int($s['revision']) || $s['revision'] < 1 || $s['revision'] > 9007199254740991
            || ! is_int($s['attempt_no']) || $s['attempt_no'] < 0 || $s['attempt_no'] > 4
            || ! is_string($s['status']) || ! isset(self::MAPPING[$s['status']])) {
            return false;
        }

        foreach ([...self::HASHES, 'recipient_fingerprint', 'idempotency_key'] as $field) {
            if ($s[$field] !== null && (! is_string($s[$field]) || preg_match('/\A[0-9a-f]{64}\z/', $s[$field]) !== 1)) {
                return false;
            }
        }
        $hashCount = count(array_filter(array_intersect_key($s, array_flip(self::HASHES)), fn ($value) => $value !== null));
        if ($hashCount !== ($s['attempt_no'] > 0 ? 4 : 0)) {
            return false;
        }
        $status = $s['status'];
        if (($status === 'waiting_package' && $s['attempt_no'] !== 0)
            || (in_array($status, ['processing', 'sending', 'sent', 'delivery_unknown', 'failed_retryable'], true) && $s['attempt_no'] === 0)
            || ($status === 'failed_retryable' && $s['attempt_no'] === 4)) {
            return false;
        }

        $fingerprint = $s['recipient_fingerprint'];
        if ($fingerprint === null) {
            if ($s['consent_version'] !== null || $s['message_id'] !== null || $s['idempotency_key'] !== null
                || in_array($status, ['sending', 'sent', 'delivery_unknown', 'recipient_changed'], true)) {
                return false;
            }
        } else {
            $key = hash('sha256', implode("\n", [$s['event_id'], 'email', $fingerprint, $s['artifact_set_hash']]));
            $domain = config('services.hermes_intelligence_delivery_status.message_domain');
            if ($hashCount !== 4 || $s['consent_version'] !== 'v1' || $s['idempotency_key'] !== $key
                || ! is_string($domain) || strlen($domain) > 188 || preg_match('/\A[a-z0-9-]+(?:\.[a-z0-9-]+)+\z/', $domain) !== 1
                || $s['message_id'] !== "<{$key}@{$domain}>") {
                return false;
            }
        }

        $requiresError = ! in_array($status, ['waiting_package', 'processing', 'sending', 'sent'], true);
        if ($requiresError ? ! in_array($s['error_code'], self::ERRORS, true) : $s['error_code'] !== null) {
            return false;
        }
        $occurred = $this->time($s['occurred_at']);
        $sent = $this->time($s['sent_at']);
        $retry = $this->time($s['next_retry_at']);

        return $occurred !== null && $occurred->lte(now()->addSeconds(300))
            && ($status === 'sent' ? $sent !== null && $sent->lte($occurred) : $s['sent_at'] === null)
            && ($status === 'failed_retryable' ? $retry !== null && $retry->gt($occurred)
                && $retry->lte($occurred->addSeconds(5400)) : $s['next_retry_at'] === null);
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A(20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?Z\z/', $value, $match) !== 1) {
            return null;
        }
        $canonical = $match[1].'.'.str_pad($match[2] ?? '', 6, '0').'Z';
        try {
            $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $canonical, 'UTC');

            return $time && $time->format('Y-m-d\TH:i:s.u\Z') === $canonical ? $time : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function persist(array $snapshot, string $digest): JsonResponse
    {
        $eventId = $snapshot['event_id'];
        $event = WebhookOutbox::query()->where('event_id', $eventId)->lockForUpdate()->first();
        if ($event?->event_type !== 'evaluation.finalized' || $event->event_version !== 2) {
            return $this->error('event_not_found', 404);
        }
        $evaluation = SixFactorEvaluation::query()->with('umkm')->find($event->aggregate_id);
        if ($evaluation?->umkm === null || $evaluation->status !== SixFactorEvaluation::FINAL || $evaluation->finalized_at === null
            || data_get($event->payload, 'event_id') !== $eventId
            || data_get($event->payload, 'event_type') !== $event->event_type
            || data_get($event->payload, 'event_version') !== $event->event_version
            || data_get($event->payload, 'source') !== 'joyumkm'
            || data_get($event->payload, 'data.six_factor.evaluation_id') !== $evaluation->id
            || data_get($event->payload, 'data.six_factor.status') !== SixFactorEvaluation::FINAL
            || data_get($event->payload, 'data.umkm_id') !== $evaluation->umkm->id) {
            return $this->error('event_identity_conflict', 409);
        }
        $report = IntelligenceReport::query()->where('event_id', $eventId)->first();
        if ($report === null) {
            return $this->error('report_not_ready', 409);
        }
        if ($report->evaluation_id !== $evaluation->id || $report->umkm_id !== $evaluation->umkm->id
            || $report->source_event_version !== 2 || ($snapshot['report_hash'] !== null && $snapshot['report_hash'] !== $report->report_hash)) {
            return $this->error('report_conflict', 409);
        }
        $manifest = IntelligenceArtifactManifest::query()->where('report_id', $report->id)->where('event_id', $eventId)->first();
        if ($manifest === null) {
            return $this->error('report_conflict', 409);
        }
        if ($snapshot['transport_artifact_set_hash'] === null) {
            return $this->error('report_not_ready', 409);
        }
        if ($snapshot['transport_artifact_set_hash'] !== $manifest->transport_artifact_set_hash) {
            return $this->error('artifact_conflict', 409);
        }

        $deliveries = DB::table('intelligence_deliveries')->where('event_id', $eventId)->where('channel', 'email');
        $rows = (clone $deliveries)->lockForUpdate()->get();
        $row = $rows->first();
        if ($rows->count() > 1 || ($row !== null && $row->email_snapshot === null)) {
            return $this->error('delivery_conflict', 409);
        }
        if ($row !== null) {
            $previous = json_decode($row->email_snapshot, true, flags: JSON_THROW_ON_ERROR);
            if ($snapshot['revision'] < $previous['revision']) {
                return $this->ack('stale', $previous, $row->email_snapshot_sha256);
            }
            if ($snapshot['revision'] === $previous['revision']) {
                return hash_equals($row->email_snapshot_sha256, $digest)
                    ? $this->ack('duplicate', $previous, $digest)
                    : $this->error('delivery_conflict', 409);
            }
            if (! $this->canAdvance($previous, $snapshot)) {
                return $this->error('delivery_conflict', 409);
            }
        }

        $values = [
            'recipient_fingerprint' => $snapshot['recipient_fingerprint'] ?? '',
            'artifact_set_hash' => $snapshot['artifact_set_hash'],
            'status' => self::MAPPING[$snapshot['status']],
            'attempt_no' => $snapshot['attempt_no'], 'error_code' => $snapshot['error_code'],
            'sent_at' => $this->time($snapshot['sent_at'])?->format('Y-m-d H:i:s'),
            'email_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'email_snapshot_sha256' => $digest, 'updated_at' => now(),
            'provider_message_id' => $snapshot['message_id'],
        ];
        if ($snapshot['idempotency_key'] !== null) {
            // Kunci kontrak SMTP (validasi di atas) disimpan begitu identitas penerima tersedia.
            $values['idempotency_key'] = $snapshot['idempotency_key'];
        }
        if ($row === null) {
            // Placeholder NOT NULL untuk baris yang dibuat sebelum penerima diketahui; diganti saat kunci tersedia.
            $deliveries->insert($values + ['event_id' => $eventId, 'channel' => 'email',
                'idempotency_key' => hash('sha256', "JMI-DELIVERY-STATUS-V1\n{$eventId}\nemail"), 'created_at' => now()]);
        } else {
            $deliveries->where('id', $row->id)->update($values);
        }

        return $this->ack($row === null ? 'stored' : 'updated', $snapshot, $digest);
    }

    private function canAdvance(array $previous, array $next): bool
    {
        foreach (self::PINNED as $field) {
            if ($previous[$field] !== null && $previous[$field] !== $next[$field]) {
                return false;
            }
        }
        if (in_array($previous['status'], self::TERMINAL, true)) {
            unset($previous['revision'], $next['revision']);

            return $previous == $next;
        }

        return $next['attempt_no'] >= $previous['attempt_no']
            && ! ($previous['status'] === 'failed_retryable' && $next['attempt_no'] === $previous['attempt_no']
                && ! in_array($next['status'], ['failed_retryable', 'failed_permanent', 'blocked'], true))
            && ! ($previous['status'] === 'sending' && $next['status'] === 'processing'
                && $next['attempt_no'] === $previous['attempt_no']);
    }

    private function ack(string $result, array $s, string $digest): JsonResponse
    {
        return response()->json([
            'schema_version' => 1, 'result' => $result, 'event_id' => $s['event_id'], 'channel' => 'email',
            'accepted_revision' => $s['revision'], 'snapshot_sha256' => $digest, 'status' => self::MAPPING[$s['status']],
            'attempt_no' => $s['attempt_no'], 'report_hash' => $s['report_hash'],
            'transport_artifact_set_hash' => $s['transport_artifact_set_hash'],
        ], $result === 'stored' ? 201 : 200);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['code' => $code], $status)->header('Cache-Control', 'no-store');
    }
}
