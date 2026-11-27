<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\SixFactorEvaluation;
use App\Services\ScoringService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Verifikasi integritas cohort dari data yang tersimpan (bukan dari label generator).
 *
 * @phpstan-import-type FactorMeta from AnalyticsDatasetPlanner
 *
 * @phpstan-type CheckResult array{name: string, expected: mixed, actual: mixed, pass: bool}
 * @phpstan-type InspectionReport array{passed: bool, failures: list<string>, checks: list<CheckResult>, fingerprint: string, counts: array{umkm: int, three_dimension: int, six_factor: int, answer: int}}
 */
final class AnalyticsDatasetVerifier
{
    private const SCORE_TOLERANCE = 0.00005;

    /** Jumlah UMKM yang diharapkan pada inspeksi berjalan (428 untuk v1, 458 untuk v2). */
    private int $expectedCount = AnalyticsDatasetPlanner::UMKM_COUNT;

    public function __construct(private readonly ScoringService $scoring) {}

    /**
     * Inspeksi invarian seed awal. Anggota dipilih berdasarkan keanggotaan cohort (daftar ID UMKM), bukan pemilik.
     *
     * @param  list<int>  $umkmIds  ID UMKM anggota cohort.
     * @param  list<FactorMeta>  $factors
     * @param  list<string>  $months  Periode referensi (Y-m, WIB), tepat 12 bulan.
     * @return InspectionReport
     */
    public function inspect(ConnectionInterface $connection, array $umkmIds, string $email, array $factors, array $months, CarbonImmutable $now): array
    {
        $this->expectedCount = count($umkmIds);
        $umkms = $connection->table('umkms')
            ->leftJoin('sectors', 'sectors.id', '=', 'umkms.sector_id')
            ->whereIn('umkms.id', $umkmIds)
            ->orderBy('umkms.id')
            ->get(['umkms.id', 'umkms.name', 'umkms.province', 'umkms.city', 'umkms.omzet', 'umkms.omzet_period',
                'umkms.business_capital', 'umkms.year_founded', 'umkms.employee_count', 'umkms.created_at',
                'umkms.whatsapp_number', 'umkms.business_email', 'umkms.notification_consent_at',
                'umkms.wa_registration_status', 'umkms.wa_checked_at', 'sectors.slug as sector_slug'])
            ->all();

        $threeRows = $connection->table('evaluations')
            ->join('umkms', 'umkms.id', '=', 'evaluations.umkm_id')
            ->whereIn('umkms.id', $umkmIds)
            ->orderBy('evaluations.id')
            ->get(['evaluations.id', 'evaluations.umkm_id', 'evaluations.status', 'evaluations.ins_score',
                'evaluations.ect_score', 'evaluations.ops_score', 'evaluations.average_score', 'evaluations.category',
                'evaluations.submitted_at', 'evaluations.created_at'])
            ->all();

        $answerRows = $connection->table('evaluation_answers')
            ->join('evaluations', 'evaluations.id', '=', 'evaluation_answers.evaluation_id')
            ->join('umkms', 'umkms.id', '=', 'evaluations.umkm_id')
            ->join('questions', 'questions.id', '=', 'evaluation_answers.question_id')
            ->join('factors', 'factors.id', '=', 'questions.factor_id')
            ->whereIn('umkms.id', $umkmIds)
            ->get(['evaluation_answers.evaluation_id', 'evaluation_answers.question_id', 'evaluation_answers.value',
                'questions.is_active', 'factors.code', 'factors.scale_min', 'factors.scale_max'])
            ->all();

        $sixRows = $connection->table('six_factor_evaluations')
            ->join('umkms', 'umkms.id', '=', 'six_factor_evaluations.umkm_id')
            ->whereIn('umkms.id', $umkmIds)
            ->orderBy('six_factor_evaluations.id')
            ->select('six_factor_evaluations.*')
            ->get()
            ->all();

        $sixIds = array_map(fn (object $row): int => (int) $row->id, $sixRows);
        $outboxCount = $sixIds === [] ? 0 : $connection->table('webhook_outbox')->whereIn('aggregate_id', $sixIds)->count();
        $jobCount = $connection->table('jobs')->where('payload', 'like', '%'.$email.'%')->count();

        $umkmNameById = [];
        foreach ($umkms as $umkm) {
            $umkmNameById[(int) $umkm->id] = (string) $umkm->name;
        }

        $checks = [];
        $this->checkUmkms($checks, $umkms, $factors);
        $answersByEvaluation = $this->groupAnswers($answerRows);
        $this->checkThreeDimension($checks, $threeRows, $answersByEvaluation, $factors, $months, $umkmNameById);
        $this->checkSixFactor($checks, $sixRows, $months, $umkmNameById);
        $this->checkAlignmentAndOrder($checks, $threeRows, $sixRows, $umkms);
        $this->checkTimeline($checks, $umkms, $threeRows, $sixRows, $now);
        $checks[] = $this->check('side_effect_outbox_rows_for_cohort', 0, $outboxCount);
        $checks[] = $this->check('side_effect_job_rows_for_cohort', 0, $jobCount);

        $fingerprint = $this->fingerprint($umkms, $threeRows, $answersByEvaluation, $sixRows, $umkmNameById);
        $failures = array_values(array_map(
            fn (array $check): string => $check['name'],
            array_filter($checks, fn (array $check): bool => ! $check['pass']),
        ));

        return [
            'passed' => $failures === [],
            'failures' => $failures,
            'checks' => $checks,
            'fingerprint' => $fingerprint,
            'counts' => [
                'umkm' => count($umkms),
                'three_dimension' => count($threeRows),
                'six_factor' => count($sixRows),
                'answer' => count($answerRows),
            ],
        ];
    }

