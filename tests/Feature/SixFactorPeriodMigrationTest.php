<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Umkm;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SixFactorPeriodMigrationTest extends TestCase
{
    use DatabaseTruncation;

    private const MIGRATION = 'migrations/2026_11_23_153541_add_period_month_to_six_factor_evaluations_table.php';

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('joyumkm_testing', DB::scalar('SELECT DATABASE()'));
    }

    protected function tearDown(): void
    {
        try {
            // Baris yang di-commit di sini tidak berada di dalam transaksi uji; bersihkan agar tidak bocor ke test lain.
            DB::table('six_factor_evaluations')->delete();
            DB::table('users')->delete();
        } finally {
            parent::tearDown();
        }
    }

    public function test_backfill_assigns_wib_months_to_existing_finals_and_then_enforces_the_check(): void
    {
        $migration = require database_path(self::MIGRATION);
        $umkm = Umkm::factory()->create();

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('six_factor_evaluations', 'period_month'));

            $finals = [
                '2026-10-31 16:59:59' => '2026-10',
                '2026-10-31 17:00:00' => '2026-11',
                '2026-12-31 17:00:00' => '2027-01',
                '2026-06-15 08:00:00' => '2026-06',
            ];
            $ids = [];
            foreach (array_keys($finals) as $finalizedAt) {
                $ids[$finalizedAt] = $this->insertFinal($umkm->id, $finalizedAt);
            }
            $draftId = DB::table('six_factor_evaluations')->insertGetId(['umkm_id' => $umkm->id, 'status' => 'draft']);

            $migration->up();

            foreach ($finals as $finalizedAt => $expected) {
                $this->assertSame($expected, DB::table('six_factor_evaluations')->where('id', $ids[$finalizedAt])->value('period_month'));
                $this->assertSame($finalizedAt, DB::table('six_factor_evaluations')->where('id', $ids[$finalizedAt])->value('finalized_at'));
            }
            $this->assertNull(DB::table('six_factor_evaluations')->where('id', $draftId)->value('period_month'));
            $this->assertMysqlRejects(3819, fn () => DB::table('six_factor_evaluations')
                ->where('id', $ids['2026-06-15 08:00:00'])->update(['period_month' => null]));
        } finally {
            if (! Schema::hasColumn('six_factor_evaluations', 'period_month')) {
                $migration->up();
            }
        }
    }

    private function insertFinal(int $umkmId, string $finalizedAt): int
    {
        return DB::table('six_factor_evaluations')->insertGetId([
            'umkm_id' => $umkmId,
            'status' => 'final',
            ...array_fill_keys(['sumber_daya', 'kepemimpinan', 'lingkungan_kerja', 'nilai_organisasi', 'stabilitas', 'kinerja'], 3),
            'total_score' => 18,
            'category' => 'Cukup Sehat',
            'finalized_at' => $finalizedAt,
            'created_at' => $finalizedAt,
            'updated_at' => $finalizedAt,
        ]);
    }

    private function assertMysqlRejects(int $code, Closure $operation): void
    {
        try {
            $operation();
            $this->fail("MySQL menerima operasi yang seharusnya ditolak dengan kode {$code}.");
        } catch (QueryException $exception) {
            $this->assertSame($this->databaseErrorCode($code), $exception->errorInfo[1], $exception->getMessage());
        }
    }
}
