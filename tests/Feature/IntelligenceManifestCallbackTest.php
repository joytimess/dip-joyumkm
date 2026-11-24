<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceArtifact;
use App\Models\IntelligenceArtifactManifest;
use App\Models\IntelligenceReport;
use App\Services\IntelligenceArtifactContract;
use App\Services\IntelligenceCallbackPayload;
use Database\Factories\IntelligenceReportFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\IntelligenceArtifactFixture as Fixture;
use Tests\Fixtures\IntelligenceCallbackFixture;
use Tests\Fixtures\InteractsWithIntelligenceArtifacts;
use Tests\TestCase;

/** P4-03: callback manifest, pengikatan event→report, rekonsiliasi status, dan QA penerima terintegrasi. */
class IntelligenceManifestCallbackTest extends TestCase
{
    use InteractsWithIntelligenceArtifacts;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpArtifacts();
    }

    protected function tearDown(): void
    {
        IntelligenceArtifactManifest::flushEventListeners();
        $this->tearDownArtifacts();
        parent::tearDown();
    }

    public function test_callback_with_manifest_binds_report_and_persists_expected_manifest_atomically(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $files = Fixture::sample('complete');
        $payload = IntelligenceCallbackFixture::payload($evaluation, $event, ['artifacts' => Fixture::projection($files)]);

        $response = $this->callbackRequest(IntelligenceCallbackFixture::signed($payload));

        $report = IntelligenceReport::query()->sole();
        $manifest = IntelligenceArtifactManifest::query()->sole();
        $response->assertCreated()->assertJson([
            'status' => 'stored', 'event_id' => $event->event_id, 'report_id' => $report->id,
            'report_hash' => $report->report_hash, 'artifact_status' => 'pending',
            'transport_artifact_set_hash' => IntelligenceArtifactContract::setHash(Fixture::projection($files)),
        ]);
        $this->assertSame([$report->id, $event->event_id], [$manifest->report_id, $manifest->event_id]);
        $this->assertSame(Fixture::projection($files), $manifest->entries());
        $this->assertDatabaseEmpty('intelligence_artifacts');

        // Metadata artefak tidak boleh mengubah report_hash semantik.
        $withoutManifest = IntelligenceCallbackPayload::fromJson(json_encode([...$payload, 'artifacts' => null], JSON_THROW_ON_ERROR));
        $this->assertSame($withoutManifest->hash(), $report->report_hash);
    }

    public function test_transport_hash_matches_the_hermes_p3_manifest_for_all_five_packages(): void
    {
        foreach (Fixture::SAMPLES as $name) {
            $files = Fixture::sample($name);
            $hermes = json_decode((string) file_get_contents(base_path("docs/evidence/p3-07/samples/{$name}/manifest.json")), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($hermes['transport_artifact_set_hash'], IntelligenceArtifactContract::setHash(Fixture::projection($files)), $name);
            foreach ($hermes['artifacts'] as $entry) {
                $this->assertSame($entry['sha256'], hash('sha256', $files[$entry['kind']]), $name);
                $this->assertSame($entry['size_bytes'], strlen($files[$entry['kind']]), $name);
            }
        }
    }

    public function test_identical_callback_retry_is_an_idempotent_duplicate(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $request = Fixture::callback($evaluation, $event, Fixture::sample('complete'));

        $first = $this->callbackRequest($request)->assertCreated();
        $this->callbackRequest(IntelligenceCallbackFixture::signed($request['body']))->assertOk()
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('transport_artifact_set_hash', $first->json('transport_artifact_set_hash'));

        $this->assertDatabaseCount('intelligence_reports', 1);
        $this->assertDatabaseCount('intelligence_artifact_manifests', 1);
    }

    public function test_legacy_callback_without_manifest_keeps_existing_behaviour(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();

        $this->callbackRequest(IntelligenceCallbackFixture::signed(IntelligenceCallbackFixture::payload($evaluation, $event)))
            ->assertCreated()->assertJsonPath('status', 'stored')->assertJsonPath('transport_artifact_set_hash', null)
            ->assertJsonPath('artifact_status', 'pending');
        $this->assertDatabaseEmpty('intelligence_artifact_manifests');

        $this->upload($event->event_id, 'pdf', Fixture::sample('complete')['pdf'])
            ->assertStatus(409)->assertJsonPath('code', 'manifest_required');
    }

    public function test_legacy_report_is_not_upgraded_by_a_later_callback_with_manifest(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $this->callbackRequest(IntelligenceCallbackFixture::signed(IntelligenceCallbackFixture::payload($evaluation, $event)))->assertCreated();

        $this->callbackRequest(Fixture::callback($evaluation, $event, Fixture::sample('complete')))
            ->assertOk()->assertJsonPath('status', 'duplicate')->assertJsonPath('transport_artifact_set_hash', null);

        $this->assertDatabaseEmpty('intelligence_artifact_manifests');
    }

    public function test_legacy_client_retry_without_manifest_after_binding_is_a_duplicate_exposing_the_bound_hash(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $first = $this->callbackRequest(Fixture::callback($evaluation, $event, Fixture::sample('complete')))->assertCreated();

        $this->callbackRequest(IntelligenceCallbackFixture::signed(IntelligenceCallbackFixture::payload($evaluation, $event)))
            ->assertOk()->assertJsonPath('transport_artifact_set_hash', $first->json('transport_artifact_set_hash'));
        $this->assertDatabaseCount('intelligence_artifact_manifests', 1);
    }

    public function test_different_manifest_for_the_same_event_is_an_immutable_artifact_conflict(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $original = Fixture::projection(Fixture::sample('complete'));
        $this->callbackRequest(Fixture::callback($evaluation, $event, Fixture::sample('complete')))->assertCreated();

        $this->callbackRequest(Fixture::callback($evaluation, $event, Fixture::sample('decline')))
            ->assertStatus(409)->assertJsonPath('code', 'artifact_conflict');

        $this->assertSame($original, IntelligenceArtifactManifest::query()->sole()->entries());
    }

    public function test_different_report_for_the_same_event_is_a_report_conflict_and_leaves_old_data(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $this->callbackRequest(Fixture::callback($evaluation, $event, Fixture::sample('complete')))->assertCreated();
        $changed = IntelligenceCallbackFixture::payload($evaluation, $event, [
            'artifacts' => Fixture::projection(Fixture::sample('decline')),
            'report' => IntelligenceReportFactory::sampleReport(['executive_summary' => 'Berbeda.']),
        ]);

        $this->callbackRequest(IntelligenceCallbackFixture::signed($changed))->assertStatus(409)->assertJsonPath('code', 'report_conflict');

        $this->assertSame(Fixture::projection(Fixture::sample('complete')), IntelligenceArtifactManifest::query()->sole()->entries());
    }

    /** @return array<string, array{callable(array<int, array<string, mixed>>): mixed}> */
    public static function invalidManifests(): array
    {
        $ok = fn (): array => Fixture::projection(Fixture::sample('complete'));

        return [
            'missing xlsx' => [fn () => [$ok()[0]]],
            'missing pdf' => [fn () => [$ok()[1]]],
            'duplicate kind' => [fn () => [$ok()[0], $ok()[0]]],
            'empty list' => [fn () => []],
            'not a list' => [fn () => ['pdf' => $ok()[0]]],
            'unknown kind' => [fn () => [[...$ok()[0], 'kind' => 'docx'], $ok()[1]]],
            'invalid mime' => [fn () => [[...$ok()[0], 'mime' => 'text/plain'], $ok()[1]]],
            'uppercase sha' => [fn () => [[...$ok()[0], 'sha256' => strtoupper($ok()[0]['sha256'])], $ok()[1]]],
            'short sha' => [fn () => [[...$ok()[0], 'sha256' => 'abc'], $ok()[1]]],
            'oversized artifact' => [fn () => [[...$ok()[0], 'size_bytes' => IntelligenceArtifactContract::MAX_BYTES + 1], $ok()[1]]],
            'zero size' => [fn () => [[...$ok()[0], 'size_bytes' => 0], $ok()[1]]],
            'string size' => [fn () => [[...$ok()[0], 'size_bytes' => '100'], $ok()[1]]],
        ];
    }

    /** @param callable(): mixed $artifacts */
    #[DataProvider('invalidManifests')]
    public function test_invalid_manifest_is_rejected_and_nothing_is_persisted(callable $artifacts): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();

        $this->callbackRequest(IntelligenceCallbackFixture::signed(IntelligenceCallbackFixture::payload($evaluation, $event, ['artifacts' => $artifacts()])))
            ->assertUnprocessable()->assertJsonValidationErrors('artifacts');

        $this->assertDatabaseEmpty('intelligence_reports');
        $this->assertDatabaseEmpty('intelligence_artifact_manifests');
    }

    public function test_unknown_event_wrong_umkm_and_wrong_evaluation_are_rejected_without_side_effects(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        ['evaluation' => $otherEvaluation] = Fixture::finalization();
        $files = Fixture::sample('complete');

        $unknown = IntelligenceCallbackFixture::payload($evaluation, $event, ['event_id' => (string) Str::uuid(), 'artifacts' => Fixture::projection($files)]);
        $this->callbackRequest(IntelligenceCallbackFixture::signed($unknown))->assertNotFound();

        $wrongUmkm = IntelligenceCallbackFixture::payload($evaluation, $event, ['umkm_id' => $otherEvaluation->umkm_id, 'artifacts' => Fixture::projection($files)]);
        $this->callbackRequest(IntelligenceCallbackFixture::signed($wrongUmkm))->assertUnprocessable();

        $wrongEvaluation = IntelligenceCallbackFixture::payload($evaluation, $event, ['evaluation_id' => $otherEvaluation->id, 'artifacts' => Fixture::projection($files)]);
        $this->callbackRequest(IntelligenceCallbackFixture::signed($wrongEvaluation))->assertUnprocessable();

        $this->assertDatabaseEmpty('intelligence_reports');
        $this->assertDatabaseEmpty('intelligence_artifact_manifests');
        $this->assertDatabaseCount('webhook_outbox', 2);
    }

    public function test_manifest_failure_rolls_back_the_report_so_no_partial_binding_is_committed(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        IntelligenceArtifactManifest::creating(fn () => throw new RuntimeException('simulasi crash sebelum commit'));

        $this->callbackRequest(Fixture::callback($evaluation, $event, Fixture::sample('complete')))->assertStatus(500);
        IntelligenceArtifactManifest::flushEventListeners();

        $this->assertDatabaseEmpty('intelligence_reports');
        $this->assertDatabaseEmpty('intelligence_artifact_manifests');
        $this->callbackRequest(Fixture::callback($evaluation, $event, Fixture::sample('complete')))->assertCreated();
    }

    public function test_cross_event_artifact_substitution_is_rejected(): void
    {
        $a = $this->boundEvent(null, 'complete');
        $b = $this->boundEvent(null, 'decline');

        $this->upload($b['event_id'], 'pdf', $a['files']['pdf'])->assertStatus(422)->assertJsonPath('code', 'manifest_mismatch');
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    /** @return array<string, array{string, list<string>}> */
    public static function packages(): array
    {
        $orders = [['pdf', 'xlsx'], ['xlsx', 'pdf']];

        return array_combine(
            Fixture::SAMPLES,
            array_map(fn (string $name, int $index): array => [$name, $orders[$index % 2]], Fixture::SAMPLES, array_keys(Fixture::SAMPLES)),
        );
    }

    /** @param list<string> $order */
    #[DataProvider('packages')]
    public function test_synthetic_package_flows_pending_partial_complete_with_byte_identical_artifacts(string $name, array $order): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $files = Fixture::sample($name);
        $hermes = json_decode((string) file_get_contents(base_path("docs/evidence/p3-07/samples/{$name}/manifest.json")), true, flags: JSON_THROW_ON_ERROR);

        $callback = $this->callbackRequest(Fixture::callback($evaluation, $event, $files))->assertCreated()->assertJsonPath('artifact_status', 'pending');
        $this->assertSame($hermes['transport_artifact_set_hash'], $callback->json('transport_artifact_set_hash'));
        $report = IntelligenceReport::query()->sole();

        $this->upload($event->event_id, $order[0], $files[$order[0]])->assertCreated()->assertJsonPath('artifact_status', 'partial');
        $this->assertSame('partial', $report->fresh()->artifact_status);
        $this->upload($event->event_id, $order[1], $files[$order[1]])->assertCreated()->assertJsonPath('artifact_status', 'complete');
        $this->assertSame('complete', $report->fresh()->artifact_status);

        foreach ($hermes['artifacts'] as $entry) {
            $row = IntelligenceArtifact::query()->where('kind', $entry['kind'])->sole();
            $stored = (string) file_get_contents($this->artifactRoot.'/'.$row->storage_path);
            $this->assertSame($entry['sha256'], hash('sha256', $stored), $name.' '.$entry['kind']);
            $this->assertSame($entry['size_bytes'], strlen($stored));
            $this->assertSame($files[$entry['kind']], $stored, 'Harus byte-identik dengan artefak Hermes.');
        }

        // Duplikat callback/PDF/XLSX: tidak ada baris atau file baru, status tidak turun.
        $this->callbackRequest(Fixture::callback($evaluation, $event, $files))->assertOk()->assertJsonPath('artifact_status', 'complete');
        foreach (['pdf', 'xlsx'] as $kind) {
            $this->upload($event->event_id, $kind, $files[$kind])->assertOk()->assertJsonPath('result', 'duplicate')->assertJsonPath('artifact_status', 'complete');
        }
        $this->assertDatabaseCount('intelligence_artifacts', 2);
        $this->assertDatabaseCount('intelligence_artifact_manifests', 1);
        $this->assertCount(2, $this->storedFiles());
    }

    public function test_callback_retry_after_one_artifact_keeps_partial_status(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $files = Fixture::sample('complete');
        $this->callbackRequest(Fixture::callback($evaluation, $event, $files))->assertCreated();
        $this->upload($event->event_id, 'xlsx', $files['xlsx'])->assertCreated();

        $this->callbackRequest(Fixture::callback($evaluation, $event, $files))->assertOk()->assertJsonPath('artifact_status', 'partial');
    }

    public function test_complete_status_cannot_regress_and_database_rejects_manual_downgrade(): void
    {
        $bound = $this->boundEvent();
        foreach (['pdf', 'xlsx'] as $kind) {
            $this->upload($bound['event_id'], $kind, $bound['files'][$kind])->assertCreated();
        }

        $this->expectException(QueryException::class);
        DB::table('intelligence_reports')->where('id', $bound['report']->id)->update(['artifact_status' => 'partial']);
    }

    public function test_manifest_rows_are_immutable_in_database(): void
    {
        $this->boundEvent();

        try {
            DB::table('intelligence_artifact_manifests')->update(['transport_artifact_set_hash' => str_repeat('a', 64)]);
            $this->fail('Manifest harus immutable.');
        } catch (QueryException) {
            $this->assertDatabaseCount('intelligence_artifact_manifests', 1);
        }
        $this->expectException(QueryException::class);
        DB::table('intelligence_artifact_manifests')->delete();
    }
}