    /**
     * Pemeriksaan operasional cohort selesai: hanya keanggotaan dan keberadaan riwayat awal. Tidak membandingkan
     * pemilik, nama, sidik jari, atau jumlah evaluasi, sehingga perubahan sah (transfer, evaluasi baru) tidak terbaca sebagai drift.
     *
     * @return list<string> Daftar masalah; kosong berarti konsisten.
     */
    public function inspectMembership(ConnectionInterface $connection, int $cohortId, int $expectedCount): array
    {
        $problems = [];
        $members = $connection->table('analytics_dataset_members')->where('analytics_dataset_cohort_id', $cohortId)->get(['umkm_id', 'synthetic_key']);
        if ($members->count() !== $expectedCount) {
            $problems[] = "anggota cohort {$members->count()}, tercatat {$expectedCount}";
        }
        if ($members->pluck('synthetic_key')->unique()->count() !== $members->count()) {
            $problems[] = 'synthetic_key tidak unik';
        }

        $ids = $members->pluck('umkm_id')->all();
        $existing = $connection->table('umkms')->whereIn('id', $ids)->count();
        if ($existing !== count($ids)) {
            $problems[] = 'ada anggota tanpa UMKM (orphan): '.(count($ids) - $existing);
        }
        $threeComplete = $connection->table('evaluations')->whereIn('umkm_id', $ids)->where('status', 'final')
            ->select('umkm_id')->groupBy('umkm_id')->havingRaw('COUNT(*) >= 3')->get()->count();
        $sixComplete = $connection->table('six_factor_evaluations')->whereIn('umkm_id', $ids)->where('status', SixFactorEvaluation::FINAL)
            ->select('umkm_id')->groupBy('umkm_id')->havingRaw('COUNT(*) >= 3')->get()->count();
        if ($threeComplete !== count($ids) || $sixComplete !== count($ids)) {
            $problems[] = "riwayat awal tidak lengkap (3D {$threeComplete}, 6F {$sixComplete} dari ".count($ids).')';
        }

        return $problems;
    }

    /**
     * @param  InspectionReport  $report
     *
     * @throws AnalyticsDatasetException
     */
    public function assertPassed(array $report): void
    {
        if (! $report['passed']) {
            throw AnalyticsDatasetException::because('Verifikasi integritas dataset gagal: '.implode(', ', $report['failures']).'.');
        }
    }

