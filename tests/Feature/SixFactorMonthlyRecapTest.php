<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Services\SixFactorMonthlyRecapService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SixFactorMonthlyRecapTest extends TestCase
{
    use LazilyRefreshDatabase;

    private SixFactorMonthlyRecapService $recap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recap = app(SixFactorMonthlyRecapService::class);
    }

    private function createFinal(Umkm $umkm, string $finalizedAtUtc): SixFactorEvaluation
    {
        return SixFactorEvaluation::factory()->for($umkm)->final()->create([
            'finalized_at' => CarbonImmutable::parse($finalizedAtUtc, 'UTC'),
        ]);
    }

    public function test_latest_final_per_wib_month_uses_finalized_at_then_id_and_keeps_every_final(): void
    {
        $umkm = Umkm::factory()->create();
        $octoberEarly = $this->createFinal($umkm, '2026-10-15 02:00:00');
        $boundary = $this->createFinal($umkm, '2026-10-31 17:00:00');
        $older = $this->createFinal($umkm, '2026-11-05 03:00:00');
        $tiedFirst = $this->createFinal($umkm, '2026-11-20 08:00:00');
        $tiedWinner = $this->createFinal($umkm, '2026-11-20 08:00:00');
        $draft = SixFactorEvaluation::factory()->for($umkm)->create();
        $cancelled = SixFactorEvaluation::factory()->for($umkm)->cancelled()->create();

        $history = $this->recap->history($umkm, CarbonImmutable::parse('2026-11-25 04:00:00', 'UTC'), 2);

        $this->assertSame('2026-11', $boundary->fresh()->period_month, 'Batas 17:00 UTC jatuh di November WIB.');

        $this->assertSame(['2026-11', '2026-10'], array_column($history, 'period_month'));
        $this->assertSame($tiedWinner->id, $history[0]['six_factor']['evaluation_id'], 'Finalized_at yang sama dimenangkan oleh ID terbesar.');
        $this->assertSame($octoberEarly->id, $history[1]['six_factor']['evaluation_id'], 'Oktober hanya memuat Final yang jatuh di Oktober WIB.');
        $this->assertSame(
            [$octoberEarly->id, $boundary->id, $older->id, $tiedFirst->id, $tiedWinner->id],
            SixFactorEvaluation::query()->where('umkm_id', $umkm->id)->where('status', SixFactorEvaluation::FINAL)->orderBy('id')->pluck('id')->all(),
            'Semua Final tetap tersimpan sebagai histori.',
        );
        $this->assertNotContains($draft->id, [$history[0]['six_factor']['evaluation_id'], $history[1]['six_factor']['evaluation_id']]);
        $this->assertNotContains($cancelled->id, [$history[0]['six_factor']['evaluation_id'], $history[1]['six_factor']['evaluation_id']]);
    }

    public function test_comparison_marks_a_month_without_final_as_data_gap_and_keeps_the_other_month(): void
    {
        $umkm = Umkm::factory()->create();
        $current = $this->createFinal($umkm, '2026-11-12 09:00:00');

        $comparison = $this->recap->comparison($umkm, CarbonImmutable::parse('2026-11-15 00:00:00', 'UTC'));

        $this->assertSame('2026-10', $comparison['previous']['period_month']);
        $this->assertSame('data_gap', $comparison['previous']['six_factor']['status']);
        $this->assertNull($comparison['previous']['six_factor']['evaluation_id']);
        $this->assertNull($comparison['previous']['six_factor']['total_score']);
        $this->assertSame('data_gap', $comparison['previous']['three_dimension']['status']);

        $this->assertSame('2026-11', $comparison['current']['period_month']);
        $this->assertSame('available', $comparison['current']['six_factor']['status']);
        $this->assertSame($current->id, $comparison['current']['six_factor']['evaluation_id']);
        $this->assertSame('2026-11-12T09:00:00Z', $comparison['current']['six_factor']['finalized_at']);
    }

    public function test_history_lists_newest_first_with_data_gaps_for_empty_months(): void
    {
        $umkm = Umkm::factory()->create();
        $september = $this->createFinal($umkm, '2026-09-10 01:00:00');
        $november = $this->createFinal($umkm, '2026-11-02 01:00:00');

        $history = $this->recap->history($umkm, CarbonImmutable::parse('2026-11-30 12:00:00', 'UTC'), 3);

        $this->assertSame(['2026-11', '2026-10', '2026-09'], array_column($history, 'period_month'));
        $this->assertSame([$november->id, null, $september->id], array_map(
            fn (array $row): ?int => $row['six_factor']['evaluation_id'],
            $history,
        ));
        $this->assertSame('data_gap', $history[1]['six_factor']['status']);
    }

    public function test_three_dimension_uses_submitted_at_then_created_at_in_wib_months(): void
    {
        $umkm = Umkm::factory()->create();
        $octoberEdge = $umkm->evaluations()->create([
            'status' => 'final', 'ins_score' => 60, 'ect_score' => 60, 'ops_score' => 60, 'average_score' => 60, 'category' => 'CUKUP',
            'submitted_at' => CarbonImmutable::parse('2026-10-31 16:59:59', 'UTC'),
        ]);
        $novemberBoundary = $umkm->evaluations()->create([
            'status' => 'final', 'ins_score' => 70, 'ect_score' => 70, 'ops_score' => 70, 'average_score' => 70, 'category' => 'BAIK',
            'submitted_at' => CarbonImmutable::parse('2026-10-31 17:00:00', 'UTC'),
        ]);
        $createdOnly = $umkm->evaluations()->create([
            'status' => 'final', 'ins_score' => 80, 'ect_score' => 80, 'ops_score' => 80, 'average_score' => 80, 'category' => 'BAIK',
            'submitted_at' => null,
        ]);
        DB::table('evaluations')->where('id', $createdOnly->id)->update(['created_at' => '2026-11-03 01:00:00']);
        $draft = $umkm->evaluations()->create(['status' => 'draft', 'submitted_at' => null]);
        DB::table('evaluations')->where('id', $draft->id)->update(['created_at' => '2026-11-04 01:00:00']);

        $comparison = $this->recap->comparison($umkm, CarbonImmutable::parse('2026-11-10 00:00:00', 'UTC'));

        $this->assertSame($octoberEdge->id, $comparison['previous']['three_dimension']['evaluation_id'], 'Submitted 16:59:59 UTC masih Oktober WIB.');
        $this->assertSame($createdOnly->id, $comparison['current']['three_dimension']['evaluation_id'], 'created_at dipakai saat submitted_at kosong dan menang sebagai penilaian terbaru November.');
        $this->assertNotSame($novemberBoundary->id, $comparison['current']['three_dimension']['evaluation_id']);
        $this->assertSame('2026-11-03T01:00:00Z', $comparison['current']['three_dimension']['assessed_at']);
        $this->assertTrue($comparison['current']['three_dimension']['is_complete']);
        $this->assertSame(3, Evaluation::query()->where('umkm_id', $umkm->id)->where('status', 'final')->count(), 'Penilaian V1 tidak diubah oleh rekap.');
    }

    public function test_recap_is_scoped_to_one_umkm(): void
    {
        $umkm = Umkm::factory()->create();
        $other = Umkm::factory()->create();
        $this->createFinal($other, '2026-11-02 01:00:00');

        $comparison = $this->recap->comparison($umkm, CarbonImmutable::parse('2026-11-10 00:00:00', 'UTC'));

        $this->assertSame('data_gap', $comparison['current']['six_factor']['status']);
        $this->assertSame('data_gap', $comparison['current']['three_dimension']['status']);
    }
}
