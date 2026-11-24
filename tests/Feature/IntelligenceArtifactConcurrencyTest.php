<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceArtifact;
use App\Models\IntelligenceReport;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Fixtures\IntelligenceArtifactFixture as Fixture;
use Tests\Fixtures\IntelligenceCallbackFixture;
use Tests\Fixtures\InteractsWithIntelligenceArtifacts;
use Tests\TestCase;

/**
 * Konkurensi nyata pada MySQL: beberapa proses PHP terpisah dilepas bersamaan lewat penghalang waktu
 * terhadap kernel HTTP Laravel. SQLite tidak cukup untuk membuktikan perilaku kunci dan constraint ini.
 */
class IntelligenceArtifactConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithIntelligenceArtifacts;

    private const TABLES = [
        'intelligence_artifacts', 'intelligence_artifact_manifests', 'intelligence_reports', 'webhook_outbox',
        'six_factor_evaluations', 'umkms', 'personal_access_tokens', 'users',
    ];

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('joyumkm_testing', DB::scalar('SELECT DATABASE()'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpArtifacts();
    }

    protected function tearDown(): void
    {
        try {
            Schema::disableForeignKeyConstraints();
            foreach (self::TABLES as $table) {
                DB::table($table)->truncate();
            }
            Schema::enableForeignKeyConstraints();
            $this->tearDownArtifacts();
        } finally {
            parent::tearDown();
        }
    }

    public function test_parallel_identical_uploads_register_exactly_one_artifact(): void
    {
        $bound = $this->boundEvent();
        $request = $this->putSpec($bound['event_id'], 'pdf', $bound['files']['pdf']);

        $results = $this->race(array_fill(0, 6, $request));

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 200, 200, 200, 200, 201], $statuses, json_encode($results));
        $this->assertDatabaseCount('intelligence_artifacts', 1);
        $this->assertCount(1, $this->storedFiles());
        $this->assertSame($bound['files']['pdf'], file_get_contents($this->artifactRoot.'/'.IntelligenceArtifact::query()->sole()->storage_path));
    }

    public function test_parallel_pdf_and_xlsx_uploads_end_complete_without_lost_update(): void
    {
        $bound = $this->boundEvent();
        $requests = [];
        foreach (range(1, 3) as $_) {
            $requests[] = $this->putSpec($bound['event_id'], 'pdf', $bound['files']['pdf']);
            $requests[] = $this->putSpec($bound['event_id'], 'xlsx', $bound['files']['xlsx']);
        }

        $results = $this->race($requests);

        $this->assertCount(2, array_filter($results, fn (array $r): bool => $r['status'] === 201), json_encode($results));
        $this->assertCount(4, array_filter($results, fn (array $r): bool => $r['status'] === 200), json_encode($results));
        $this->assertDatabaseCount('intelligence_artifacts', 2);
        $this->assertSame('complete', $bound['report']->fresh()->artifact_status);
        $this->assertCount(2, $this->storedFiles());
    }

    public function test_parallel_conflicting_checksums_never_overwrite_or_duplicate(): void
    {
        $bound = $this->boundEvent();
        $other = Fixture::sample('decline')['pdf'];
        $requests = [];
        foreach (range(1, 3) as $_) {
            $requests[] = $this->putSpec($bound['event_id'], 'pdf', $bound['files']['pdf']);
            $requests[] = $this->putSpec($bound['event_id'], 'pdf', $other);
        }

        $results = $this->race($requests);

        $this->assertCount(1, array_filter($results, fn (array $r): bool => $r['status'] === 201), json_encode($results));
        foreach ($results as $index => $result) {
            $this->assertContains($result['status'], $index % 2 === 0 ? [200, 201] : [409, 422], json_encode($results));
        }
        $row = IntelligenceArtifact::query()->sole();
        $this->assertSame(hash('sha256', $bound['files']['pdf']), $row->sha256);
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_parallel_uploads_of_identical_bytes_for_different_events_stay_isolated(): void
    {
        $files = Fixture::sample('complete');
        $a = $this->boundEvent($files);
        $b = $this->boundEvent($files);

        $results = $this->race([
            $this->putSpec($a['event_id'], 'pdf', $files['pdf']),
            $this->putSpec($b['event_id'], 'pdf', $files['pdf']),
            $this->putSpec($a['event_id'], 'xlsx', $files['xlsx']),
            $this->putSpec($b['event_id'], 'xlsx', $files['xlsx']),
        ]);

        $this->assertSame([201, 201, 201, 201], array_column($results, 'status'), json_encode($results));
        $this->assertDatabaseCount('intelligence_artifacts', 4);
        $this->assertSame(['complete', 'complete'], [$a['report']->fresh()->artifact_status, $b['report']->fresh()->artifact_status]);
        $this->assertSame(4, IntelligenceArtifact::query()->distinct()->count('storage_path'));
        foreach (IntelligenceArtifact::query()->get() as $row) {
            $this->assertSame($row->event_id, explode('/', $row->storage_path)[0]);
        }
    }

    public function test_uploads_racing_with_callback_retries_keep_a_single_report_and_manifest(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $files = Fixture::sample('complete');
        $callback = Fixture::callback($evaluation, $event, $files);
        $this->callbackRequest($callback)->assertCreated();

        $callbackSpec = [
            'method' => 'POST', 'uri' => IntelligenceCallbackFixture::PATH, 'body' => $callback['body'],
            'server' => IntelligenceCallbackFixture::server($callback['headers']),
        ];
        $results = $this->race([
            $callbackSpec,
            $this->putSpec($event->event_id, 'pdf', $files['pdf']),
            $callbackSpec,
            $this->putSpec($event->event_id, 'xlsx', $files['xlsx']),
            $callbackSpec,
        ]);

        foreach ([0, 2, 4] as $index) {
            $this->assertSame(200, $results[$index]['status'], json_encode($results));
        }
        $this->assertSame([201, 201], [$results[1]['status'], $results[3]['status']], json_encode($results));
        $this->assertDatabaseCount('intelligence_reports', 1);
        $this->assertDatabaseCount('intelligence_artifact_manifests', 1);
        $this->assertDatabaseCount('intelligence_artifacts', 2);
        $this->assertSame('complete', IntelligenceReport::query()->sole()->artifact_status);
    }

    public function test_parallel_identical_callbacks_with_manifest_store_one_report_and_one_manifest(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $callback = Fixture::callback($evaluation, $event, Fixture::sample('complete'));
        $spec = [
            'method' => 'POST', 'uri' => IntelligenceCallbackFixture::PATH, 'body' => $callback['body'],
            'server' => IntelligenceCallbackFixture::server($callback['headers']),
        ];

        $results = $this->race(array_fill(0, 6, $spec));

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 200, 200, 200, 200, 201], $statuses, json_encode($results));
        $this->assertDatabaseCount('intelligence_reports', 1);
        $this->assertDatabaseCount('intelligence_artifact_manifests', 1);
        $this->assertCount(1, array_unique(array_column(array_column($results, 'body'), 'transport_artifact_set_hash')));
    }

    /** @return array{method: string, uri: string, body: string, server: array<string, string>} */
    private function putSpec(string $eventId, string $kind, string $body): array
    {
        return [
            'method' => 'PUT', 'uri' => Fixture::path($eventId, $kind), 'body' => $body,
            'server' => [...Fixture::server($eventId, $kind, $body), 'HTTP_ACCEPT' => 'application/json'],
        ];
    }

    /**
     * @param  list<array{method: string, uri: string, body: string, server: array<string, string>}>  $specs
     * @return list<array{status: int, body: array<string, mixed>}>
     */
    private function race(array $specs): array
    {
        $barrier = microtime(true) + 5.0;
        $files = [];
        $processes = [];
        foreach ($specs as $spec) {
            $file = tempnam(sys_get_temp_dir(), 'jmi-req-');
            file_put_contents($file, json_encode(['body' => base64_encode($spec['body'])] + $spec, JSON_THROW_ON_ERROR));
            $files[] = $file;
            $processes[] = $this->startWorker($file, $barrier);
        }

        $results = [];
        try {
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            array_map('unlink', $files);
        }

        return $results;
    }

    private function startWorker(string $requestFile, float $barrier): Process
    {
        $process = new Process([PHP_BINARY], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'joyumkm_testing',
            'DB_URL' => '',
            'HERMES_WEBHOOK_ENABLED' => 'false',
            'HERMES_INTELLIGENCE_CALLBACK_SECRET' => IntelligenceCallbackFixture::SECRET,
            'HERMES_INTELLIGENCE_ARTIFACT_SECRET' => Fixture::SECRET,
            'INTELLIGENCE_ARTIFACT_ROOT' => $this->artifactRoot,
            'CACHE_STORE' => 'array',
            'JOYUMKM_TEST_REQUEST' => $requestFile,
            'JOYUMKM_TEST_BARRIER' => (string) $barrier,
        ], timeout: 60);
        $process->setInput(<<<'PHP'
            <?php
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $db = $app['db']->connection();
            if ($db->getDriverName() !== 'mysql' || $db->scalar('SELECT DATABASE()') !== 'joyumkm_testing') {
                throw new RuntimeException('Koneksi pekerja di luar database uji.');
            }
            $db->statement('SET SESSION innodb_lock_wait_timeout = 30');
            $spec = json_decode(file_get_contents(getenv('JOYUMKM_TEST_REQUEST')), true, flags: JSON_THROW_ON_ERROR);
            $request = Illuminate\Http\Request::create($spec['uri'], $spec['method'], server: $spec['server'], content: base64_decode($spec['body']));
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            while (microtime(true) < (float) getenv('JOYUMKM_TEST_BARRIER')) {
                usleep(500);
            }
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            echo json_encode([
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true) ?? [],
            ], JSON_THROW_ON_ERROR);
            PHP);
        $process->start();

        return $process;
    }
}