    /**
     * @param  list<CheckResult>  $checks
     * @param  list<object>  $umkms
     * @param  list<FactorMeta>  $factors
     */
    private function checkUmkms(array &$checks, array $umkms, array $factors): void
    {
        $checks[] = $this->check('umkm_count', $this->expectedCount, count($umkms));

        $cityActual = [];
        $sectorActual = [];
        $names = [];
        $contactClean = 0;
        foreach ($umkms as $umkm) {
            $cityKey = $umkm->city.'|'.$umkm->province;
            $cityActual[$cityKey] = ($cityActual[$cityKey] ?? 0) + 1;
            $sectorActual[$umkm->sector_slug] = ($sectorActual[$umkm->sector_slug] ?? 0) + 1;
            $names[mb_strtolower((string) $umkm->name)] = true;
            if ($umkm->whatsapp_number === null && $umkm->business_email === null && $umkm->notification_consent_at === null
                && $umkm->wa_registration_status === 'unchecked' && $umkm->wa_checked_at === null) {
                $contactClean++;
            }
        }

        $cityExpected = AnalyticsDatasetPlanner::expectedCityCounts($this->expectedCount);
        ksort($cityExpected);
        ksort($cityActual);
        $checks[] = $this->check('umkm_city_province_distribution', $cityExpected, $cityActual);

        $sectorExpected = AnalyticsDatasetPlanner::expectedSectorCounts($this->expectedCount);
        ksort($sectorExpected);
        ksort($sectorActual);
        $checks[] = $this->check('umkm_sector_distribution', $sectorExpected, $sectorActual);
        $checks[] = $this->check('umkm_business_identity_unique', $this->expectedCount, count($names));
        $checks[] = $this->check('umkm_no_contact_or_consent_or_wa_request', count($umkms), $contactClean);
    }

    /**
     * @param  list<CheckResult>  $checks
     * @param  list<object>  $threeRows
     * @param  array<int, list<object>>  $answersByEvaluation
     * @param  list<FactorMeta>  $factors
     * @param  list<string>  $months
     * @param  array<int, string>  $umkmNameById
     */
    private function checkThreeDimension(array &$checks, array $threeRows, array $answersByEvaluation, array $factors, array $months, array $umkmNameById): void
    {
        $expectedCount = $this->expectedCount * 3;
        $checks[] = $this->check('three_dimension_count', $expectedCount, count($threeRows));
        $checks[] = $this->check('three_dimension_all_final', $expectedCount, count(array_filter($threeRows, fn (object $row): bool => $row->status === 'final' && $row->submitted_at !== null)));

        $perMonth = array_fill_keys($months, 0);
        $monthsByUmkm = [];
        foreach ($threeRows as $row) {
            $month = $this->wibMonth((string) $row->submitted_at);
            $perMonth[$month] = ($perMonth[$month] ?? 0) + 1;
            $monthsByUmkm[(int) $row->umkm_id][] = $month;
        }
        $checks[] = $this->check('three_dimension_per_month_per_type', AnalyticsDatasetPlanner::expectedPerMonthCounts($this->expectedCount, $months), $perMonth);
        $checks[] = $this->check('three_dimension_three_distinct_months_per_umkm', $this->expectedCount, count(array_filter(
            $monthsByUmkm,
            fn (array $list): bool => count($list) === 3 && count(array_unique($list)) === 3,
        )));

        $answerCount = 0;
        $validAnswers = true;
        $activeQuestionsPerEvaluation = array_sum(array_map(fn (array $factor): int => count($factor['questions'] ?? []), $factors));
        $expectedPerEvaluation = 16;
        $shapeOk = true;
        $recomputeMismatch = 0;
        $categoriesSeen = [];

        foreach ($threeRows as $row) {
            $answers = $answersByEvaluation[(int) $row->id] ?? [];
            $answerCount += count($answers);
            $questionIds = array_map(fn (object $answer): int => (int) $answer->question_id, $answers);
            if (count($answers) !== $expectedPerEvaluation || count(array_unique($questionIds)) !== count($questionIds)) {
                $shapeOk = false;
            }
            $valuesByCode = [];
            foreach ($answers as $answer) {
                if ((int) $answer->is_active !== 1 || (int) $answer->value < (int) $answer->scale_min || (int) $answer->value > (int) $answer->scale_max) {
                    $validAnswers = false;
                }
                $valuesByCode[$answer->code][] = (int) $answer->value;
            }

            $scoringFactors = array_map(fn (array $factor): array => ['code' => $factor['code'], 'scale_min' => $factor['scale_min'], 'scale_max' => $factor['scale_max']], $factors);
            $recomputed = $this->scoring->calculate($scoringFactors, $valuesByCode);
            $expectedScores = [
                (float) $row->ins_score - (float) ($recomputed['factor_scores']['INS'] ?? NAN),
                (float) $row->ect_score - (float) ($recomputed['factor_scores']['ECT'] ?? NAN),
                (float) $row->ops_score - (float) ($recomputed['factor_scores']['OPS'] ?? NAN),
                (float) $row->average_score - (float) ($recomputed['average_score'] ?? NAN),
            ];
            $mismatch = $row->category !== $recomputed['category'] || count(array_filter(
                $expectedScores,
                fn (float $difference): bool => is_nan($difference) || abs($difference) > self::SCORE_TOLERANCE,
            )) > 0;
            if ($mismatch) {
                $recomputeMismatch++;
            }
            $categoriesSeen[$row->category] = true;
        }

        $checks[] = $this->check('three_dimension_answer_count', $this->expectedCount * 3 * $expectedPerEvaluation, $answerCount);
        $checks[] = $this->check('three_dimension_answers_shape_all_active_questions', true, $shapeOk && $activeQuestionsPerEvaluation === $expectedPerEvaluation);
        $checks[] = $this->check('three_dimension_answer_values_valid', true, $validAnswers);
        $checks[] = $this->check('three_dimension_scores_recomputed_match', 0, $recomputeMismatch);

        $expectedCategories = array_map(fn (array $category): string => $category['label'], config('umkm.scoring.categories'));
        $checks[] = $this->check('three_dimension_categories_attainable_covered', count($expectedCategories), count(array_intersect($expectedCategories, array_keys($categoriesSeen))));
    }

