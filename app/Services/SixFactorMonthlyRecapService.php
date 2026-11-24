<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evaluation;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Rekap bulanan per UMKM berbasis periode WIB.
 *
 * Enam Faktor memakai Final terbaru per bulan menurut finalized_at, lalu id terbesar.
 * Tiga Dimensi (V1) memakai COALESCE(submitted_at, created_at) tanpa kolom baru.
 * Bulan tanpa Final dikembalikan sebagai data_gap, bukan dihitung dari bulan lain.
 */
class SixFactorMonthlyRecapService
{
    private const ASSESSED_AT = 'COALESCE(submitted_at, created_at)';

    /**
     * Rekap bulan sebelumnya dan bulan berjalan (WIB) pada waktu acuan.
     *
     * @return array{previous: array<string, mixed>, current: array<string, mixed>}
     */
    public function comparison(Umkm $umkm, CarbonImmutable $asOf): array
    {
        $current = $this->periodOf($asOf);
        $previous = CarbonImmutable::parse($current.'-01', SixFactorEvaluation::PERIOD_TIMEZONE)->subMonth()->format('Y-m');
        $recap = $this->recap($umkm, [$previous, $current]);

        return ['previous' => $recap[$previous], 'current' => $recap[$current]];
    }

    /**
     * Riwayat bulanan dari bulan berjalan ke belakang, termasuk bulan tanpa Final.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Umkm $umkm, CarbonImmutable $asOf, int $months): array
    {
        $periods = [];
        $cursor = CarbonImmutable::parse($this->periodOf($asOf).'-01', SixFactorEvaluation::PERIOD_TIMEZONE);
        for ($index = 0; $index < $months; $index++) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->subMonth();
        }

        $recap = $this->recap($umkm, $periods);

        return array_map(static fn (string $period): array => $recap[$period], $periods);
    }

    /**
     * @param  list<string>  $periods  Format YYYY-MM.
     * @return array<string, array<string, mixed>>
     */
    private function recap(Umkm $umkm, array $periods): array
    {
        $sixFactor = $this->latestSixFactorFinals($umkm, $periods);
        $threeDimension = $this->latestThreeDimensionFinals($umkm, $periods);

        $recap = [];
        foreach ($periods as $period) {
            $recap[$period] = [
                'period_month' => $period,
                'six_factor' => $this->sixFactorRow($sixFactor[$period] ?? null),
                'three_dimension' => $this->threeDimensionRow($threeDimension[$period] ?? null),
            ];
        }

        return $recap;
    }

    /**
     * @param  list<string>  $periods
     * @return array<string, SixFactorEvaluation>
     */
    private function latestSixFactorFinals(Umkm $umkm, array $periods): array
    {
        $latest = [];
        $finals = SixFactorEvaluation::query()
            ->where('umkm_id', $umkm->id)
            ->where('status', SixFactorEvaluation::FINAL)
            ->whereIn('period_month', $periods)
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->get();

        foreach ($finals as $final) {
            $latest[$final->period_month] ??= $final;
        }

        return $latest;
    }

    /**
     * @param  list<string>  $periods
     * @return array<string, object>
     */
    private function latestThreeDimensionFinals(Umkm $umkm, array $periods): array
    {
        sort($periods);
        $start = CarbonImmutable::parse($periods[0].'-01', SixFactorEvaluation::PERIOD_TIMEZONE)->utc();
        $end = CarbonImmutable::parse(end($periods).'-01', SixFactorEvaluation::PERIOD_TIMEZONE)->addMonth()->utc();

        $assessments = Evaluation::query()
            ->where('umkm_id', $umkm->id)
            ->where('status', 'final')
            ->whereRaw(self::ASSESSED_AT.' >= ? AND '.self::ASSESSED_AT.' < ?', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ])
            ->orderByRaw(self::ASSESSED_AT.' DESC')
            ->orderByDesc('id')
            ->get(['id', 'ins_score', 'ect_score', 'ops_score', 'average_score', 'category', 'submitted_at', 'created_at'])
            ->map(fn (Evaluation $assessment): object => (object) [
                'evaluation' => $assessment,
                'assessed_at' => $assessment->submitted_at ?? CarbonImmutable::parse((string) $assessment->created_at, 'UTC'),
            ]);

        $latest = [];
        foreach ($assessments as $assessment) {
            $period = SixFactorEvaluation::periodMonthFor($assessment->assessed_at);
            $latest[$period] ??= $assessment;
        }

        return $latest;
    }

    /**
     * @return array<string, mixed>
     */
    private function sixFactorRow(?SixFactorEvaluation $evaluation): array
    {
        if ($evaluation === null) {
            return ['status' => 'data_gap', 'evaluation_id' => null, 'total_score' => null, 'category' => null, 'finalized_at' => null, 'scores' => null];
        }

        return [
            'status' => 'available',
            'evaluation_id' => $evaluation->id,
            'total_score' => $evaluation->total_score,
            'category' => $evaluation->category,
            'finalized_at' => $this->utcIso($evaluation->finalized_at),
            'scores' => $evaluation->only(SixFactorEvaluation::FACTORS),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function threeDimensionRow(?object $assessment): array
    {
        if ($assessment === null) {
            return ['status' => 'data_gap', 'evaluation_id' => null, 'average_score' => null, 'category' => null, 'is_complete' => false, 'assessed_at' => null, 'scores' => null];
        }

        $model = $assessment->evaluation;
        $scores = ['ins' => $model->ins_score, 'ect' => $model->ect_score, 'ops' => $model->ops_score];

        return [
            'status' => 'available',
            'evaluation_id' => $model->id,
            'average_score' => $model->average_score,
            'category' => $model->category,
            'is_complete' => ! in_array(null, $scores, true),
            'assessed_at' => $this->utcIso($assessment->assessed_at),
            'scores' => $scores,
        ];
    }

    private function periodOf(CarbonImmutable $instant): string
    {
        return SixFactorEvaluation::periodMonthFor($instant);
    }

    private function utcIso(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
