<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceReport;
use App\Models\User;
use App\Models\WebhookOutbox;
use Database\Factories\IntelligenceReportFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\IntelligenceCallbackFixture as Fixture;
use Tests\TestCase;

class IntelligenceCallbackTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hermes_intelligence_callback.secret' => Fixture::SECRET]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    /** @param array{headers: array<string, string>, body: string} $request */
    private function send(array $request): TestResponse
    {
        return $this->call('POST', Fixture::PATH, [], [], [], Fixture::server($request['headers']), $request['body']);
    }

    #[TestWith([1])]
    #[TestWith([2])]
    public function test_valid_report_for_event_version_is_stored(int $version): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization($version);
        $this->assertSame($version, $event->event_version);

        $response = $this->send(Fixture::signed(Fixture::payload($evaluation, $event)));

        $response->assertCreated()->assertJsonPath('status', 'stored')->assertJsonPath('event_id', $event->event_id);
        $report = IntelligenceReport::query()->sole();
        $this->assertSame($report->id, $response->json('report_id'));
        $this->assertSame($event->event_id, $report->event_id);
        $this->assertSame($evaluation->id, $report->evaluation_id);
        $this->assertSame($evaluation->umkm_id, $report->umkm_id);
        $this->assertSame($version, $report->source_event_version);
        $this->assertSame('complete', $report->methodology_status);
        $this->assertSame('2026-11-23 08:00:00', $report->generated_at->format('Y-m-d H:i:s'));
        $this->assertSame(64, strlen($report->report_hash));
        $this->assertSame('Ringkasan eksekutif contoh.', $report->document()->executive_summary);
        $this->assertSame('pending', $event->fresh()->status, 'Callback tidak boleh mengubah status outbox.');
    }

    public function test_partial_methodology_with_limitations_is_stored_and_without_limitations_is_rejected(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $partial = ['methodology_status' => 'partial'];
        $limited = IntelligenceReportFactory::sampleReport(['limitations' => ['Data tiga dimensi tidak tersedia.']]);

        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, $partial)))
            ->assertUnprocessable()->assertJsonValidationErrors('report.limitations');
        $this->assertDatabaseEmpty('intelligence_reports');

        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, [...$partial, 'report' => $limited])))->assertCreated();
        $report = IntelligenceReport::query()->sole();
        $this->assertSame('partial', $report->methodology_status);
        $this->assertSame(['Data tiga dimensi tidak tersedia.'], $report->document()->limitations);
    }

    public function test_empty_json_objects_stay_objects_and_key_order_does_not_change_the_hash(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $report = [...IntelligenceReportFactory::sampleReport(), 'perspectives' => new \stdClass];
        $payload = Fixture::payload($evaluation, $event, ['report' => $report]);

        $this->send(Fixture::signed($payload))->assertCreated();

        $stored = DB::table('intelligence_reports')->sole();
        $this->assertTrue(json_decode($stored->report, false)->perspectives instanceof \stdClass);
        $reordered = Fixture::signed(json_encode(array_reverse($payload, true)));
        $this->send($reordered)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertDatabaseCount('intelligence_reports', 1);
    }

    public function test_identical_resend_is_a_duplicate_without_mutation_even_with_a_fresh_signature(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $payload = Fixture::payload($evaluation, $event);

        $first = $this->send(Fixture::signed($payload, now()->getTimestamp() - 10))->assertCreated();
        $before = DB::table('intelligence_reports')->sole();
        $this->travel(30)->seconds();
        $second = $this->send(Fixture::signed($payload))->assertOk();

        $second->assertJsonPath('status', 'duplicate')->assertJsonPath('report_id', $first->json('report_id'));
        $this->assertEquals($before, DB::table('intelligence_reports')->sole());
    }

    public function test_different_content_for_a_stored_event_conflicts_and_never_overwrites(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $this->send(Fixture::signed(Fixture::payload($evaluation, $event)))->assertCreated();
        $before = DB::table('intelligence_reports')->sole();

        $changed = Fixture::payload($evaluation, $event, [
            'report' => IntelligenceReportFactory::sampleReport(['executive_summary' => 'Isi berbeda.']),
        ]);
        $this->send(Fixture::signed($changed))->assertConflict()->assertJsonPath('code', 'report_conflict');
        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, ['model' => 'model-lain'])))
            ->assertConflict()->assertJsonPath('code', 'report_conflict');

        $this->assertEquals($before, DB::table('intelligence_reports')->sole());
    }

    #[TestWith(['source_event_version', 1], 'versi event tidak cocok dengan outbox v2')]
    #[TestWith(['source_event_version', 3], 'versi event tidak didukung')]
    #[TestWith(['schema_version', 2], 'schema laporan tidak didukung')]
    #[TestWith(['status', 'failed'], 'status bukan completed')]
    #[TestWith(['methodology_status', 'unknown'], 'status metodologi tidak dikenal')]
    #[TestWith(['evaluation_id', '11'], 'ID berupa string')]
    #[TestWith(['umkm_id', 0], 'ID UMKM nol')]
    #[TestWith(['generated_at', '2026-13-45T99:00:00Z'], 'tanggal tidak valid')]
    #[TestWith(['event_id', 'bukan-uuid'], 'event_id bukan UUID')]
    public function test_invalid_metadata_is_rejected_without_storing(string $field, mixed $value): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();

        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, [$field => $value])))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_version_one_report_is_rejected_for_a_version_two_event_and_vice_versa(): void
    {
        foreach ([[2, 1], [1, 2]] as [$eventVersion, $claimed]) {
            ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization($eventVersion);

            $this->send(Fixture::signed(Fixture::payload($evaluation, $event, ['source_event_version' => $claimed])))
                ->assertUnprocessable()->assertJsonValidationErrors('source_event_version');
        }

        $this->assertDatabaseEmpty('intelligence_reports');
    }

    #[TestWith(['report'], 'laporan kosong')]
    #[TestWith(['report.executive_summary'], 'ringkasan hilang')]
    #[TestWith(['report.action_plan.days_31_60'], 'rencana 60 hari hilang')]
    #[TestWith(['report.top_priorities.0.actions'], 'aksi prioritas hilang')]
    public function test_missing_report_sections_are_rejected(string $path): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $payload = Fixture::payload($evaluation, $event);
        data_forget($payload, $path);

        $this->send(Fixture::signed($payload))->assertUnprocessable()->assertJsonValidationErrors($path);
        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_more_than_three_priorities_and_non_list_sections_are_rejected(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $priority = IntelligenceReportFactory::sampleReport()['top_priorities'][0];
        $report = IntelligenceReportFactory::sampleReport([
            'top_priorities' => [$priority, $priority, $priority, $priority],
            'limitations' => ['a' => 'bukan daftar'],
        ]);

        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, ['report' => $report])))
            ->assertUnprocessable()->assertJsonValidationErrors(['report.top_priorities', 'report.limitations']);
        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_unknown_event_returns_generic_not_found(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $payload = Fixture::payload($evaluation, $event, ['event_id' => (string) Str::uuid()]);

        $this->send(Fixture::signed($payload))->assertNotFound()->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_identity_must_match_the_event_and_its_snapshot(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        ['evaluation' => $other] = Fixture::finalization();

        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, ['evaluation_id' => $other->id])))
            ->assertUnprocessable()->assertJsonValidationErrors('evaluation_id');
        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, ['umkm_id' => $other->umkm_id])))
            ->assertUnprocessable()->assertJsonValidationErrors('umkm_id');

        $payload = $event->payload;
        $payload['data']['six_factor']['total_score'] = 30;
        WebhookOutbox::query()->whereKey($event->id)->update(['payload' => $payload]);
        $this->send(Fixture::signed(Fixture::payload($evaluation, $event)))
            ->assertUnprocessable()->assertJsonValidationErrors('event_id');

        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_unsupported_event_types_and_versions_in_the_outbox_are_rejected(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        WebhookOutbox::query()->whereKey($event->id)->update(['event_version' => 3]);

        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, ['source_event_version' => 2])))
            ->assertUnprocessable();

        WebhookOutbox::query()->whereKey($event->id)->update(['event_version' => 2, 'event_type' => 'evaluation.other']);
        $this->send(Fixture::signed(Fixture::payload($evaluation, $event)))->assertUnprocessable();
        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_pending_outbox_is_accepted_because_the_callback_may_arrive_before_the_ack(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $this->assertSame(WebhookOutbox::PENDING, $event->status);

        $this->send(Fixture::signed(Fixture::payload($evaluation, $event)))->assertCreated();
    }

    public function test_requests_with_bad_signatures_or_timestamps_are_rejected_with_a_generic_401(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $payload = Fixture::payload($evaluation, $event);
        $now = now()->getTimestamp();
        $stale = Fixture::signed($payload, $now - 301);
        $future = Fixture::signed($payload, $now + 301);
        $wrongSecret = Fixture::signed($payload, secret: str_repeat('x', 40));
        $tampered = Fixture::signed($payload);
        $tampered['body'] = str_replace('Ringkasan', 'Ringkasan palsu', $tampered['body']);
        $noSignature = Fixture::signed($payload);
        unset($noSignature['headers']['X-Intelligence-Signature']);
        $noTimestamp = Fixture::signed($payload);
        unset($noTimestamp['headers']['X-Intelligence-Timestamp']);
        $uppercase = Fixture::signed($payload);
        $uppercase['headers']['X-Intelligence-Signature'] = strtoupper($uppercase['headers']['X-Intelligence-Signature']);
        $retimed = Fixture::signed($payload);
        $retimed['headers']['X-Intelligence-Timestamp'] = (string) ($now + 1);

        foreach ([$stale, $future, $wrongSecret, $tampered, $noSignature, $noTimestamp, $uppercase, $retimed] as $request) {
            $this->send($request)->assertUnauthorized()->assertExactJson(['message' => 'Tidak berwenang.', 'code' => 'unauthorized']);
        }
        $this->assertDatabaseEmpty('intelligence_reports');

        $this->send(Fixture::signed($payload, $now - 299))->assertCreated();
    }

    #[TestWith([null], 'secret kosong')]
    #[TestWith(['terlalu-pendek'], 'secret pendek')]
    public function test_missing_or_weak_secret_fails_closed(?string $secret): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        config(['services.hermes_intelligence_callback.secret' => $secret]);
        $payload = Fixture::payload($evaluation, $event);

        $this->send(Fixture::signed($payload, secret: $secret ?? ''))->assertStatus(503);
        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_oversized_body_is_rejected_before_parsing(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $report = IntelligenceReportFactory::sampleReport(['executive_summary' => str_repeat('a', 2 * 1024 * 1024)]);

        $this->send(Fixture::signed(Fixture::payload($evaluation, $event, ['report' => $report])))
            ->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_malformed_ambiguous_and_non_json_bodies_are_rejected(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $json = json_encode(Fixture::payload($evaluation, $event));
        $duplicateIdentity = substr_replace($json, '"evaluation_id":'.($evaluation->id + 1).',', 1, 0);
        $nestedDuplicate = str_replace('"limitations":[]', '"limitations":[],"limitations":["x"]', $json);

        foreach (['{"event_id":', '[]', '"teks"', '', $duplicateIdentity, $nestedDuplicate, '{"a":1}garbage'] as $body) {
            $this->send(Fixture::signed($body))->assertUnprocessable();
        }

        $wrongType = Fixture::signed($json);
        $wrongType['headers']['Content-Type'] = 'text/plain';
        $this->send($wrongType)->assertStatus(415);
        $withCharset = Fixture::signed($json);
        $withCharset['headers']['Content-Type'] = 'application/json; charset=utf-8';
        $this->send($withCharset)->assertCreated();
    }

    public function test_responses_never_echo_the_report_or_secret(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $payload = Fixture::payload($evaluation, $event);
        unset($payload['report']['executive_summary']);

        $body = $this->send(Fixture::signed($payload))->assertUnprocessable()->getContent();

        $this->assertStringNotContainsString(Fixture::SECRET, $body);
        $this->assertStringNotContainsString('Ringkasan enam faktor', $body);
    }

    public function test_callback_route_is_outside_sanctum_and_get_is_not_allowed(): void
    {
        $this->getJson(Fixture::PATH)->assertStatus(405);
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson(Fixture::PATH, [])->assertUnauthorized();
    }

    public function test_database_enforces_uniqueness_and_foreign_keys_and_models_are_immutable(): void
    {
        $report = IntelligenceReport::factory()->create();
        $this->assertDatabaseCount('intelligence_reports', 1);

        try {
            IntelligenceReport::factory()->create(['evaluation_id' => $report->evaluation_id]);
            $this->fail('Satu evaluasi tidak boleh memiliki dua laporan.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Duplicate entry', $exception->getMessage());
        }

        $this->expectIntegrityFailure(fn () => IntelligenceReport::factory()->create(['event_id' => $report->event_id]));
        $this->expectIntegrityFailure(fn () => IntelligenceReport::factory()->create(['event_id' => (string) Str::uuid()]));
        $this->expectIntegrityFailure(fn () => DB::table('webhook_outbox')->where('event_id', $report->event_id)->delete());
        $this->expectIntegrityFailure(fn () => DB::table('umkms')->where('id', $report->umkm_id)->delete());
        $this->expectIntegrityFailure(fn () => DB::table('intelligence_reports')->where('id', $report->id)->update(['methodology_status' => 'bogus']));

        $this->assertThrows(fn () => $report->forceFill(['model' => 'lain'])->save(), LogicException::class);
        $this->assertThrows(fn () => $report->delete(), LogicException::class);
        $this->assertDatabaseCount('intelligence_reports', 1);
    }

    private function expectIntegrityFailure(callable $action): void
    {
        $this->assertThrows($action, QueryException::class);
    }
}