    /**
     * @param  list<CheckResult>  $checks
     * @param  list<object>  $sixRows
     * @param  list<string>  $months
     * @param  array<int, string>  $umkmNameById
     */
    private function checkSixFactor(array &$checks, array $sixRows, array $months, array $umkmNameById): void
    {
        $checks[] = $this->check('six_factor_count', $this->expectedCount * 3, count($sixRows));
        $checks[] = $this->check('six_factor_all_final_no_draft_or_cancelled', $this->expectedCount * 3, count(array_filter(
            $sixRows,
            fn (object $row): bool => $row->status === SixFactorEvaluation::FINAL && $row->cancelled_at === null && $row->finalized_at !== null,
        )));

        $factorCodes = SixFactorEvaluation::FACTORS;
        $validFactors = true;
        $totalsOk = true;
        $categoryOk = true;
        $periodOk = true;
        $totals = [];
        $categoryCounts = [];
        $perMonth = array_fill_keys($months, 0);
        foreach ($sixRows as $row) {
            $factorValues = array_map(fn (string $code): int => (int) $row->{$code}, $factorCodes);
            if (count(array_filter($factorValues, fn (int $value): bool => $value < 1 || $value > 5)) > 0) {
                $validFactors = false;
            }
            $sum = array_sum($factorValues);
            if ((int) $row->total_score !== $sum) {
                $totalsOk = false;
            }
            $category = SixFactorEvaluation::categoryForTotal($sum);
            if ($row->category !== $category) {
                $categoryOk = false;
            }
            $finalizedAt = CarbonImmutable::parse((string) $row->finalized_at, 'UTC');
            if ($row->period_month !== SixFactorEvaluation::periodMonthFor($finalizedAt)) {
                $periodOk = false;
            }
            $totals[$sum] = true;
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            $perMonth[$row->period_month] = ($perMonth[$row->period_month] ?? 0) + 1;
        }

        $checks[] = $this->check('six_factor_values_valid_1_to_5', true, $validFactors);
        $checks[] = $this->check('six_factor_total_equals_sum', true, $totalsOk);
        $checks[] = $this->check('six_factor_category_matches_total', true, $categoryOk);
        $checks[] = $this->check('six_factor_period_month_matches_wib', true, $periodOk);
        $checks[] = $this->check('six_factor_per_month_per_type', AnalyticsDatasetPlanner::expectedPerMonthCounts($this->expectedCount, $months), $perMonth);
        $checks[] = $this->check('six_factor_total_range_6_to_30_covered', 25, count(array_filter(
            array_keys($totals),
            fn (int $total): bool => $total >= 6 && $total <= 30,
        )));

        $expectedCategories = AnalyticsDatasetPlanner::expectedSixFactorCategoryCounts($this->expectedCount);
        ksort($expectedCategories);
        ksort($categoryCounts);
        $checks[] = $this->check('six_factor_category_distribution_target', $expectedCategories, $categoryCounts);
    }

