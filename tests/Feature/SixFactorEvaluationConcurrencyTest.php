<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Question;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SixFactorEvaluationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private ?Umkm $umkm = null;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('joyumkm_testing', DB::scalar('SELECT DATABASE()'));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->umkm !== null) {
                $this->umkm->sixFactorEvaluations()->delete();
                $this->umkm->user->tokens()->delete();
                $this->umkm->user->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_parallel_first_saves_create_one_draft_and_merge_all_sent_factors(): void
    {
        $umkm = $this->umkm = Umkm::factory()->create();
        $base = $this->endpoint($umkm);
        [$first, $second, $third] = $this->overlap(
            ['PUT', $base.'/draft', '{"scores":{"sumber_daya":4}}'],
            [
                ['PATCH', $base.'/draft', '{"scores":{"kinerja":5}}'],
                ['PUT', $base.'/draft', '{"scores":{"stabilitas":2}}'],
            ],
            ['from `umkms`', 'for update'],
        );

        $this->assertSame([201, 200, 200], array_column([$first, $second, $third], 'status'));
        $id = $first['body']['data']['id'];
        $this->assertSame($id, $second['body']['data']['id']);
        $this->assertSame($id, $third['body']['data']['id']);
        $this->assertDatabaseCount('six_factor_evaluations', 1);
        $this->assertDatabaseHas('six_factor_evaluations', [
            'id' => $id, 'umkm_id' => $umkm->id, 'status' => 'draft',
            'sumber_daya' => 4, 'kinerja' => 5, 'stabilitas' => 2,
        ]);
    }

    #[TestWith(['finalize', 'finalize', 'final', 'Evaluasi sudah Final dan tidak dapat diubah.'])]
    #[TestWith(['finalize', 'cancel', 'final', 'Evaluasi sudah Final dan tidak dapat diubah.'])]
    #[TestWith(['cancel', 'finalize', 'cancelled', 'Evaluasi sudah dibatalkan dan tidak dapat diubah.'])]
    public function test_parallel_terminal_requests_allow_one_transition(
        string $firstAction, string $secondAction, string $status, string $message,
    ): void {
        $umkm = $this->umkm = Umkm::factory()->create();
        $draft = SixFactorEvaluation::factory()->for($umkm)->create(array_fill_keys([
            'sumber_daya', 'kepemimpinan', 'lingkungan_kerja', 'nilai_organisasi', 'stabilitas', 'kinerja',
        ], 3));
        $url = $this->endpoint($umkm).'/'.$draft->id.'/';
        [$first, $second] = $this->overlap(
            ['POST', $url.$firstAction, '{}'],
            [['POST', $url.$secondAction, '']],
            ['from `umkms`', 'for update'],
        );

        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame($draft->id, $first['body']['data']['id']);
        $this->assertSame(['message' => $message], $second['body']);
        $this->assertDatabaseCount('six_factor_evaluations', 1);
        $this->assertDatabaseHas('six_factor_evaluations', ['id' => $draft->id, 'status' => $status]);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_delete_and_first_save_accommodate_both_lock_orders(bool $saveFirst): void
    {
        $umkm = $this->umkm = Umkm::factory()->create();
        $legacy = $umkm->evaluations()->create(['status' => 'draft']);
        $answer = $legacy->answers()->create(['question_id' => Question::query()->firstOrFail()->id, 'value' => 3]);
        $save = ['PUT', $this->endpoint($umkm).'/draft', '{"scores":{"kinerja":5}}'];
        $delete = ['DELETE', '/api/umkms/'.$umkm->id, ''];
        [$first, $second] = $this->overlap(
            $saveFirst ? $save : $delete,
            [$saveFirst ? $delete : $save],
            $saveFirst ? ['delete from `umkms`'] : ['from `umkms`', 'for update'],
        );

        if ($saveFirst) {
            $this->assertSame([201, 409], [$first['status'], $second['status']]);
            $this->assertSame([
                'message' => 'UMKM tidak dapat dihapus karena memiliki riwayat penilaian enam faktor.',
            ], $second['body']);
            $this->assertDatabaseHas('six_factor_evaluations', ['umkm_id' => $umkm->id, 'kinerja' => 5]);
            $this->assertModelExists($umkm);
            $this->assertModelExists($legacy);
            $this->assertModelExists($answer);

            return;
        }

        $this->assertSame([200, 404], [$first['status'], $second['status']]);
        $this->assertSame(['message' => 'Data tidak ditemukan.'], $second['body']);
        $this->assertDatabaseEmpty('six_factor_evaluations');
        $this->assertModelMissing($umkm);
        $this->assertModelMissing($legacy);
        $this->assertModelMissing($answer);
    }

    private function endpoint(Umkm $umkm): string
    {
        return '/api/umkms/'.$umkm->id.'/six-factor-evaluations';
    }

    /**
     * @param  array{string, string, string}  $first
     * @param  list<array{string, string, string}>  $concurrent
     * @param  list<string>  $lockQuery
     * @return list<array{status: int, body: array}>
     */
    private function overlap(array $first, array $concurrent, array $lockQuery): array
    {
        $owner = $this->umkm->user;
        $firstToken = $owner->createToken('first')->plainTextToken;
        $tokens = array_map(fn (): string => $owner->createToken('concurrent')->plainTextToken, $concurrent);
        $processes = [];
        try {
            $results = DB::transaction(function () use ($first, $firstToken, $tokens, $concurrent, $lockQuery, &$processes): array {
                $response = $this->call($first[0], $first[1], [], [], [], [
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$firstToken,
                ], $first[2]);
                foreach ($concurrent as $index => $request) {
                    $worker = $this->startRequest($request, $tokens[$index]);
                    $processes[] = $worker;
                    $this->waitForLockQuery($worker, $lockQuery);
                }

                return [['status' => $response->status(), 'body' => $response->json()]];
            });

            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
        }
    }

    /** @param array{string, string, string} $request */
    private function startRequest(array $request, string $token): Process
    {
        $process = new Process([PHP_BINARY], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'joyumkm_testing',
            'DB_URL' => '',
            'JOYUMKM_P1_TEST_METHOD' => $request[0],
            'JOYUMKM_P1_TEST_PATH' => $request[1],
            'JOYUMKM_P1_TEST_BODY' => $request[2],
            'JOYUMKM_P1_TEST_TOKEN' => $token,
        ], timeout: 20);
        $process->setInput(<<<'PHP'
            <?php
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $db = $app['db']->connection();
            if ($db->getDriverName() !== 'mysql' || $db->scalar('SELECT DATABASE()') !== 'joyumkm_testing') {
                throw new RuntimeException('Koneksi pekerja di luar database uji.');
            }
            $db->statement('SET SESSION innodb_lock_wait_timeout = 12');
            echo $db->scalar('SELECT CONNECTION_ID()').PHP_EOL;
            flush();
            $request = Illuminate\Http\Request::create(
                getenv('JOYUMKM_P1_TEST_PATH'), getenv('JOYUMKM_P1_TEST_METHOD'), server: [
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.getenv('JOYUMKM_P1_TEST_TOKEN'),
                ], content: getenv('JOYUMKM_P1_TEST_BODY') ?: '',
            );
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            echo json_encode([
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR).PHP_EOL;
            PHP);
        $process->start();

        return $process;
    }

    /** @param list<string> $fragments */
    private function waitForLockQuery(Process $worker, array $fragments): void
    {
        $deadline = microtime(true) + 5;
        $mainConnection = (int) DB::scalar('SELECT CONNECTION_ID()');
        do {
            $workerConnection = (int) strtok($worker->getOutput(), "\n");
            if ($workerConnection > 0) {
                foreach (DB::select('SHOW FULL PROCESSLIST') as $session) {
                    if ((int) $session->Id === $workerConnection
                        && str(strtolower($session->Info ?? ''))->containsAll($fragments)) {
                        $this->assertNotSame($mainConnection, $workerConnection);

                        return;
                    }
                }
            }
            usleep(20_000);
        } while ($worker->isRunning() && microtime(true) < $deadline);

        $this->fail('Request tidak sampai pada query yang menunggu lock: '.$worker->getOutput().$worker->getErrorOutput());
    }
}
