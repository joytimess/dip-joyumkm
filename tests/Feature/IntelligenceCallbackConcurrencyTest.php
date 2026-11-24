<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceReport;
use Database\Factories\IntelligenceReportFactory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Fixtures\IntelligenceCallbackFixture as Fixture;
use Tests\TestCase;

/**
 * Callback paralel dari beberapa proses PHP terpisah yang dilepas bersamaan lewat penghalang waktu.
 */
class IntelligenceCallbackConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('joyumkm_testing', DB::scalar('SELECT DATABASE()'));
    }

    protected function tearDown(): void
    {
        try {
            Schema::disableForeignKeyConstraints();
            foreach (['intelligence_reports', 'webhook_outbox', 'six_factor_evaluations', 'umkms', 'personal_access_tokens', 'users'] as $table) {
                DB::table($table)->truncate();
            }
            Schema::enableForeignKeyConstraints();
        } finally {
            parent::tearDown();
        }
    }

    public function test_parallel_identical_callbacks_store_exactly_one_report(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $request = Fixture::signed(Fixture::payload($evaluation, $event));

        $results = $this->race(array_fill(0, 6, $request));

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 200, 200, 200, 200, 201], $statuses, json_encode($results));
        $this->assertDatabaseCount('intelligence_reports', 1);
        $this->assertSame(1, count(array_unique(array_column(array_column($results, 'body'), 'report_id'))));
    }

    public function test_parallel_conflicting_callbacks_never_duplicate_or_overwrite(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = Fixture::finalization();
        $variants = [];
        foreach (['Isi A.', 'Isi B.'] as $summary) {
            $variants[$summary] = Fixture::signed(Fixture::payload($evaluation, $event, [
                'report' => IntelligenceReportFactory::sampleReport(['executive_summary' => $summary]),
            ]));
        }
        $requests = [$variants['Isi A.'], $variants['Isi B.'], $variants['Isi A.'], $variants['Isi B.'], $variants['Isi A.'], $variants['Isi B.']];

        $results = $this->race($requests);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['status'] === 201), json_encode($results));
        $this->assertDatabaseCount('intelligence_reports', 1);
        $winner = IntelligenceReport::query()->sole()->document()->executive_summary;
        foreach ($results as $index => $result) {
            $variant = $index % 2 === 0 ? 'Isi A.' : 'Isi B.';
            if ($variant === $winner) {
                $this->assertContains($result['status'], [200, 201], json_encode($results));
            } else {
                $this->assertSame(409, $result['status'], json_encode($results));
                $this->assertSame('report_conflict', $result['body']['code']);
            }
        }
        $this->assertSame($winner, IntelligenceReport::query()->sole()->document()->executive_summary);
    }

    /**
     * @param  list<array{headers: array<string, string>, body: string}>  $requests
     * @return list<array{status: int, body: array<string, mixed>}>
     */
    private function race(array $requests): array
    {
        $barrier = microtime(true) + 4.0;
        $processes = array_map(fn (array $request): Process => $this->startWorker($request, $barrier), $requests);
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
        }

        return $results;
    }

    /** @param array{headers: array<string, string>, body: string} $request */
    private function startWorker(array $request, float $barrier): Process
    {
        $process = new Process([PHP_BINARY], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'joyumkm_testing',
            'DB_URL' => '',
            'HERMES_WEBHOOK_ENABLED' => 'false',
            'HERMES_INTELLIGENCE_CALLBACK_SECRET' => Fixture::SECRET,
            'JOYUMKM_TEST_HEADERS' => json_encode($request['headers'], JSON_THROW_ON_ERROR),
            'JOYUMKM_TEST_BODY' => $request['body'],
            'JOYUMKM_TEST_BARRIER' => (string) $barrier,
        ], timeout: 30);
        $process->setInput(<<<'PHP'
            <?php
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $db = $app['db']->connection();
            if ($db->getDriverName() !== 'mysql' || $db->scalar('SELECT DATABASE()') !== 'joyumkm_testing') {
                throw new RuntimeException('Koneksi pekerja di luar database uji.');
            }
            $db->statement('SET SESSION innodb_lock_wait_timeout = 20');
            $server = [];
            foreach (json_decode(getenv('JOYUMKM_TEST_HEADERS'), true) as $name => $value) {
                $key = strtoupper(str_replace('-', '_', $name));
                $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
            }
            $request = Illuminate\Http\Request::create(
                '/api/internal/intelligence/callback', 'POST', server: $server, content: getenv('JOYUMKM_TEST_BODY'),
            );
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            while (microtime(true) < (float) getenv('JOYUMKM_TEST_BARRIER')) {
                usleep(500);
            }
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            echo json_encode([
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR);
            PHP);
        $process->start();

        return $process;
    }
}
