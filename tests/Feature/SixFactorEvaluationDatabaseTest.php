<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Question;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Services\SixFactorEvaluationService;
use App\Services\UmkmService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SixFactorEvaluationDatabaseTest extends TestCase
{
    use DatabaseTruncation;

    private const TABLE = 'six_factor_evaluations';

    private const FACTORS = [
        'sumber_daya', 'kepemimpinan', 'lingkungan_kerja', 'nilai_organisasi', 'stabilitas', 'kinerja',
    ];

    private bool $verifiedTestSchema = false;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('joyumkm_testing', DB::scalar('SELECT DATABASE()'));
        $this->verifiedTestSchema = true;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->verifiedTestSchema) {
                if (Schema::hasTable(self::TABLE)) {
                    DB::table(self::TABLE)->delete();
                }
                DB::table('users')->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_migration_preserves_v1_schema_and_data_and_only_rolls_back_when_empty(): void
    {
        $migration = require database_path('migrations/2026_11_20_084534_create_six_factor_evaluations_table.php');
        // Tabel laporan Intelligence memiliki FK ke tabel ini sehingga harus dilepas lebih dulu, seperti urutan rollback sebenarnya.
        $dependent = require database_path('migrations/2026_11_23_050328_create_intelligence_reports_table.php');
        $umkm = Umkm::factory()->create();
        $umkm->user->createToken('fixture');
        $legacy = $umkm->evaluations()->create(['status' => 'final', 'average_score' => 78, 'submitted_at' => now()]);
        $legacy->answers()->create(['question_id' => Question::query()->firstOrFail()->id, 'value' => 3]);
        $dependent->down();
        $migration->down();

        try {
            $tables = $this->tableNames();
            $v1 = $this->legacyFingerprints();

            $migration->up();
            $this->assertSame([self::TABLE], array_values(array_diff($this->tableNames(), $tables)));
            $this->assertSame([], array_diff($tables, $this->tableNames()));
            $this->assertSame($v1, $this->legacyFingerprints());
            $migration->down();
            $this->assertSame($tables, $this->tableNames());
            $this->assertSame($v1, $this->legacyFingerprints());

            $migration->up();
            $evaluation = SixFactorEvaluation::factory()->for($umkm)->create(['kinerja' => 4]);
            try {
                $migration->down();
                $this->fail('Rollback menghapus tabel berisi evaluasi.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Rollback ditolak karena tabel evaluasi enam faktor masih berisi data.', $exception->getMessage());
            }

            $this->assertDatabaseHas(self::TABLE, ['id' => $evaluation->id, 'umkm_id' => $umkm->id, 'kinerja' => 4]);
            $this->assertSame($v1, $this->legacyFingerprints());
        } finally {
            if (! Schema::hasTable(self::TABLE)) {
                $migration->up();
            }
            if (! Schema::hasTable('intelligence_reports')) {
                $dependent->up();
            }
        }
    }

    public function test_schema_has_required_columns_indexes_checks_and_foreign_key(): void
    {
        $columns = collect(Schema::getColumns(self::TABLE))->keyBy('name');
        $this->assertSame([
            'id', 'umkm_id', 'status', ...self::FACTORS, 'total_score', 'category',
            'finalized_at', 'cancelled_at', 'created_at', 'updated_at', 'active_draft_umkm_id',
        ], $columns->keys()->all());
        $this->assertSame('draft', $columns['status']['default']);
        $this->assertFalse($columns['status']['nullable']);
        foreach (self::FACTORS as $factor) {
            $this->assertSame('tinyint unsigned', $columns[$factor]['type']);
            $this->assertTrue($columns[$factor]['nullable']);
        }
        $this->assertSame('stored', $columns['active_draft_umkm_id']['generation']['type']);
        $this->assertTrue($columns['active_draft_umkm_id']['nullable']);

        $indexes = collect(Schema::getIndexes(self::TABLE))->keyBy('name');
        $unique = $indexes['six_factor_active_draft_unique'];
        $this->assertTrue($unique['unique']);
        $this->assertSame(['active_draft_umkm_id'], $unique['columns']);
        $this->assertSame(['umkm_id', 'status', 'id'], $indexes['six_factor_umkm_status_id_index']['columns']);

        $foreignKeys = Schema::getForeignKeys(self::TABLE);
        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['umkm_id'], $foreignKeys[0]['columns']);
        $this->assertSame('umkms', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('restrict', $foreignKeys[0]['on_delete']);

        $checks = DB::table('information_schema.table_constraints')
            ->where('table_schema', 'joyumkm_testing')
            ->where('table_name', self::TABLE)
            ->where('constraint_type', 'CHECK')
            ->orderBy('CONSTRAINT_NAME')
            ->pluck('ENFORCED', 'CONSTRAINT_NAME')->all();
        $this->assertSame([
            'six_factor_scores_check' => 'YES',
            'six_factor_status_check' => 'YES',
        ], $checks);
    }

    public function test_mysql_rejects_each_out_of_range_factor_on_insert_and_update(): void
    {
        $umkm = Umkm::factory()->create();
        foreach (self::FACTORS as $factor) {
            foreach ([0, 6] as $value) {
                $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->insert([
                    'umkm_id' => $umkm->id, $factor => $value,
                ]));
            }
        }
        $draft = SixFactorEvaluation::factory()->for($umkm)->create();
        foreach (self::FACTORS as $factor) {
            $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->where('id', $draft->id)->update([$factor => 6]));
        }
        $this->assertSame(array_fill_keys(self::FACTORS, null), $draft->fresh()->only(self::FACTORS));
    }

    public function test_mysql_rejects_inconsistent_status_scores_categories_and_timestamps(): void
    {
        $umkm = Umkm::factory()->create();
        $finalValues = ['status' => 'final', ...array_fill_keys(self::FACTORS, 2), 'total_score' => 12,
            'category' => 'Kurang Sehat', 'finalized_at' => now()];
        $invalidRows = [
            ['status' => 'unknown'],
            ['status' => 'DRAFT'],
            ['status' => 'draft '],
            ['total_score' => 6],
            ['category' => 'Sehat'],
            ['finalized_at' => now()],
            ['cancelled_at' => now()],
            ['status' => 'cancelled'],
            ['status' => 'cancelled', 'cancelled_at' => now(), 'finalized_at' => now()],
            ['status' => 'cancelled', 'cancelled_at' => now(), 'total_score' => 6],
            ['status' => 'cancelled', 'cancelled_at' => now(), 'category' => 'Sehat'],
        ];
        foreach ($invalidRows as $row) {
            $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->insert(['umkm_id' => $umkm->id, ...$row]));
        }

        $final = DB::table(self::TABLE)->insertGetId(['umkm_id' => $umkm->id, ...$finalValues]);
        $invalidFinals = [
            ['finalized_at' => null], ['cancelled_at' => now()], ['total_score' => null],
            ['total_score' => 13], ['category' => null], ['category' => 'Cukup Sehat'],
            ['category' => 'kurang sehat'], ['category' => 'Kurang Sehat '],
        ];
        foreach (self::FACTORS as $factor) {
            $invalidFinals[] = [$factor => null];
        }
        foreach ($invalidFinals as $invalid) {
            $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->insert([
                'umkm_id' => $umkm->id, ...$finalValues, ...$invalid,
            ]));
            $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->where('id', $final)->update($invalid));
        }
        $this->assertDatabaseHas(self::TABLE, ['id' => $final, 'total_score' => 12, 'category' => 'Kurang Sehat']);
    }

    public function test_unique_index_allows_one_draft_per_umkm_alongside_multiple_terminal_records(): void
    {
        $umkm = Umkm::factory()->create();
        $otherUmkm = Umkm::factory()->for($umkm->user)->create();
        $first = SixFactorEvaluation::factory()->for($umkm)->create();
        $second = SixFactorEvaluation::factory()->for($otherUmkm)->create();

        $this->assertMysqlRejects(1062, fn () => DB::table(self::TABLE)->insert(['umkm_id' => $umkm->id]));
        $this->assertSame($umkm->id, $first->fresh()->active_draft_umkm_id);
        $this->assertSame($otherUmkm->id, $second->fresh()->active_draft_umkm_id);

        SixFactorEvaluation::factory()->for($umkm)->final()->count(2)->create();
        SixFactorEvaluation::factory()->for($umkm)->cancelled()->create();

        $this->assertSame(4, $umkm->sixFactorEvaluations()->count());
        $this->assertSame(3, $umkm->sixFactorEvaluations()->whereNull('active_draft_umkm_id')->count());
    }

    public function test_foreign_key_preserves_history_and_legacy_delete_still_applies_without_six_factor_data(): void
    {
        $umkm = Umkm::factory()->create();
        $legacyOnly = Umkm::factory()->for($umkm->user)->create();
        $legacyEvaluation = $legacyOnly->evaluations()->create(['status' => 'draft']);
        $final = SixFactorEvaluation::factory()->for($umkm)->final()->create();
        $missingId = Umkm::max('id') + 1;

        $this->assertMysqlRejects(1452, fn () => DB::table(self::TABLE)->insert(['umkm_id' => $missingId]));
        $this->assertMysqlRejects(1451, fn () => app(UmkmService::class)->delete($umkm->user, $umkm->id));
        $this->assertModelExists($umkm);
        $this->assertModelExists($final);

        app(UmkmService::class)->delete($legacyOnly->user, $legacyOnly->id);
        $this->assertModelMissing($legacyOnly);
        $this->assertModelMissing($legacyEvaluation);
    }

    #[DataProvider('raceOrderings')]
    public function test_independent_writers_serialize_on_the_parent_lock(string $firstAction, string $secondAction): void
    {
        $umkm = Umkm::factory()->create();
        $owner = $umkm->user;
        $service = app(SixFactorEvaluationService::class);
        $base = $firstAction === 'finalize' || $secondAction === 'finalize'
            ? $service->saveDraft($owner, $umkm->id, array_fill_keys(self::FACTORS, 2))
            : null;

        $first = DB::transaction(function () use ($service, $owner, $umkm, $firstAction, $secondAction, &$worker): SixFactorEvaluation {
            $first = $firstAction === 'finalize'
                ? $service->finalize($owner, $umkm->id)
                : $service->saveDraft($owner, $umkm->id, ['sumber_daya' => 3]);
            $worker = $this->startWriter($owner->id, $umkm->id, $secondAction);

            try {
                $this->waitForQuery($worker, 'from `umkms`', 'for update');
            } catch (\Throwable $exception) {
                $worker->stop(0);
                throw $exception;
            }

            return $first;
        });
        $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        $second = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        $draft = $service->activeDraft($owner, $umkm->id);
        $final = $service->latestFinal($owner, $umkm->id);

        if ($firstAction === 'finalize') {
            $this->assertSame([$base->id, 12], [$final?->id, $final?->total_score]);
            $this->assertSame([$draft?->id, 'draft'], $second);
            $this->assertNotSame($first->id, $draft?->id);
            $this->assertSame([null, 5], [$draft?->sumber_daya, $draft?->kinerja]);
        } elseif ($secondAction === 'finalize') {
            $this->assertSame([$base->id, 'final'], $second);
            $this->assertSame([$first->id, 13, 'Cukup Sehat'], [$final?->id, $final?->total_score, $final?->category]);
            $this->assertNull($draft);
        } else {
            $this->assertSame([$first->id, 'draft'], $second);
            $this->assertSame([$first->id, 3, 5], [$draft?->id, $draft?->sumber_daya, $draft?->kinerja]);
            $this->assertNull($final);
        }
        $this->assertSame($draft === null ? 0 : 1, $umkm->sixFactorEvaluations()->where('status', 'draft')->count());
        $this->assertSame($firstAction === 'finalize' ? 2 : 1, $umkm->sixFactorEvaluations()->count());
    }

    public static function raceOrderings(): array
    {
        return [
            'save and save' => ['saveDraft', 'saveDraft'],
            'finalize before save' => ['finalize', 'saveDraft'],
            'save before finalize' => ['saveDraft', 'finalize'],
        ];
    }

    public function test_concurrent_first_saves_for_different_umkms_recover_from_a_gap_lock_deadlock(): void
    {
        $first = Umkm::factory()->create();
        $second = Umkm::factory()->create();
        $barrier = 'joyumkm_p0_'.bin2hex(random_bytes(8));
        $this->assertSame(1, DB::scalar('SELECT GET_LOCK(?, 0)', [$barrier]));
        $workers = [];

        try {
            try {
                foreach ([$first, $second] as $umkm) {
                    $workers[] = $this->startWriter($umkm->user_id, $umkm->id, 'saveDraft', $barrier);
                }
                foreach ($workers as $worker) {
                    $this->waitForQuery($worker, 'get_lock(');
                }
            } finally {
                DB::scalar('SELECT RELEASE_LOCK(?)', [$barrier]);
            }

            $attempts = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                [, , $attempts[]] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }

            sort($attempts);
            $this->assertSame([1, 2], $attempts);
            $this->assertDatabaseHas(self::TABLE, ['umkm_id' => $first->id, 'status' => 'draft', 'kinerja' => 5]);
            $this->assertDatabaseHas(self::TABLE, ['umkm_id' => $second->id, 'status' => 'draft', 'kinerja' => 5]);
            $this->assertDatabaseCount(self::TABLE, 2);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(0);
            }
        }
    }

    private function assertMysqlRejects(int $code, Closure $operation): void
    {
        try {
            $operation();
            $this->fail("MySQL menerima operasi yang seharusnya ditolak dengan kode {$code}.");
        } catch (QueryException $exception) {
            $this->assertSame($code, $exception->errorInfo[1], $exception->getMessage());
        }
    }

    private function tableNames(): array
    {
        return Schema::getTableListing(DB::connection()->getDatabaseName(), schemaQualified: false);
    }

    private function legacyFingerprints(): array
    {
        $result = [];
        foreach (['users', 'personal_access_tokens', 'umkms', 'sectors', 'factors', 'questions', 'evaluations', 'evaluation_answers'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->all();
            $this->assertNotEmpty($rows, "Fixture {$table} harus berisi data.");
            $result[$table] = [
                DB::selectOne("SHOW CREATE TABLE `{$table}`")->{'Create Table'},
                count($rows),
                hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            ];
        }

        return $result;
    }

    private function startWriter(int $userId, int $umkmId, string $action, string $barrier = ''): Process
    {
        $process = new Process([PHP_BINARY], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'joyumkm_testing',
            'DB_URL' => '',
            'JOYUMKM_P0_TEST_USER' => (string) $userId,
            'JOYUMKM_P0_TEST_UMKM' => (string) $umkmId,
            'JOYUMKM_P0_TEST_ACTION' => $action,
            'JOYUMKM_P0_TEST_INSERT_BARRIER' => $barrier,
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
            $user = App\Models\User::findOrFail((int) getenv('JOYUMKM_P0_TEST_USER'));
            $umkmId = (int) getenv('JOYUMKM_P0_TEST_UMKM');
            $service = $app->make(App\Services\SixFactorEvaluationService::class);
            $barrier = getenv('JOYUMKM_P0_TEST_INSERT_BARRIER');
            $attempts = 0;
            if ($barrier !== '') {
                $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                App\Models\SixFactorEvaluation::creating(static function () use ($db, $barrier, &$attempts): void {
                    $attempts++;
                    if ($db->scalar('SELECT GET_LOCK(?, 9)', [$barrier]) !== 1) {
                        throw new RuntimeException('Barrier INSERT tidak dapat diperoleh.');
                    }
                    $db->scalar('SELECT RELEASE_LOCK(?)', [$barrier]);
                });
            }
            try {
                $evaluation = getenv('JOYUMKM_P0_TEST_ACTION') === 'finalize'
                    ? $service->finalize($user, $umkmId)
                    : $service->saveDraft($user, $umkmId, ['kinerja' => 5]);
            } catch (Throwable $exception) {
                file_put_contents('php://stderr', $exception->getMessage().PHP_EOL);
                exit(1);
            }
            $result = [$evaluation->id, $evaluation->status];
            if ($barrier !== '') {
                $result[] = $attempts;
            }
            echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
            PHP);
        $process->start();

        return $process;
    }

    private function waitForQuery(Process $worker, string ...$fragments): void
    {
        $deadline = microtime(true) + 7;
        do {
            $connectionId = (int) strtok($worker->getOutput(), "\n");
            if ($connectionId > 0) {
                foreach (DB::select('SHOW FULL PROCESSLIST') as $process) {
                    if ((int) $process->Id === $connectionId
                        && str(strtolower($process->Info ?? ''))->containsAll($fragments)) {
                        return;
                    }
                }
            }
            usleep(20_000);
        } while ($worker->isRunning() && microtime(true) < $deadline);

        $this->fail('Pernyataan penulis tidak terlihat di MySQL: '.implode(', ', $fragments).'. '.$worker->getOutput().$worker->getErrorOutput());
    }
}
