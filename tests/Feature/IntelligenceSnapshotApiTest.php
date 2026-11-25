<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\WebhookOutbox;
use App\Services\HermesWebhookOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IntelligenceSnapshotApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'snapshot-test-secret-0123456789abcdef';

    private const CALLBACK_SECRET = 'callback-test-secret-0123456789abcdef';

    private const WEBHOOK_SECRET = 'webhook-test-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        config([
            'services.hermes_intelligence_snapshot.secret' => self::SECRET,
            'services.hermes_intelligence_snapshot.tolerance_seconds' => 300,
            'services.hermes_intelligence_callback.secret' => self::CALLBACK_SECRET,
            'services.hermes_webhook.secret' => self::WEBHOOK_SECRET,
        ]);
    }

    private function createFinal(Umkm $umkm, string $finalizedAtUtc): SixFactorEvaluation
    {
        return SixFactorEvaluation::factory()->for($umkm)->final()->create([
            'finalized_at' => CarbonImmutable::parse($finalizedAtUtc, 'UTC'),
        ]);
    }

    private function eventFor(SixFactorEvaluation $evaluation): WebhookOutbox
    {
        app(HermesWebhookOutbox::class)->recordFinalization($evaluation);

        return WebhookOutbox::query()
            ->where('aggregate_id', $evaluation->id)
            ->where('event_type', 'evaluation.finalized')
            ->sole();
    }

    private function path(string $eventId): string
    {
        return '/api/internal/intelligence/events/'.$eventId.'/snapshot';
    }

    /** @param  array<string, string>  $overrides */
    private function signedGet(string $eventId, ?int $timestamp = null, ?string $secret = self::SECRET, array $overrides = []): TestResponse
    {
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp."\nGET\n".$this->path($eventId), (string) $secret);

        return $this->get($this->path($eventId), array_merge([
            'Accept' => 'application/json',
            'X-Intelligence-Snapshot-Timestamp' => (string) $timestamp,
            'X-Intelligence-Snapshot-Signature' => $signature,
        ], $overrides));
    }

    public function test_signed_pull_returns_only_the_event_umkm_history_up_to_the_cutoff(): void
    {
        $umkm = Umkm::factory()->create(['business_email' => 'pemilik-rahasia@example.test']);
        $october = $this->createFinal($umkm, '2026-10-05 02:00:00');
        $anchor = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $this->createFinal($umkm, '2026-11-20 08:00:00');
        $other = Umkm::factory()->create();
        $otherFinal = $this->createFinal($other, '2026-11-09 08:00:00');
        $event = $this->eventFor($anchor);

        $response = $this->signedGet($event->event_id)->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = $response->json();
        $this->assertSame(1, $body['schema_version']);
        $this->assertSame($event->event_id, $body['event_id']);
        $this->assertSame('evaluation.finalized', $body['event_type']);
        $this->assertSame(2, $body['event_version']);
        $this->assertSame($umkm->id, $body['umkm_id']);
        $this->assertSame($anchor->id, $body['evaluation_id']);
        $this->assertSame('2026-11-10T08:00:00Z', $body['cutoff']['at']);
        $this->assertSame($anchor->id, $body['cutoff']['evaluation_id']);
        $this->assertSame('Asia/Jakarta', $body['period_timezone']);
        $this->assertSame('2026-11', $body['period_month']);
        $this->assertSame('2026-10', $body['previous_period_month']);
        $this->assertSame(12, $body['history_months']);
        $this->assertSame($anchor->id, $body['current']['six_factor']['evaluation_id']);
        $this->assertSame($october->id, $body['previous']['six_factor']['evaluation_id']);
        $this->assertCount(12, $body['history']);
        $this->assertSame([$october->id, $anchor->id], $body['source']['six_factor_evaluation_ids']);
        $this->assertNotContains($otherFinal->id, $body['source']['six_factor_evaluation_ids']);
        $this->assertSame(2, $body['availability']['six_factor_final_months']);
        $this->assertSame('available', $body['availability']['current_six_factor']);
        $this->assertSame('data_gap', $body['availability']['current_three_dimension']);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('pemilik-rahasia@example.test', $raw);
        $this->assertStringNotContainsString('business_email', $raw);
        $this->assertStringNotContainsString('owner_name', $raw);
        $this->assertStringNotContainsString('whatsapp', strtolower($raw));
        $this->assertLessThan(64 * 1024, strlen($raw), 'Respons harus berada di bawah batas ukuran aman.');
    }

    public function test_data_gaps_are_null_and_never_zero_and_the_profile_is_not_returned(): void
    {
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $event = $this->eventFor($anchor);

        $body = $this->signedGet($event->event_id)->assertOk()->json();

        $previous = $body['previous'];
        $this->assertSame('data_gap', $previous['six_factor']['status']);
        $this->assertNull($previous['six_factor']['evaluation_id']);
        $this->assertNull($previous['six_factor']['total_score']);
        $this->assertNull($previous['six_factor']['scores']);
        $this->assertSame('data_gap', $previous['three_dimension']['status']);
        $this->assertNull($previous['three_dimension']['average_score']);
        $this->assertArrayNotHasKey('business_context', $body);
        $this->assertArrayNotHasKey('name', $body);
    }

    public function test_requests_without_a_valid_signature_are_rejected(): void
    {
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $event = $this->eventFor($anchor);
        $now = now()->getTimestamp();

        $this->get($this->path($event->event_id), ['Accept' => 'application/json'])->assertUnauthorized()->assertJsonPath('code', 'unauthorized');
        $this->signedGet($event->event_id, $now, 'wrong-secret-0123456789abcdef-wrong')->assertUnauthorized();
        $this->signedGet($event->event_id, $now, self::CALLBACK_SECRET)->assertUnauthorized();
        $this->signedGet($event->event_id, $now - 301)->assertUnauthorized();
        $this->signedGet($event->event_id, $now + 301)->assertUnauthorized();
        $this->signedGet($event->event_id, null, self::SECRET, ['X-Intelligence-Snapshot-Timestamp' => 'abc'])->assertUnauthorized();

        $otherEventId = (string) Str::uuid();
        $signedForOther = hash_hmac('sha256', $now."\nGET\n".$this->path($otherEventId), self::SECRET);
        $this->get($this->path($event->event_id), [
            'Accept' => 'application/json',
            'X-Intelligence-Snapshot-Timestamp' => (string) $now,
            'X-Intelligence-Snapshot-Signature' => $signedForOther,
        ])->assertUnauthorized();

        $this->get($this->path($event->event_id), [
            'Accept' => 'application/json',
            'X-Intelligence-Snapshot-Timestamp' => (string) $now,
            'X-Intelligence-Snapshot-Signature' => hash_hmac('sha256', $now."\nPOST\n".$this->path($event->event_id), self::SECRET),
        ])->assertUnauthorized();
    }

    public function test_a_timestamp_inside_the_tolerance_window_is_accepted(): void
    {
        // Waktu dibekukan agar selisih tepat di batas toleransi tidak bergeser menjadi 301 detik.
        $this->freezeTime();
        $umkm = Umkm::factory()->create();
        $event = $this->eventFor($this->createFinal($umkm, '2026-11-10 08:00:00'));

        $this->signedGet($event->event_id, now()->getTimestamp() - 300)->assertOk();
    }

    public function test_snapshot_fails_closed_when_its_secret_is_missing_short_or_reused(): void
    {
        $umkm = Umkm::factory()->create();
        $event = $this->eventFor($this->createFinal($umkm, '2026-11-10 08:00:00'));

        config(['services.hermes_intelligence_snapshot.secret' => null]);
        $this->signedGet($event->event_id, null, self::SECRET)->assertStatus(503)->assertJsonPath('code', 'snapshot_unavailable');

        config(['services.hermes_intelligence_snapshot.secret' => 'short']);
        $this->signedGet($event->event_id, null, 'short')->assertStatus(503);

        config(['services.hermes_intelligence_snapshot.secret' => self::CALLBACK_SECRET]);
        $this->signedGet($event->event_id, null, self::CALLBACK_SECRET)->assertStatus(503);

        config(['services.hermes_intelligence_snapshot.secret' => self::WEBHOOK_SECRET]);
        $this->signedGet($event->event_id, null, self::WEBHOOK_SECRET)->assertStatus(503);
    }

    public function test_a_body_on_the_snapshot_request_is_rejected(): void
    {
        $umkm = Umkm::factory()->create();
        $event = $this->eventFor($this->createFinal($umkm, '2026-11-10 08:00:00'));
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp."\nGET\n".$this->path($event->event_id), self::SECRET);

        $this->call('GET', $this->path($event->event_id), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INTELLIGENCE_SNAPSHOT_TIMESTAMP' => $timestamp,
            'HTTP_X_INTELLIGENCE_SNAPSHOT_SIGNATURE' => $signature,
        ], '{"umkm_id":1}')->assertStatus(400)->assertJsonPath('code', 'unexpected_body');
    }

    public function test_unknown_or_malformed_events_are_not_found(): void
    {
        $this->signedGet((string) Str::uuid())->assertNotFound()->assertJsonPath('code', 'event_not_found');
        $this->signedGet('not-a-uuid')->assertNotFound();
        $this->signedGet(strtoupper((string) Str::uuid()))->assertNotFound();
    }

    public function test_legacy_version_one_events_have_no_historical_snapshot(): void
    {
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $eventId = (string) Str::uuid();
        WebhookOutbox::query()->forceCreate([
            'event_id' => $eventId,
            'event_type' => 'evaluation.finalized',
            'event_version' => 1,
            'aggregate_id' => $anchor->id,
            'payload' => ['event_id' => $eventId, 'event_type' => 'evaluation.finalized', 'event_version' => 1, 'data' => ['umkm_id' => $umkm->id]],
            'status' => WebhookOutbox::DELIVERED,
            'attempts' => 1,
        ]);

        $this->signedGet($eventId)->assertStatus(422)->assertJsonPath('code', 'unsupported_event_version');
    }

    public function test_event_whose_umkm_no_longer_matches_its_evaluation_is_rejected(): void
    {
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $event = $this->eventFor($anchor);
        $otherUmkm = Umkm::factory()->create();
        $payload = $event->payload;
        $payload['data']['umkm_id'] = $otherUmkm->id;
        $event->forceFill(['payload' => $payload])->save();

        $this->signedGet($event->event_id)->assertStatus(409)->assertJsonPath('code', 'event_evaluation_mismatch');
    }

    public function test_later_finals_do_not_change_an_already_issued_event_snapshot(): void
    {
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $event = $this->eventFor($anchor);
        $first = $this->signedGet($event->event_id)->assertOk()->json();

        $this->createFinal($umkm, '2026-11-25 08:00:00');

        $this->assertSame($first, $this->signedGet($event->event_id)->assertOk()->json());
    }

    public function test_signature_computed_by_the_hermes_client_is_accepted(): void
    {
        // Vektor silang: signature dihitung oleh klien Python (jmi/snapshot.py) untuk secret uji, timestamp, dan path tetap.
        $eventId = '7f0c1d52-3b8a-4e7e-9c31-5a2f6d9e1b01';
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-22 13:10:20');
        WebhookOutbox::query()->forceCreate([
            'event_id' => $eventId,
            'event_type' => 'evaluation.finalized',
            'event_version' => 2,
            'aggregate_id' => $anchor->id,
            'payload' => ['data' => ['umkm_id' => $umkm->id]],
            'status' => WebhookOutbox::DELIVERED,
            'attempts' => 1,
        ]);
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(1798000000));

        $this->get($this->path($eventId), [
            'Accept' => 'application/json',
            'X-Intelligence-Snapshot-Timestamp' => '1798000000',
            'X-Intelligence-Snapshot-Signature' => '0df90841d68605c84a0ee01f888a6bacef793055650cbe050a1fb3dfacbce24f',
        ])->assertOk()->assertJsonPath('evaluation_id', $anchor->id);
    }

    public function test_pull_response_has_the_same_key_structure_as_the_shared_contract_sample(): void
    {
        $umkm = Umkm::factory()->create();
        $this->createFinal($umkm, '2026-10-15 02:00:00');
        $umkm->evaluations()->create([
            'status' => 'final', 'ins_score' => 71.5, 'ect_score' => 81.25, 'ops_score' => 91, 'average_score' => 81.3,
            'category' => 'BAIK', 'submitted_at' => CarbonImmutable::parse('2026-11-21 08:30:00', 'UTC'),
        ]);
        $anchor = $this->createFinal($umkm, '2026-11-22 13:10:20');
        $event = $this->eventFor($anchor);

        $actual = $this->signedGet($event->event_id)->assertOk()->json();
        $sample = json_decode((string) file_get_contents(base_path('tests/Fixtures/intelligence-snapshot/pull-response.sample.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($this->keyPaths($sample), $this->keyPaths($actual));
        $this->assertSame(array_keys($sample), array_keys($actual));
    }

    /** @return list<string> */
    private function keyPaths(array $node, string $prefix = ''): array
    {
        $paths = [];
        foreach ($node as $key => $value) {
            if (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                $paths[] = $prefix.$key.'[]';
                $paths = [...$paths, ...$this->keyPaths($value[0], $prefix.$key.'[].')];

                continue;
            }

            $paths[] = $prefix.$key;
            if (is_array($value) && ! array_is_list($value)) {
                $paths = [...$paths, ...$this->keyPaths($value, $prefix.$key.'.')];
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }
}
