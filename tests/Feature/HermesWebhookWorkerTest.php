<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverHermesWebhook;
use App\Models\SixFactorEvaluation;
use App\Models\WebhookOutbox;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\HermesWebhookReceiver;
use Tests\TestCase;

class HermesWebhookWorkerTest extends TestCase
{
    use DatabaseTruncation;

    private ?SixFactorEvaluation $draft = null;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('joyumkm_testing', DB::scalar('SELECT DATABASE()'));
    }

    protected function tearDown(): void
    {
        try {
            DB::table('webhook_outbox')->delete();
            DB::table('jobs')->delete();
            if ($this->draft !== null) {
                $user = $this->draft->umkm->user;
                $this->draft->umkm->sixFactorEvaluations()->delete();
                $user->tokens()->delete();
                $user->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_parallel_database_workers_and_scanners_recover_a_crashed_delivery_through_http_stub(): void
    {
        $draft = $this->draft = SixFactorEvaluation::factory()->create(array_fill_keys(SixFactorEvaluation::FACTORS, 4));
        $this->actingAs($draft->umkm->user, 'sanctum');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $host = stream_socket_get_name($socket, false);
        fclose($socket);
        $gate = tempnam(sys_get_temp_dir(), 'joyumkm-hermes-');
        $this->assertIsString($gate);
        $server = new Process([
            PHP_BINARY, '-S', $host, '-t', 'public', 'tests/Fixtures/HermesWebhookReceiver.php',
        ], base_path(), ['JOYUMKM_HERMES_STUB_GATE' => $gate], timeout: null);
        $processes = [$server];
        $url = 'http://'.$host.'/joyumkm-events';
        try {
            $server->start();
            $this->waitFor($server, fn (): bool => str_contains($server->getErrorOutput(), 'started'));
            config(['services.hermes_webhook' => [
                'enabled' => true, 'url' => $url, 'allowed_host' => '127.0.0.1', 'secret' => HermesWebhookReceiver::SECRET,
            ]]);

            $this->postJson('/api/umkms/'.$draft->umkm_id.'/six-factor-evaluations/'.$draft->id.'/finalize', options: JSON_FORCE_OBJECT)
                ->assertOk()->assertJsonPath('data.status', 'final');
            $event = WebhookOutbox::query()->sole();
            $raw = $event->getRawOriginal('payload');
            $this->assertDatabaseCount('jobs', 1);
            $this->assertSame('pending', $event->status);
            app('queue')->connection('database')->push(new DeliverHermesWebhook($event->id), queue: DeliverHermesWebhook::QUEUE);

            $processes[] = $first = $this->startChild($url);
            $this->waitFor($first, fn (): bool => file_get_contents($gate) === 'accepted');
            $event->refresh();
            $this->assertSame('processing', $event->status);
            $this->assertSame(1, $event->attempts);
            $token = $event->lease_token;

            $processes[] = $duplicate = $this->startChild($url);
            $this->assertSame(0, $duplicate->wait(), $duplicate->getErrorOutput());
            $this->assertTrue($first->isRunning());
            $this->assertSame($token, $event->refresh()->lease_token);
            $this->assertSame(1, $event->attempts);

            $first->stop(0);
            touch($gate.'.release');
            $this->assertSame('processing', $event->refresh()->status);
            DB::table('webhook_outbox')->where('id', $event->id)->update(['lease_expires_at' => now()->subSecond()]);
            $processes[] = $scanner = $this->startChild($url, scan: true);
            $processes[] = $parallelScanner = $this->startChild($url, scan: true);
            $this->assertSame(0, $scanner->wait(), $scanner->getErrorOutput());
            $this->assertSame(0, $parallelScanner->wait(), $parallelScanner->getErrorOutput());
            $this->assertSame(1, DB::table('jobs')->where('queue', DeliverHermesWebhook::QUEUE)->whereNull('reserved_at')->count());

            $processes[] = $retry = $this->startChild($url);
            $this->assertSame(0, $retry->wait(), $retry->getErrorOutput());
            $event->refresh();
            $this->assertSame('delivered', $event->status);
            $this->assertSame(202, $event->last_http_status);
            $this->assertSame(2, $event->attempts);
            $this->assertSame($raw, $event->getRawOriginal('payload'));
            $this->assertNotNull($event->delivered_at);
            $this->assertNull($event->lease_token);
            $this->assertSame(DeliverHermesWebhook::QUEUE, DB::table('jobs')->sole()->queue);
            $this->assertDatabaseEmpty('failed_jobs');
        } finally {
            foreach (array_reverse($processes) as $process) {
                $process->stop(0);
            }
            if (is_file($gate.'.release')) {
                unlink($gate.'.release');
            }
            unlink($gate);
        }
    }

    private function startChild(string $url, bool $scan = false): Process
    {
        $process = new Process([PHP_BINARY], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'joyumkm_testing',
            'DB_URL' => '',
            'DB_QUEUE_CONNECTION' => 'mysql',
            'DB_QUEUE_TABLE' => 'jobs',
            'DB_QUEUE_RETRY_AFTER' => '90',
            'CACHE_STORE' => 'array',
            'HERMES_WEBHOOK_ENABLED' => 'true',
            'HERMES_WEBHOOK_URL' => $url,
            'HERMES_WEBHOOK_ALLOWED_HOST' => '127.0.0.1',
            'HERMES_WEBHOOK_SECRET' => HermesWebhookReceiver::SECRET,
            'HERMES_WEBHOOK_TIMEOUT_SECONDS' => '15',
            'JOYUMKM_HERMES_TEST_SCAN' => $scan ? '1' : '0',
        ], timeout: 20);
        $process->setInput(<<<'PHP'
            <?php
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $db = $app['db']->connection();
            if (! $app->environment('testing') || $db->getDriverName() !== 'mysql' || $db->scalar('SELECT DATABASE()') !== 'joyumkm_testing') {
                throw new RuntimeException('Koneksi pekerja di luar database uji.');
            }
            $input = getenv('JOYUMKM_HERMES_TEST_SCAN') === '1'
                ? ['command' => 'hermes:webhook-scan']
                : ['command' => 'queue:work', 'connection' => 'database', '--queue' => App\Jobs\DeliverHermesWebhook::QUEUE,
                    '--once' => true, '--tries' => 1, '--timeout' => 20];
            exit($app->handleCommand(new Symfony\Component\Console\Input\ArrayInput($input + ['--no-interaction' => true])));
            PHP);
        $process->start();

        return $process;
    }

    /** @param callable(): bool $ready */
    private function waitFor(Process $process, callable $ready): void
    {
        $deadline = microtime(true) + 6;
        do {
            if ($ready()) {
                return;
            }
            usleep(20_000);
        } while ($process->isRunning() && microtime(true) < $deadline);

        $this->fail('Proses webhook tidak siap. '.$process->getOutput().$process->getErrorOutput());
    }
}
