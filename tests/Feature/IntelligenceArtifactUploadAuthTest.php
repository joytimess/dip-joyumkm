<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\IntelligenceArtifactContract;
use App\Services\IntelligenceArtifactSignature;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\IntelligenceArtifactFixture as Fixture;
use Tests\Fixtures\InteractsWithIntelligenceArtifacts;
use Tests\TestCase;

/** P4-01: autentikasi HMAC, parsing request, dan kontrak rute unggah artefak. */
class IntelligenceArtifactUploadAuthTest extends TestCase
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
        $this->tearDownArtifacts();
        parent::tearDown();
    }

    public function test_valid_signed_request_is_stored_with_full_contract_response(): void
    {
        $bound = $this->boundEvent();

        $response = $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf']);

        $response->assertCreated()->assertJson([
            'result' => 'stored', 'event_id' => $bound['event_id'], 'report_id' => $bound['report']->id, 'kind' => 'pdf',
            'sha256' => hash('sha256', $bound['files']['pdf']), 'size_bytes' => strlen($bound['files']['pdf']),
            'artifact_status' => 'partial',
        ]);
        $this->assertStringNotContainsString($this->artifactRoot, $response->getContent());
    }

    public function test_missing_authentication_headers_are_rejected_without_database_or_file_effects(): void
    {
        $bound = $this->boundEvent();
        $body = $bound['files']['pdf'];

        $response = $this->call('PUT', Fixture::path($bound['event_id'], 'pdf'), [], [], [], [
            'CONTENT_TYPE' => 'application/pdf', 'CONTENT_LENGTH' => (string) strlen($body), 'HTTP_ACCEPT' => 'application/json',
        ], $body);

        $response->assertUnauthorized()->assertJsonPath('code', 'missing_authentication');
        $this->assertDatabaseEmpty('intelligence_artifacts');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $bound = $this->boundEvent();
        $server = Fixture::server($bound['event_id'], 'pdf', $bound['files']['pdf']);
        $server['HTTP_X_INTELLIGENCE_SIGNATURE'] = str_repeat('0', 64);

        $this->call('PUT', Fixture::path($bound['event_id'], 'pdf'), [], [], [], $server, $bound['files']['pdf'])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_signature');
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $bound = $this->boundEvent();
        $server = Fixture::server($bound['event_id'], 'pdf', $bound['files']['pdf'], null, str_repeat('x', 40));

        $this->call('PUT', Fixture::path($bound['event_id'], 'pdf'), [], [], [], $server, $bound['files']['pdf'])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_signature');
    }

    public function test_expired_and_future_timestamps_are_rejected(): void
    {
        // Waktu dibekukan agar selisih tepat 301 detik tidak bergeser melewati batas detik saat middleware membaca waktu.
        $this->freezeTime();
        $bound = $this->boundEvent();

        $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf'], now()->getTimestamp() - 301)
            ->assertUnauthorized()->assertJsonPath('code', 'expired_signature');
        $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf'], now()->getTimestamp() + 301)
            ->assertUnauthorized()->assertJsonPath('code', 'expired_signature');
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    public function test_missing_or_short_secret_fails_closed(): void
    {
        $bound = $this->boundEvent();
        foreach ([null, '', 'short-secret'] as $secret) {
            config(['services.hermes_intelligence_artifact.secret' => $secret]);
            $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf'])
                ->assertStatus(503)->assertJsonPath('code', 'artifact_receiver_unavailable');
        }
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    public function test_malformed_event_id_and_kind_never_reach_the_receiver(): void
    {
        $bound = $this->boundEvent();
        $body = $bound['files']['pdf'];

        $this->call('PUT', '/api/internal/intelligence/artifacts/not-a-uuid/pdf', [], [], [], Fixture::server('not-a-uuid', 'pdf', $body), $body)->assertNotFound();
        $this->call('PUT', '/api/internal/intelligence/artifacts/'.$bound['event_id'].'/exe', [], [], [], Fixture::server($bound['event_id'], 'pdf', $body), $body)->assertNotFound();
        $this->call('PUT', '/api/internal/intelligence/artifacts/'.strtoupper($bound['event_id']).'/pdf', [], [], [], Fixture::server($bound['event_id'], 'pdf', $body), $body)->assertNotFound();
    }

    public function test_method_tampering_is_rejected(): void
    {
        $bound = $this->boundEvent();
        $body = $bound['files']['pdf'];
        $server = Fixture::server($bound['event_id'], 'pdf', $body);

        $this->call('POST', Fixture::path($bound['event_id'], 'pdf'), [], [], [], $server, $body)->assertStatus(405);
        $this->call('DELETE', Fixture::path($bound['event_id'], 'pdf'), [], [], [], $server, $body)->assertStatus(405);
    }

    public function test_path_event_and_kind_are_bound_to_the_signature(): void
    {
        $bound = $this->boundEvent();
        $other = $this->boundEvent();
        $pdf = $bound['files']['pdf'];

        // Tanda tangan event A dipakai pada event B.
        $this->call('PUT', Fixture::path($other['event_id'], 'pdf'), [], [], [], Fixture::server($bound['event_id'], 'pdf', $pdf), $pdf)
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_signature');
        // Tanda tangan kind pdf dipakai pada kind xlsx (dengan content type disesuaikan).
        $server = [...Fixture::server($bound['event_id'], 'pdf', $pdf), 'CONTENT_TYPE' => IntelligenceArtifactContract::KINDS['xlsx']];
        $this->call('PUT', Fixture::path($bound['event_id'], 'xlsx'), [], [], [], $server, $pdf)
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_signature');
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    public function test_mime_and_content_length_tampering_are_rejected(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        $path = Fixture::path($bound['event_id'], 'pdf');

        $this->call('PUT', $path, [], [], [], [...Fixture::server($bound['event_id'], 'pdf', $pdf), 'CONTENT_TYPE' => 'application/octet-stream'], $pdf)
            ->assertUnauthorized();
        $this->call('PUT', $path, [], [], [], [...Fixture::server($bound['event_id'], 'pdf', $pdf), 'CONTENT_LENGTH' => (string) (strlen($pdf) + 1)], $pdf)
            ->assertUnauthorized();
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    public function test_signed_unsupported_media_type_and_content_encoding_are_rejected(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        $path = Fixture::path($bound['event_id'], 'pdf');

        $canonical = IntelligenceArtifactSignature::canonical('PUT', $path, now()->getTimestamp(), $bound['event_id'], 'pdf', 'application/octet-stream', strlen($pdf), hash('sha256', $pdf));
        $server = [
            ...Fixture::server($bound['event_id'], 'pdf', $pdf),
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_INTELLIGENCE_SIGNATURE' => IntelligenceArtifactSignature::sign(Fixture::SECRET, $canonical),
        ];
        $this->call('PUT', $path, [], [], [], $server, $pdf)->assertStatus(415)->assertJsonPath('code', 'unsupported_media_type');

        $this->call('PUT', $path, [], [], [], [...Fixture::server($bound['event_id'], 'pdf', $pdf), 'HTTP_CONTENT_ENCODING' => 'gzip'], $pdf)
            ->assertStatus(415);
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    public function test_signed_checksum_that_differs_from_the_body_is_rejected_and_nothing_is_stored(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        $path = Fixture::path($bound['event_id'], 'pdf');

        // Header digest diubah tanpa menandatangani ulang.
        $server = Fixture::server($bound['event_id'], 'pdf', $pdf);
        $server['HTTP_X_INTELLIGENCE_CONTENT_SHA256'] = hash('sha256', 'lain');
        $this->call('PUT', $path, [], [], [], $server, $pdf)->assertUnauthorized();

        // Digest valid untuk byte lain, tetapi body yang dikirim berbeda (body tampering) dengan panjang sama.
        $tampered = substr_replace($pdf, 'X', 100, 1);
        $this->call('PUT', $path, [], [], [], Fixture::server($bound['event_id'], 'pdf', $pdf), $tampered)
            ->assertStatus(422)->assertJsonPath('code', 'checksum_mismatch');
        $this->assertDatabaseEmpty('intelligence_artifacts');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_declared_length_larger_than_limit_is_413_and_actual_oversize_body_is_bounded(): void
    {
        $bound = $this->boundEvent();
        $path = Fixture::path($bound['event_id'], 'pdf');

        $big = str_repeat('A', IntelligenceArtifactContract::MAX_BYTES + 1);
        $this->call('PUT', $path, [], [], [], Fixture::server($bound['event_id'], 'pdf', $big), $big)
            ->assertStatus(413)->assertJsonPath('code', 'artifact_too_large');

        // Content-Length ditandatangani kecil, body sebenarnya lebih besar.
        $pdf = $bound['files']['pdf'];
        $this->call('PUT', $path, [], [], [], Fixture::server($bound['event_id'], 'pdf', $pdf), $pdf.'tambahan')
            ->assertStatus(422)->assertJsonPath('code', 'size_mismatch');
        // Body lebih pendek daripada yang dideklarasikan.
        $this->call('PUT', $path, [], [], [], Fixture::server($bound['event_id'], 'pdf', $pdf), substr($pdf, 0, -10))
            ->assertStatus(422)->assertJsonPath('code', 'size_mismatch');
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    public function test_empty_body_is_rejected(): void
    {
        $bound = $this->boundEvent();

        $this->upload($bound['event_id'], 'pdf', '')->assertStatus(422)->assertJsonPath('code', 'invalid_artifact');
    }

    public function test_exact_replay_and_fresh_signature_retry_are_idempotent_duplicates(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        $timestamp = now()->getTimestamp();

        $this->upload($bound['event_id'], 'pdf', $pdf, $timestamp)->assertCreated();
        $this->upload($bound['event_id'], 'pdf', $pdf, $timestamp)->assertOk()->assertJsonPath('result', 'duplicate');
        $this->upload($bound['event_id'], 'pdf', $pdf, $timestamp + 5)->assertOk()->assertJsonPath('result', 'duplicate');

        $this->assertDatabaseCount('intelligence_artifacts', 1);
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_unknown_event_and_upload_before_callback_are_rejected_without_creating_reports(): void
    {
        $unknown = (string) Str::uuid();
        $pdf = Fixture::sample('complete')['pdf'];
        $this->upload($unknown, 'pdf', $pdf)->assertNotFound()->assertJsonPath('code', 'event_not_found');

        ['event' => $event] = Fixture::finalization();
        $this->upload($event->event_id, 'pdf', $pdf)->assertNotFound()->assertJsonPath('code', 'report_not_found');
        $this->assertDatabaseEmpty('intelligence_reports');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_artifacts_are_not_reachable_through_public_paths(): void
    {
        $bound = $this->boundEvent();
        $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf'])->assertCreated();

        $this->assertFalse(str_starts_with(realpath($this->artifactRoot), public_path()));
        $this->assertContains($this->get('/storage/'.$bound['event_id'].'/'.hash('sha256', $bound['files']['pdf']).'.pdf')->getStatusCode(), [403, 404]);
        $this->assertFalse(config('filesystems.disks.intelligence.serve'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.intelligence'));
    }

    public function test_golden_hmac_vectors_are_reproducible(): void
    {
        $vectors = json_decode((string) file_get_contents(base_path('docs/evidence/p4-01-hmac-vectors.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertGreaterThanOrEqual(5, count($vectors['vectors']));
        foreach ($vectors['vectors'] as $vector) {
            $canonical = IntelligenceArtifactSignature::canonical(
                $vector['method'], $vector['path'], $vector['timestamp'], $vector['event_id'], $vector['kind'],
                $vector['content_type'], $vector['content_length'], hash('sha256', hex2bin($vector['body_hex'])),
            );
            $this->assertSame($vector['canonical'], $canonical, $vector['name']);
            $this->assertSame($vector['signature'], IntelligenceArtifactSignature::sign($vectors['test_only_secret'], $canonical), $vector['name']);
        }
    }
}
