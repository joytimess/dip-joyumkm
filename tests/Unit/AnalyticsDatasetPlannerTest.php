<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\SixFactorEvaluation;
use App\Services\Analytics\AnalyticsDatasetPlanner;
use App\Services\Analytics\DeterministicRandom;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AnalyticsDatasetPlannerTest extends TestCase
{
    private const START_MONTH = '2025-11';

    /** @var array<string, mixed>|null */
    private static ?array $plan = null;

    /**
     * @return list<array{code: string, scale_min: int, scale_max: int, questions: list<array{id: int, values: list<int>}>}>
     */
    private function factors(): array
    {
        $id = 1;
        $build = function (string $code, int $min, int $max, int $count) use (&$id): array {
            $questions = [];
            for ($index = 0; $index < $count; $index++) {
                $questions[] = ['id' => $id++, 'values' => range($min, $max)];
            }

            return ['code' => $code, 'scale_min' => $min, 'scale_max' => $max, 'questions' => $questions];
        };

        return [$build('INS', 1, 3, 6), $build('ECT', 1, 5, 4), $build('OPS', 1, 5, 6)];
    }

    /**
     * @return array{months: list<string>, account_created_at: CarbonImmutable, umkms: list<array<string, mixed>>}
     */
    private function plan(string $seed = 'joyumkm-analytics-v1'): array
    {
        if ($seed === 'joyumkm-analytics-v1' && self::$plan !== null) {
            return self::$plan;
        }

        $plan = (new AnalyticsDatasetPlanner(new DeterministicRandom($seed)))->plan(self::START_MONTH, $this->factors());
        if ($seed === 'joyumkm-analytics-v1') {
            self::$plan = $plan;
        }

        return $plan;
    }

    public function test_plan_matches_the_umkm_sector_and_city_contract(): void
    {
        $umkms = $this->plan()['umkms'];

        $this->assertCount(428, $umkms);
        $sectorCounts = array_count_values(array_column($umkms, 'sector_slug'));
        ksort($sectorCounts);
        $expectedSectors = AnalyticsDatasetPlanner::SECTOR_COUNTS;
        ksort($expectedSectors);
        $this->assertSame($expectedSectors, $sectorCounts);

        $cityCounts = array_count_values(array_map(fn (array $umkm): string => $umkm['city'].'|'.$umkm['province'], $umkms));
        ksort($cityCounts);
        $expected = [];
        foreach (AnalyticsDatasetPlanner::CITY_COUNTS as $row) {
            $expected[$row['city'].'|'.$row['province']] = $row['count'];
        }
        ksort($expected);
        $this->assertSame($expected, $cityCounts);
    }

    public function test_every_umkm_has_three_distinct_months_and_each_month_gets_107_per_type(): void
    {
        $plan = $this->plan();
        $threeMonths = [];
        $sixMonths = [];
        foreach ($plan['umkms'] as $umkm) {
            $threeMonths[] = array_map(fn (array $evaluation): int => $evaluation['month_index'], $umkm['three']);
            $sixMonths[] = array_map(fn (array $evaluation): int => $evaluation['month_index'], $umkm['six']);
        }

        foreach ([$threeMonths, $sixMonths] as $perUmkm) {
            foreach ($perUmkm as $months) {
                $this->assertCount(3, array_unique($months));
            }
        }

        $perMonth = array_fill(0, 12, 0);
        foreach ($threeMonths as $months) {
            foreach ($months as $month) {
                $perMonth[$month]++;
            }
        }
        $this->assertSame(array_fill(0, 12, AnalyticsDatasetPlanner::EVALUATIONS_PER_TYPE_PER_MONTH), $perMonth);
        $this->assertCount(12, $plan['months']);
        $this->assertSame('2025-11', $plan['months'][0]);
        $this->assertSame('2026-10', $plan['months'][11]);
    }

    public function test_six_factor_totals_cover_6_to_30_and_match_the_category_target(): void
    {
        $totals = [];
        foreach ($this->plan()['umkms'] as $umkm) {
            foreach ($umkm['six'] as $evaluation) {
                $totals[] = $evaluation['total'];
                $this->assertSame($evaluation['total'], array_sum($evaluation['factors']));
                foreach ($evaluation['factors'] as $score) {
                    $this->assertGreaterThanOrEqual(1, $score);
                    $this->assertLessThanOrEqual(5, $score);
                }
            }
        }

        $this->assertSame(range(6, 30), array_values(array_unique(array_intersect(range(6, 30), $totals))));
        $categories = array_count_values(array_map(fn (int $total): string => SixFactorEvaluation::categoryForTotal($total), $totals));
        ksort($categories);
        $expectedCategories = AnalyticsDatasetPlanner::expectedSixFactorCategoryCounts();
        ksort($expectedCategories);
        $this->assertSame($expectedCategories, $categories);
    }

    public function test_timestamps_are_wib_month_aligned_ordered_and_never_in_the_future(): void
    {
        $nowTimestamp = CarbonImmutable::now('UTC')->getTimestamp();
        $wibVersusUtcMonthDiffers = 0;

        foreach ($this->plan()['umkms'] as $umkm) {
            foreach ($umkm['three'] as $index => $three) {
                $six = $umkm['six'][$index];

                $this->assertSame(
                    SixFactorEvaluation::periodMonthFor($three['submitted_at']),
                    SixFactorEvaluation::periodMonthFor($six['finalized_at']),
                    'Tiga Dimensi dan Enam Faktor harus berada pada bulan WIB yang sama.',
                );
                $this->assertLessThanOrEqual(
                    $six['finalized_at']->getTimestamp(),
                    $three['submitted_at']->getTimestamp(),
                    'Tiga Dimensi harus tersedia pada atau sebelum finalisasi Enam Faktor.',
                );
                $this->assertLessThan($nowTimestamp, $six['finalized_at']->getTimestamp());
                $this->assertLessThan($six['finalized_at']->getTimestamp(), $six['created_at']->getTimestamp());

                if (SixFactorEvaluation::periodMonthFor($six['finalized_at']) !== $six['finalized_at']->utc()->format('Y-m')) {
                    $wibVersusUtcMonthDiffers++;
                }
            }
        }

        $this->assertGreaterThan(0, $wibVersusUtcMonthDiffers, 'Kasus batas bulan WIB (UTC berbeda bulan) harus direncanakan.');
    }

    public function test_plan_is_deterministic_for_the_same_seed_and_changes_for_another_seed(): void
    {
        $first = $this->plan('joyumkm-analytics-v1');
        $second = (new AnalyticsDatasetPlanner(new DeterministicRandom('joyumkm-analytics-v1')))->plan(self::START_MONTH, $this->factors());
        $other = (new AnalyticsDatasetPlanner(new DeterministicRandom('joyumkm-analytics-other')))->plan(self::START_MONTH, $this->factors());

        $this->assertSame(
            array_map(fn (array $umkm): array => [$umkm['name'], $umkm['created_at']->toIso8601String(), $umkm['six'][0]['total']], $first['umkms']),
            array_map(fn (array $umkm): array => [$umkm['name'], $umkm['created_at']->toIso8601String(), $umkm['six'][0]['total']], $second['umkms']),
        );
        $this->assertNotSame(
            array_column($first['umkms'], 'sector_slug'),
            array_column($other['umkms'], 'sector_slug'),
        );
    }

    public function test_answers_cover_every_active_question_with_a_valid_option_value(): void
    {
        $factors = $this->factors();
        $questionIds = [];
        foreach ($factors as $factor) {
            foreach ($factor['questions'] as $question) {
                $questionIds[] = $question['id'];
            }
        }

        foreach (array_slice($this->plan()['umkms'], 0, 50) as $umkm) {
            foreach ($umkm['three'] as $evaluation) {
                $this->assertSame($questionIds, array_keys($evaluation['answers']));
                foreach ($factors as $factor) {
                    foreach ($factor['questions'] as $question) {
                        $this->assertContains($evaluation['answers'][$question['id']], $question['values']);
                    }
                }
            }
        }
    }

    public function test_trends_up_down_and_stable_are_all_represented(): void
    {
        $trends = array_count_values(array_column($this->plan()['umkms'], 'trend'));

        $this->assertGreaterThan(0, $trends['up'] ?? 0);
        $this->assertGreaterThan(0, $trends['down'] ?? 0);
        $this->assertGreaterThan(0, $trends['stable'] ?? 0);
    }
}