    /**
     * @param  list<CheckResult>  $checks
     * @param  list<object>  $threeRows
     * @param  list<object>  $sixRows
     * @param  list<object>  $umkms
     */
    private function checkAlignmentAndOrder(array &$checks, array $threeRows, array $sixRows, array $umkms): void
    {
        $threeByUmkm = [];
        foreach ($threeRows as $row) {
            $threeByUmkm[(int) $row->umkm_id][] = $row;
        }
        $sixByUmkm = [];
        foreach ($sixRows as $row) {
            $sixByUmkm[(int) $row->umkm_id][] = $row;
        }

        $aligned = 0;
        $orderOk = 0;
        $latestThreeOk = 0;
        $latestSixOk = 0;
        foreach ($umkms as $umkm) {
            $id = (int) $umkm->id;
            $three = $threeByUmkm[$id] ?? [];
            $six = $sixByUmkm[$id] ?? [];
            if (count($three) !== 3 || count($six) !== 3) {
                continue;
            }

            $threeMonths = array_map(fn (object $row): string => $this->wibMonth((string) $row->submitted_at), $three);
            $sixMonths = array_map(fn (object $row): string => SixFactorEvaluation::periodMonthFor(CarbonImmutable::parse((string) $row->finalized_at, 'UTC')), $six);
            $pairsOk = true;
            foreach ($six as $index => $sixRow) {
                $matchIndex = array_search($sixMonths[$index], $threeMonths, true);
                if ($matchIndex === false || CarbonImmutable::parse((string) $three[$matchIndex]->submitted_at, 'UTC')
                    > CarbonImmutable::parse((string) $sixRow->finalized_at, 'UTC')) {
                    $pairsOk = false;
                }
            }
            if ($pairsOk) {
                $aligned++;
            }

            $threeSorted = $three;
            usort($threeSorted, fn (object $a, object $b): int => $a->id <=> $b->id);
            $sixSorted = $six;
            usort($sixSorted, fn (object $a, object $b): int => $a->id <=> $b->id);
            $threeTimestamps = array_map(fn (object $row): string => (string) $row->submitted_at, $threeSorted);
            $sixTimestamps = array_map(fn (object $row): string => (string) $row->finalized_at, $sixSorted);
            if ($this->isAscending($threeTimestamps) && $this->isAscending($sixTimestamps)) {
                $orderOk++;
            }

            $latestThree = end($threeSorted);
            if ($this->wibMonth((string) $latestThree->submitted_at) === max($threeMonths)) {
                $latestThreeOk++;
            }
            $latestSix = $this->latestFinal($six);
            if (SixFactorEvaluation::periodMonthFor(CarbonImmutable::parse((string) $latestSix->finalized_at, 'UTC')) === max($sixMonths)) {
                $latestSixOk++;
            }
        }

        $checks[] = $this->check('aligned_histories_both_types_same_months', $this->expectedCount, $aligned);
        $checks[] = $this->check('chronological_order_per_umkm_per_type', $this->expectedCount, $orderOk);
        $checks[] = $this->check('latest_three_dimension_is_latest_month', $this->expectedCount, $latestThreeOk);
        $checks[] = $this->check('latest_six_factor_is_latest_month', $this->expectedCount, $latestSixOk);

        $trendCounts = ['up' => 0, 'down' => 0, 'stable' => 0];
        foreach ($sixByUmkm as $list) {
            usort($list, fn (object $a, object $b): int => CarbonImmutable::parse((string) $a->finalized_at, 'UTC')->getTimestamp()
                <=> CarbonImmutable::parse((string) $b->finalized_at, 'UTC')->getTimestamp());
            $delta = (int) $list[2]->total_score - (int) $list[0]->total_score;
            $trendCounts[$delta >= 2 ? 'up' : ($delta <= -2 ? 'down' : 'stable')]++;
        }
        $checks[] = $this->check('trend_up_down_stable_represented', true, $trendCounts['up'] > 0 && $trendCounts['down'] > 0 && $trendCounts['stable'] > 0);
    }

