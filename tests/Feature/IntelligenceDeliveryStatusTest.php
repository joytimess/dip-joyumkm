<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyIntelligenceDeliverySignature as Signature;
use App\Models\IntelligenceReport;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\IntelligenceArtifactContract;
use App\Services\IntelligenceCallbackPayload;
use App\Services\IntelligenceReportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\IntelligenceArtifactFixture as Artifacts;
use Tests\Fixtures\IntelligenceCallbackFixture as Report;
use Tests\TestCase;

class IntelligenceDeliveryStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY = 'synthetic-delivery-key-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hermes_intelligence_delivery_status.secret' => self::KEY,
            'services.hermes_intelligence_delivery_status.message_domain' => 'example.test']);
    }

    public function test_signed_ordered_projection_before_upload_and_authorized_reads(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Report::finalization();
        $manifest = Artifacts::projection(Artifacts::sample('complete'));
        $report = IntelligenceCallbackPayload::fromJson(json_encode(Report::payload($evaluation, $event, ['artifacts' => $manifest]), JSON_THROW_ON_ERROR));
        $fingerprint = str_repeat('a', 64);
        $local = str_repeat('b', 64);
        $smtpKey = hash('sha256', implode("\n", [$event->event_id, 'email', $fingerprint, $local]));
        $clock = now()->utc();
        $base = [
            'schema_version' => 1, 'event_id' => $event->event_id, 'channel' => 'email',
            'revision' => 1, 'status' => 'processing', 'attempt_no' => 1,
            'recipient_fingerprint' => null, 'consent_version' => null, 'artifact_set_hash' => $local,
            'transport_artifact_set_hash' => IntelligenceArtifactContract::setHash($manifest),
            'source_report_sha256' => str_repeat('c', 64), 'report_hash' => $report->hash(),
            'idempotency_key' => null, 'message_id' => null, 'sent_at' => null,
            'occurred_at' => $clock->format('Y-m-d\TH:i:s\Z'), 'next_retry_at' => null, 'error_code' => null,
        ];
        $sent = array_replace($base, ['revision' => 3, 'status' => 'sent', 'recipient_fingerprint' => $fingerprint,
            'consent_version' => 'v1', 'idempotency_key' => $smtpKey, 'message_id' => "<{$smtpKey}@example.test>",
            'sent_at' => $base['occurred_at']]);
        $this->postSnapshot(array_replace($sent, ['event_id' => '11111111-1111-4111-8111-111111111111',
            'status' => 'processing', 'sent_at' => null, 'recipient_fingerprint' => null, 'consent_version' => null,
            'idempotency_key' => null, 'message_id' => null]))->assertNotFound()->assertJsonPath('code', 'event_not_found');
        $legacy = Report::finalization(1)['event'];
        $this->postSnapshot(array_replace($base, ['event_id' => $legacy->event_id]))->assertNotFound();
        $this->postSnapshot(array_replace($base, ['channel' => 'artifact']))->assertUnprocessable();
        $conflictingEvent = Report::finalization()['event'];
        WebhookOutbox::query()->whereKey($conflictingEvent->id)->update(['payload->data->umkm_id' => 0]);
        $this->postSnapshot(array_replace($base, ['event_id' => $conflictingEvent->event_id]))
            ->assertConflict()->assertJsonPath('code', 'event_identity_conflict');
        $this->postSnapshot($sent)->assertConflict()->assertJsonPath('code', 'report_not_ready');
        $this->assertDatabaseEmpty('intelligence_deliveries');
        app(IntelligenceReportService::class)->store($report);
        $fresh = IntelligenceReport::query()->sole();
        $path = "/api/umkms/{$evaluation->umkm_id}/intelligence-reports/{$fresh->id}/artifacts";
        $this->getJson($path)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($path)->assertNotFound();
        Sanctum::actingAs($evaluation->umkm->user);
        $this->getJson($path)->assertOk()->assertJsonPath('email_delivery', null);
        $this->postSnapshot($base)->assertCreated()->assertJsonPath('status', 'queued');
        $this->postSnapshot($sent)->assertOk()->assertJsonPath('result', 'updated')->assertJsonPath('accepted_revision', 3);
        $before = (array) DB::table('intelligence_deliveries')->sole();
        $this->postSnapshot($sent)->assertOk()->assertJsonPath('result', 'duplicate')->assertJsonPath('snapshot_sha256', $before['email_snapshot_sha256']);
        $this->postSnapshot($base)->assertOk()->assertJsonPath('result', 'stale')->assertJsonPath('accepted_revision', 3);
        $this->postSnapshot(array_replace($sent, ['occurred_at' => $clock->addSecond()->format('Y-m-d\TH:i:s\Z')]))
            ->assertConflict()->assertJsonPath('code', 'delivery_conflict');
        $this->postSnapshot(array_replace($base, ['revision' => 4]))->assertConflict();
        $this->postSnapshot(array_replace($sent, ['revision' => 4, 'transport_artifact_set_hash' => str_repeat('d', 64)]))
            ->assertConflict()->assertJsonPath('code', 'artifact_conflict');
        $this->postSnapshot(array_replace($sent, ['revision' => 4, 'report_hash' => str_repeat('d', 64)]))
            ->assertConflict()->assertJsonPath('code', 'report_conflict');
        $this->assertSame($before, (array) DB::table('intelligence_deliveries')->sole());
        $this->assertDatabaseEmpty('intelligence_artifacts');
        $this->getJson($path)->assertOk()->assertJsonPath('artifact_status', 'pending')
            ->assertJsonPath('email_delivery', ['status' => 'sent', 'sent_at' => $sent['sent_at'], 'reason' => null])
            ->assertJsonMissing(['recipient_fingerprint' => $fingerprint]);
    }

    public function test_retry_unknown_and_ineligible_project_safe_statuses(): void
    {
        $manifest = Artifacts::projection(Artifacts::sample('complete'));
        foreach (['delivery_unknown', 'skipped_ineligible', 'recipient_changed', 'blocked'] as $outcome) {
            ['evaluation' => $evaluation, 'event' => $event] = Report::finalization();
            $raw = json_encode(Report::payload($evaluation, $event, ['artifacts' => $manifest]), JSON_THROW_ON_ERROR);
            $report = IntelligenceCallbackPayload::fromJson($raw);
            $stored = app(IntelligenceReportService::class)->store($report)['report'];
            $clock = now()->utc();
            $local = str_repeat('a', 64);
            $fingerprint = in_array($outcome, ['delivery_unknown', 'recipient_changed'], true) ? str_repeat('b', 64) : null;
            $key = $fingerprint === null ? null : hash('sha256', implode("\n", [$event->event_id, 'email', $fingerprint, $local]));
            $snapshot = [
                'schema_version' => 1, 'event_id' => $event->event_id, 'channel' => 'email',
                'revision' => 1, 'status' => $outcome, 'attempt_no' => 1,
                'recipient_fingerprint' => $fingerprint, 'consent_version' => $key === null ? null : 'v1',
                'artifact_set_hash' => $local, 'source_report_sha256' => str_repeat('c', 64), 'report_hash' => $report->hash(),
                'transport_artifact_set_hash' => IntelligenceArtifactContract::setHash($manifest),
                'idempotency_key' => $key, 'message_id' => $key === null ? null : "<{$key}@example.test>",
                'occurred_at' => $clock->format('Y-m-d\TH:i:s\Z'), 'sent_at' => null, 'next_retry_at' => null,
                'error_code' => match ($outcome) {
                    'delivery_unknown' => 'SMTP_CONNECTION_LOST',
                    'skipped_ineligible' => 'SUPPRESSED',
                    'recipient_changed' => 'RECIPIENT_CHANGED',
                    'blocked' => 'RECIPIENT_UNAUTHORIZED',
                },
            ];
            if ($outcome === 'delivery_unknown') {
                $retry = array_replace($snapshot, ['revision' => 1, 'status' => 'failed_retryable',
                    'next_retry_at' => $clock->copy()->addSeconds(600)->format('Y-m-d\TH:i:s\Z'), 'error_code' => 'SMTP_TEMPORARY_REJECTION']);
                $this->postSnapshot($retry)->assertCreated()->assertJsonPath('status', 'failed_retryable');
                $queued = array_replace($snapshot, ['revision' => 2, 'status' => 'processing', 'error_code' => null]);
                $this->postSnapshot($queued)->assertConflict();
                $this->postSnapshot(array_replace($queued, ['attempt_no' => 2]))->assertOk()->assertJsonPath('status', 'queued');
                $this->postSnapshot(array_replace($snapshot, ['revision' => 3]))->assertConflict();
                $this->postSnapshot(array_replace($snapshot, ['revision' => 3, 'attempt_no' => 2]))->assertOk();
            } else {
                $this->postSnapshot($snapshot)->assertCreated();
            }
            Sanctum::actingAs($evaluation->umkm->user);
            $this->getJson("/api/umkms/{$evaluation->umkm_id}/intelligence-reports/{$stored->id}/artifacts")
                ->assertOk()->assertJsonPath('email_delivery', [
                    'status' => $outcome, 'sent_at' => null, 'reason' => $outcome === 'skipped_ineligible' ? 'suppressed' : null,
                ]);
        }
        $this->assertDatabaseCount('intelligence_deliveries', 4);
    }

    public function test_signature_vector_and_invalid_requests(): void
    {
        $this->assertSame('be98d0b8bbac8370f6a7419bdfdbfc9d1e24703b6d2360bbd8d6b7b04a3b9425',
            Signature::sign(self::KEY, '1795568400', '{}'));
        $this->postSnapshot('{}', now()->timestamp - 301)->assertUnauthorized();
        $this->postSnapshot('{}', now()->timestamp + 301)->assertUnauthorized();
        $this->postSnapshot('{}', signature: str_repeat('a', 64))->assertUnauthorized();
        $this->postSnapshot('{}', signature: '')->assertUnauthorized();
        $stamp = now()->timestamp;
        $this->postSnapshot('{"tampered":true}', $stamp, Signature::sign(self::KEY, (string) $stamp, '{}'))->assertUnauthorized();
        $this->postSnapshot('{"a":1,"\\u0061":2}')->assertUnprocessable();
        $this->postSnapshot('{"broken":')->assertUnprocessable();
        $this->postSnapshot(str_repeat(' ', 16385))->assertStatus(413);
        $this->postSnapshot('{}', path: Signature::PATH.'?bad=1')->assertBadRequest();
        $this->getJson(Signature::PATH)->assertMethodNotAllowed();
        config(['services.hermes_intelligence_delivery_status.secret' => null]);
        $this->postSnapshot('{}')->assertServiceUnavailable()->assertJsonPath('code', 'delivery_config_invalid');
        $this->assertDatabaseEmpty('intelligence_deliveries');
    }

    private function postSnapshot(array|string $body, ?int $timestamp = null, ?string $signature = null, string $path = Signature::PATH): TestResponse
    {
        $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;
        $stamp = (string) ($timestamp ?? now()->timestamp);

        return $this->call('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($raw),
            'HTTP_X_INTELLIGENCE_SIGNATURE_VERSION' => '1', 'HTTP_X_INTELLIGENCE_TIMESTAMP' => $stamp,
            'HTTP_X_INTELLIGENCE_SIGNATURE' => $signature ?? Signature::sign(self::KEY, $stamp, $raw),
        ], content: $raw);
    }
}
