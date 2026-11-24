<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Services\SixFactorMonthlyRecapService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IntelligenceSnapshotServiceTest extends TestCase
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

    private function createV1Final(Umkm $umkm, string $submittedAtUtc, int $score): Evaluation
    {
        return $umkm->evaluations()->create([
            'status' => 'final', 'ins_score' => $score, 'ect_score' => $score, 'ops_score' => $score,
            'average_score' => $score, 'category' => 'BAIK',
            'submitted_at' => CarbonImmutable::parse($submittedAtUtc, 'UTC'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function asOf(SixFactorEvaluation $anchor, int $months = 12): array
    {
        return $this->recap->asOfEvaluation($anchor->umkm, $anchor->fresh(), $months);
    }

    public function test_cutoff_month_is_wib_so_17_utc_on_the_last_day_is_the_next_month(): void
    {
        $umkm = Umkm::factory()->create();
        $october = $this->createFinal($umkm, '2026-10-31 16:59:59');
        $anchor = $this->createFinal($umkm, '2026-10-31 17:00:00');

        $history = $this->asOf($anchor, 2);

        $this->assertSame('2026-11', $history[0]['period_month'], '17:00 UTC 31 Oktober adalah 00:00 WIB 1 November.');
        $this->assertSame('2026-10', $history[1]['period_month']);
        $this->assertSame($anchor->id, $history[0]['six_factor']['evaluation_id']);
        $this->assertSame($october->id, $history[1]['six_factor']['evaluation_id'], '16:59:59 UTC masih 23:59:59 WIB 31 Oktober.');
    }

    public function test_year_change_uses_wib_for_current_and_previous_months(): void
    {
        $umkm = Umkm::factory()->create();
        $december = $this->createFinal($umkm, '2026-12-15 03:00:00');
        $anchor = $this->createFinal($umkm, '2026-12-31 17:30:00');

        $history = $this->asOf($anchor, 2);

        $this->assertSame('2027-01', $history[0]['period_month'], '17:30 UTC 31 Desember adalah 00:30 WIB 1 Januari.');
        $this->assertSame('2026-12', $history[1]['period_month']);
        $this->assertSame($december->id, $history[1]['six_factor']['evaluation_id']);
        $this->assertSame($anchor->id, $history[0]['six_factor']['evaluation_id']);
    }

    public function test_finals_after_the_anchor_and_higher_id_at_the_same_instant_are_excluded(): void
    {
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $sameInstantLater = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $afterCutoff = $this->createFinal($umkm, '2026-11-12 09:00:00');

        $history = $this->asOf($anchor);

        $this->assertSame($anchor->id, $history[0]['six_factor']['evaluation_id'], 'Final setelah anchor tidak boleh menjadi bulan berjalan.');
        $this->assertNotContains($afterCutoff->id, array_column(array_column($history, 'six_factor'), 'evaluation_id'));
        $this->assertNotContains($sameInstantLater->id, array_column(array_column($history, 'six_factor'), 'evaluation_id'), 'ID lebih besar dengan waktu sama berada setelah anchor.');
        $this->assertSame('2026-11-10T08:00:00Z', $history[0]['six_factor']['finalized_at']);
    }

    public function test_identical_finalized_at_resolves_to_highest_id_at_or_below_the_anchor(): void
    {
        $umkm = Umkm::factory()->create();
        $lower = $this->createFinal($umkm, '2026-11-20 08:00:00');
        $higher = $this->createFinal($umkm, '2026-11-20 08:00:00');

        $atHigher = $this->asOf($higher);
        $atLower = $this->asOf($lower);

        $this->assertSame($higher->id, $atHigher[0]['six_factor']['evaluation_id'], 'Anchor ID terbesar menang.');
        $this->assertSame($lower->id, $atLower[0]['six_factor']['evaluation_id'], 'Anchor ID kecil tidak melihat ID yang lebih besar.');
        $this->assertSame(
            [$lower->id, $higher->id],
            SixFactorEvaluation::query()->where('umkm_id', $umkm->id)->orderBy('id')->pluck('id')->all(),
            'Kedua Final tetap tersimpan sebagai histori.',
        );
    }

    public function test_drafts_and_cancelled_are_never_selected(): void
    {
        $umkm = Umkm::factory()->create();
        SixFactorEvaluation::factory()->for($umkm)->create(['finalized_at' => null]);
        SixFactorEvaluation::factory()->for($umkm)->cancelled()->create();
        $anchor = $this->createFinal($umkm, '2026-11-05 03:00:00');

        $history = $this->asOf($anchor);

        $ids = array_filter(array_column(array_column($history, 'six_factor'), 'evaluation_id'));
        $this->assertSame([$anchor->id], array_values($ids));
    }

    public function test_missing_previous_month_is_a_data_gap_without_zero_values(): void
    {
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-12 09:00:00');

        $history = $this->asOf($anchor, 2);
        $previous = $history[1]['six_factor'];

        $this->assertSame('2026-10', $history[1]['period_month']);
        $this->assertSame('data_gap', $previous['status']);
        $this->assertNull($previous['evaluation_id']);
        $this->assertNull($previous['total_score']);
        $this->assertNull($previous['scores']);
        $this->assertSame('data_gap', $history[1]['three_dimension']['status']);
        $this->assertFalse($history[1]['three_dimension']['is_complete']);
    }

    public function test_three_dimension_after_the_cutoff_is_excluded_and_absence_is_a_gap(): void
    {
        $umkm = Umkm::factory()->create();
        $beforeCutoff = $this->createV1Final($umkm, '2026-11-02 04:00:00', 62);
        $anchor = $this->createFinal($umkm, '2026-11-10 08:00:00');
        $this->createV1Final($umkm, '2026-11-10 09:00:00', 91);

        $history = $this->asOf($anchor, 1);

        $this->assertSame('available', $history[0]['three_dimension']['status']);
        $this->assertSame($beforeCutoff->id, $history[0]['three_dimension']['evaluation_id'], 'Penilaian V1 setelah finalized_at anchor tidak dipakai.');
        $this->assertSame(62.0, (float) $history[0]['three_dimension']['average_score']);

        $otherUmkm = Umkm::factory()->create();
        $lonely = $this->createFinal($otherUmkm, '2026-11-10 08:00:00');
        $gap = $this->asOf($lonely, 1)[0]['three_dimension'];
        $this->assertSame('data_gap', $gap['status']);
        $this->assertNull($gap['average_score']);
        $this->assertNull($gap['scores']);
    }

    public function test_history_has_requested_months_newest_first_and_keeps_every_final_inside_the_window(): void
    {
        $umkm = Umkm::factory()->create();
        $september = $this->createFinal($umkm, '2026-09-10 01:00:00');
        $this->createFinal($umkm, '2026-11-02 01:00:00');
        $anchor = $this->createFinal($umkm, '2026-11-20 01:00:00');

        $history = $this->asOf($anchor);

        $this->assertCount(12, $history);
        $this->assertSame('2026-11', $history[0]['period_month']);
        $this->assertSame('2025-12', $history[11]['period_month']);
        $this->assertSame($anchor->id, $history[0]['six_factor']['evaluation_id']);
        $this->assertSame($september->id, $history[2]['six_factor']['evaluation_id']);
        $this->assertSame('data_gap', $history[1]['six_factor']['status'], 'Oktober tanpa Final tetap menjadi data_gap.');
        $this->assertSame(3, SixFactorEvaluation::query()->where('umkm_id', $umkm->id)->where('status', SixFactorEvaluation::FINAL)->count());
    }

    public function test_other_umkm_finals_are_never_selected(): void
    {
        $umkm = Umkm::factory()->create();
        $other = Umkm::factory()->create();
        $this->createFinal($other, '2026-11-03 01:00:00');
        $anchor = $this->createFinal($umkm, '2026-11-04 01:00:00');
        $this->createV1Final($other, '2026-11-03 02:00:00', 99);

        $history = $this->asOf($anchor);

        $this->assertSame($anchor->id, $history[0]['six_factor']['evaluation_id']);
        $this->assertSame('data_gap', $history[0]['three_dimension']['status']);
    }

    public function test_as_of_requires_a_final_anchor(): void
    {
        $umkm = Umkm::factory()->create();
        $draft = SixFactorEvaluation::factory()->for($umkm)->create(['finalized_at' => null]);

        $this->expectException(RuntimeException::class);

        $this->recap->asOfEvaluation($umkm, $draft, 12);
    }

    public function test_cutoff_rows_are_unaffected_by_later_rows_in_the_database(): void
    {
        $umkm = Umkm::factory()->create();
        $anchor = $this->createFinal($umkm, '2026-11-08 08:00:00');
        $before = $this->asOf($anchor);

        $this->createFinal($umkm, '2026-11-30 08:00:00');

        $this->assertSame($before, $this->asOf($anchor));
    }
}