    /**
     * @param  list<CheckResult>  $checks
     * @param  list<object>  $umkms
     * @param  list<object>  $threeRows
     * @param  list<object>  $sixRows
     */
    private function checkTimeline(array &$checks, array $umkms, array $threeRows, array $sixRows, CarbonImmutable $now): void
    {
        $future = 0;
        foreach (array_merge(
            array_map(fn (object $row): ?string => $row->created_at, $umkms),
            array_map(fn (object $row): ?string => $row->submitted_at, $threeRows),
            array_map(fn (object $row): ?string => $row->finalized_at, $sixRows),
        ) as $timestamp) {
            if ($timestamp !== null && CarbonImmutable::parse($timestamp, 'UTC')->greaterThanOrEqualTo($now)) {
                $future++;
            }
        }
        $checks[] = $this->check('no_future_timestamps', 0, $future);
    }

    /**
     * @param  list<object>  $answerRows
     * @return array<int, list<object>>
     */
    private function groupAnswers(array $answerRows): array
    {
        $grouped = [];
        foreach ($answerRows as $row) {
            $grouped[(int) $row->evaluation_id][] = $row;
        }

        return $grouped;
    }

    /**
     * Sidik jari konten tanpa ID: sama untuk seed, versi, dan periode yang sama.
     *
     * @param  list<object>  $umkms
     * @param  list<object>  $threeRows
     * @param  array<int, list<object>>  $answersByEvaluation
     * @param  list<object>  $sixRows
     * @param  array<int, string>  $umkmNameById
     */
    private function fingerprint(array $umkms, array $threeRows, array $answersByEvaluation, array $sixRows, array $umkmNameById): string
    {
        $lines = [];
        foreach ($umkms as $umkm) {
            $lines[] = implode('|', ['U', $umkm->name, $umkm->sector_slug, $umkm->province, $umkm->city, $umkm->omzet,
                $umkm->omzet_period, $umkm->business_capital, $umkm->year_founded, $umkm->employee_count, $umkm->created_at]);
        }
        foreach ($threeRows as $row) {
            $answers = $answersByEvaluation[(int) $row->id] ?? [];
            usort($answers, fn (object $a, object $b): int => $a->question_id <=> $b->question_id);
            $lines[] = implode('|', ['T', $umkmNameById[(int) $row->umkm_id], $row->submitted_at, $row->ins_score, $row->ect_score,
                $row->ops_score, $row->average_score, $row->category, implode(',', array_map(fn (object $answer): int => (int) $answer->value, $answers))]);
        }
        foreach ($sixRows as $row) {
            $lines[] = implode('|', ['S', $umkmNameById[(int) $row->umkm_id], $row->finalized_at, $row->sumber_daya, $row->kepemimpinan,
                $row->lingkungan_kerja, $row->nilai_organisasi, $row->stabilitas, $row->kinerja, $row->total_score, $row->category, $row->period_month]);
        }
        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    /** @return CheckResult */
    private function check(string $name, mixed $expected, mixed $actual): array
    {
        return ['name' => $name, 'expected' => $expected, 'actual' => $actual, 'pass' => $expected === $actual];
    }

    private function wibMonth(string $utcTimestamp): string
    {
        return SixFactorEvaluation::periodMonthFor(CarbonImmutable::parse($utcTimestamp, 'UTC'));
    }

    /**
     * @param  list<object>  $rows
     */
    private function latestFinal(array $rows): object
    {
        usort($rows, fn (object $a, object $b): int => [CarbonImmutable::parse((string) $a->finalized_at, 'UTC')->getTimestamp(), $a->id]
            <=> [CarbonImmutable::parse((string) $b->finalized_at, 'UTC')->getTimestamp(), $b->id]);

        return end($rows);
    }

    /**
     * Timestamp UTC format Y-m-d H:i:s urut secara leksikografis sama dengan urut kronologis.
     *
     * @param  list<string>  $timestamps
     */
    private function isAscending(array $timestamps): bool
    {
        $sorted = $timestamps;
        sort($sorted, SORT_STRING);

        return $timestamps === $sorted;
    }
}
