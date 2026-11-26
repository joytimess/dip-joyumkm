<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\SixFactorEvaluation;
use Carbon\CarbonImmutable;

/**
 * Perencanaan murni (tanpa database dan tanpa clock). Hasilnya deterministik untuk seed, versi, dan periode yang sama.
 *
 * @phpstan-type FactorMeta array{code: string, scale_min: int, scale_max: int, questions: list<array{id: int, values: list<int>}>}
 * @phpstan-type SixFactorPlan array{month_index: int, total: int, factors: array<string, int>, finalized_at: CarbonImmutable, created_at: CarbonImmutable}
 * @phpstan-type ThreeDimensionPlan array{month_index: int, submitted_at: CarbonImmutable, answers: array<int, int>}
 * @phpstan-type UmkmPlan array{index: int, name: string, owner_name: string, description: string, sector_slug: string, province: string, city: string, omzet: int, omzet_period: string, business_capital: int, year_founded: int, employee_count: int, created_at: CarbonImmutable, trend: string, three: list<ThreeDimensionPlan>, six: list<SixFactorPlan>}
 */
final class AnalyticsDatasetPlanner
{
    public const UMKM_COUNT = 428;

    public const MONTH_COUNT = 12;

    public const EVALUATIONS_PER_TYPE_PER_MONTH = 107;

    public const PERIOD_TIMEZONE = 'Asia/Jakarta';

    /** @var array<string, int> */
    public const SECTOR_COUNTS = [
        'makanan-minuman' => 120,
        'grosir-retail' => 100,
        'jasa' => 85,
        'fashion-kriya' => 75,
        'pertanian' => 48,
    ];

    /** @var list<array{city: string, province: string, count: int}> */
    public const CITY_COUNTS = [
        ['city' => 'Jakarta Selatan', 'province' => 'DKI Jakarta', 'count' => 43],
        ['city' => 'Jakarta Timur', 'province' => 'DKI Jakarta', 'count' => 43],
        ['city' => 'Bandung', 'province' => 'Jawa Barat', 'count' => 43],
        ['city' => 'Bekasi', 'province' => 'Jawa Barat', 'count' => 43],
        ['city' => 'Semarang', 'province' => 'Jawa Tengah', 'count' => 43],
        ['city' => 'Surakarta', 'province' => 'Jawa Tengah', 'count' => 43],
        ['city' => 'Surabaya', 'province' => 'Jawa Timur', 'count' => 43],
        ['city' => 'Malang', 'province' => 'Jawa Timur', 'count' => 43],
        ['city' => 'Tangerang', 'province' => 'Banten', 'count' => 42],
        ['city' => 'Tangerang Selatan', 'province' => 'Banten', 'count' => 42],
    ];

    /**
     * Rentang total Enam Faktor per kategori beserta jumlah evaluasi final target per jenis.
     *
     * @var list<array{lowest: int, highest: int, evaluations: int}>
     */
    public const SIX_FACTOR_BANDS = [
        ['lowest' => 6, 'highest' => 12, 'evaluations' => 360],
        ['lowest' => 13, 'highest' => 18, 'evaluations' => 309],
        ['lowest' => 19, 'highest' => 24, 'evaluations' => 308],
        ['lowest' => 25, 'highest' => 30, 'evaluations' => 307],
    ];

    /**
     * Pola blok pengurutan pool: F = blok stabil (stride 10), T = blok tren (stride 72).
     *
     * @var list<string>
     */
    private const TRIPLE_BLOCK_PATTERN = ['F', 'F', 'F', 'T', 'F', 'F', 'F', 'T', 'F', 'F', 'F', 'T', 'F', 'F', 'F', 'T', 'F', 'F'];

    private const FLAT_STRIDE = 10;

    private const TREND_STRIDE = 72;

    private const SECTOR_NOUNS = [
        'makanan-minuman' => ['Dapur', 'Warung', 'Kedai', 'Catering', 'Roti', 'Kopi', 'Sambal'],
        'grosir-retail' => ['Toko', 'Depot', 'Grosir', 'Distribusi', 'Mini Market'],
        'jasa' => ['Laundry', 'Bengkel', 'Konsultan', 'Servis', 'Percetakan'],
        'fashion-kriya' => ['Butik', 'Konveksi', 'Tenun', 'Kriya', 'Batik'],
        'pertanian' => ['Kebun', 'Tani', 'Peternakan', 'Hidroponik', 'Agro'],
    ];

    private const SECTOR_LABELS = [
        'makanan-minuman' => 'Makanan dan minuman',
        'grosir-retail' => 'Grosir dan retail',
        'jasa' => 'Jasa',
        'fashion-kriya' => 'Fashion dan kriya',
        'pertanian' => 'Pertanian',
    ];

    private const SECTOR_OMZET_FACTOR = [
        'makanan-minuman' => 1.0,
        'grosir-retail' => 1.6,
        'jasa' => 0.9,
        'fashion-kriya' => 0.8,
        'pertanian' => 1.2,
    ];

    private const BRAND_WORDS = [
        'Mekar', 'Sejahtera', 'Berkah', 'Nusantara', 'Lestari', 'Sumber', 'Maju', 'Cahaya', 'Pelangi', 'Mentari',
        'Sentosa', 'Harapan', 'Gemilang', 'Melati', 'Bintang', 'Anugerah', 'Cemara', 'Samudra', 'Rimba', 'Bumi',
        'Alam', 'Permata', 'Cendana', 'Kencana', 'Rahayu', 'Sinar', 'Wibawa', 'Kartika', 'Jaya', 'Sari',
        'Tirta', 'Puspa', 'Aurora', 'Garuda', 'Merdeka', 'Sejuk', 'Hijau', 'Kirana', 'Cakra', 'Sukma',
    ];

    public function __construct(private readonly DeterministicRandom $random) {}

    /**
     * @param  list<FactorMeta>  $factors  Master kuesioner aktif dalam urutan sort_order.
     * @return array{months: list<string>, account_created_at: CarbonImmutable, umkms: list<UmkmPlan>}
     */
    public function plan(string $referenceStartMonth, array $factors): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $referenceStartMonth, self::PERIOD_TIMEZONE);
        $months = [];
        for ($month = 0; $month < self::MONTH_COUNT; $month++) {
            $months[] = $start->addMonths($month)->format('Y-m');
        }

        $sectors = $this->shuffledExpansion(array_map(
            fn (string $slug, int $count): array => [$slug, $count],
            array_keys(self::SECTOR_COUNTS),
            array_values(self::SECTOR_COUNTS),
        ), 'sector');
        $cities = $this->shuffledExpansion(array_map(
            fn (array $row): array => [$row, $row['count']],
            self::CITY_COUNTS,
        ), 'city');
        $triples = $this->shuffledSixFactorTriples();

        $umkms = [];
        for ($index = 0; $index < self::UMKM_COUNT; $index++) {
            $city = $cities[$index];
            $sectorSlug = $sectors[$index];
            $trendTriple = $triples[$index];
            $umkms[] = $this->umkmPlan($index, $sectorSlug, $city, $start, $trendTriple, $factors);
        }

        return [
            'months' => $months,
            'account_created_at' => $this->wibDate($start->subMonth(), 1, 0, 0),
            'umkms' => $umkms,
        ];
    }

    /**
     * Mengembalikan kategori target per total (dipakai verifikasi, bukan generator).
     *
     * @return array<string, int>
     */
    public static function expectedSixFactorCategoryCounts(): array
    {
        $counts = [];
        foreach (self::SIX_FACTOR_BANDS as $band) {
            $counts[SixFactorEvaluation::categoryForTotal($band['lowest'])] = $band['evaluations'];
        }

        return $counts;
    }

    /**
     * @param  array{city: string, province: string}  $city
     * @param  list<FactorMeta>  $factors
     * @param  array{trend: string, totals: list<int>}  $trendTriple
     * @return UmkmPlan
     */
    private function umkmPlan(int $index, string $sectorSlug, array $city, CarbonImmutable $start, array $trendTriple, array $factors): array
    {
        $monthIndexes = $this->monthIndexesFor($index);
        $namePrefix = (string) $index;

        $omzetPeriod = $this->random->int(0, 9, 'omzet-period', $namePrefix) < 7 ? 'monthly' : 'yearly';
        $monthlyOmzet = (int) round($this->random->int(3_000_000, 60_000_000, 'omzet', $namePrefix)
            * self::SECTOR_OMZET_FACTOR[$sectorSlug] / 1000) * 1000;
        $omzet = $omzetPeriod === 'monthly' ? $monthlyOmzet : $monthlyOmzet * 12;

        $three = [];
        $six = [];
        foreach ($monthIndexes as $occurrence => $monthIndex) {
            $monthStart = $start->addMonths($monthIndex);
            $total = $trendTriple['totals'][$occurrence];

            [$submittedAt, $finalizedAt] = $this->timestampsFor($index, $occurrence, $monthStart);

            $six[] = [
                'month_index' => $monthIndex,
                'total' => $total,
                'factors' => $this->factorScoresForTotal($total, $index, $occurrence),
                'finalized_at' => $finalizedAt,
                'created_at' => $finalizedAt->subMinutes(30),
            ];
            $three[] = [
                'month_index' => $monthIndex,
                'submitted_at' => $submittedAt,
                'answers' => $this->answersForTotal($total, $factors, $index, $occurrence),
            ];
        }

        return [
            'index' => $index,
            'name' => $this->businessName($sectorSlug, $index),
            'owner_name' => 'Pemilik Sintetis '.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
            'description' => 'Profil sintetis untuk analitik JoyUMKM. '.self::SECTOR_LABELS[$sectorSlug].' di '.$city['city'].'.',
            'sector_slug' => $sectorSlug,
            'province' => $city['province'],
            'city' => $city['city'],
            'omzet' => $omzet,
            'omzet_period' => $omzetPeriod,
            'business_capital' => intdiv($this->random->int(5_000_000, 250_000_000, 'capital', $namePrefix), 500_000) * 500_000,
            'year_founded' => $this->random->int(2008, 2025, 'year-founded', $namePrefix),
            'employee_count' => $this->random->int(1, 25, 'employees', $namePrefix),
            'created_at' => $this->wibDate(
                $start->subMonth(),
                $this->random->int(1, 28, 'umkm-created-day', $namePrefix),
                $this->random->int(9, 16, 'umkm-created-hour', $namePrefix),
                $this->random->int(0, 59, 'umkm-created-minute', $namePrefix),
            ),
            'trend' => $trendTriple['trend'],
            'three' => $three,
            'six' => $six,
        ];
    }

    /**
     * Kuartal berulang: UMKM dengan kelas c memiliki evaluasi pada bulan c, c+4, dan c+8 (0-based).
     * Setiap kelas berisi 107 UMKM, sehingga setiap bulan menerima tepat 107 evaluasi per jenis.
     *
     * @return list<int>
     */
    private function monthIndexesFor(int $index): array
    {
        $class = $index % 4;

        return [$class, $class + 4, $class + 8];
    }

    /**
     * Waktu tersimpan bulan Tiga Dimensi (hari 1-14 WIB) dan Enam Faktor (hari 15-28 WIB) pada bulan yang sama.
     * Dua UMKM sengaja berada di batas bulan WIB untuk menguji konversi periode.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function timestampsFor(int $index, int $occurrence, CarbonImmutable $monthStart): array
    {
        if ($index % 37 === 0 && $occurrence === 0) {
            return [
                $this->wibDate($monthStart, 1, 0, 10),
                $this->wibDate($monthStart, 1, 0, 30),
            ];
        }

        $submittedDay = $this->random->int(1, 14, 'three-day', (string) $index, (string) $occurrence);
        $submittedAt = $this->wibDate($monthStart, $submittedDay, $this->random->int(8, 16, 'three-hour', (string) $index, (string) $occurrence),
            $this->random->int(0, 59, 'three-minute', (string) $index, (string) $occurrence));

        if ($index % 37 === 1 && $occurrence === 2) {
            $lastDay = $monthStart->endOfMonth()->day;

            return [$submittedAt, $this->wibDate($monthStart, $lastDay, 23, 30)];
        }

        $finalizedAt = $this->wibDate($monthStart, $this->random->int(15, 28, 'six-day', (string) $index, (string) $occurrence),
            $this->random->int(8, 16, 'six-hour', (string) $index, (string) $occurrence),
            $this->random->int(0, 59, 'six-minute', (string) $index, (string) $occurrence));

        return [$submittedAt, $finalizedAt];
    }

    /**
     * @return array<string, int>
     */
    private function factorScoresForTotal(int $total, int $index, int $occurrence): array
    {
        $codes = SixFactorEvaluation::FACTORS;
        $order = $this->random->shuffle($codes, 'six-order', (string) $index, (string) $occurrence);
        $values = array_fill_keys($codes, 1);
        $remaining = $total - count($codes);

        while ($remaining > 0) {
            foreach ($order as $code) {
                if ($remaining > 0 && $values[$code] < 5) {
                    $values[$code]++;
                    $remaining--;
                }
            }
        }

        return $values;
    }

    /**
     * @param  list<FactorMeta>  $factors
     * @return array<int, int>
     */
    private function answersForTotal(int $total, array $factors, int $index, int $occurrence): array
    {
        $baseScore = ($total - 6) * 100 / 24 + $this->random->int(-4, 4, 'three-noise', (string) $index, (string) $occurrence);
        $answers = [];

        foreach ($factors as $factor) {
            $questionCount = count($factor['questions']);
            $factorScore = max(0, min(100, $baseScore + $this->random->int(-8, 8, 'three-offset', (string) $index, (string) $occurrence, $factor['code'])));
            $range = $factor['scale_max'] - $factor['scale_min'];
            $sum = $factor['scale_min'] * $questionCount + (int) round($factorScore * $range * $questionCount / 100);
            $values = $this->distributeSum($sum, $questionCount, $factor['scale_min'], $factor['scale_max'], $index, $occurrence, $factor['code']);

            foreach ($factor['questions'] as $position => $question) {
                $value = $values[$position];
                if (! in_array($value, $question['values'], true)) {
                    throw AnalyticsDatasetException::because('Nilai jawaban yang dihasilkan tidak tersedia pada opsi pertanyaan.');
                }
                $answers[$question['id']] = $value;
            }
        }

        return $answers;
    }

    /**
     * @return list<int>
     */
    private function distributeSum(int $sum, int $count, int $minimum, int $maximum, int $index, int $occurrence, string $code): array
    {
        $base = intdiv($sum, $count);
        $remainder = $sum - $base * $count;
        $values = array_fill(0, $count, $base);

        $positions = $this->random->shuffle(range(0, $count - 1), 'distribute', (string) $index, (string) $occurrence, $code);
        for ($extra = 0; $extra < $remainder; $extra++) {
            $values[$positions[$extra]]++;
        }

        for ($swap = 0; $swap < $count; $swap++) {
            $from = $this->random->int(0, $count - 1, 'swap-from', (string) $index, (string) $occurrence, $code, (string) $swap);
            $to = $this->random->int(0, $count - 1, 'swap-to', (string) $index, (string) $occurrence, $code, (string) $swap);
            if ($from !== $to && $values[$to] < $maximum && $values[$from] > $minimum) {
                $values[$to]++;
                $values[$from]--;
            }
        }

        foreach ($values as $value) {
            if ($value < $minimum || $value > $maximum) {
                throw AnalyticsDatasetException::because('Distribusi jawaban berada di luar skala.');
            }
        }

        return $values;
    }

    /**
     * Pool total Enam Faktor disusun dari rentang kategori, lalu dipotong menjadi 428 tripel kronologis.
     * Blok stabil berjarak kecil; blok tren berjarak besar sehingga arah tren terwakili.
     *
     * @return list<array{trend: string, totals: list<int>}>
     */
    private function shuffledSixFactorTriples(): array
    {
        $pool = [];
        foreach (self::SIX_FACTOR_BANDS as $band) {
            $totals = $band['highest'] - $band['lowest'] + 1;
            $base = intdiv($band['evaluations'], $totals);
            $extra = $band['evaluations'] % $totals;
            for ($total = $band['lowest']; $total <= $band['highest']; $total++) {
                $count = $base + ($total - $band['lowest'] < $extra ? 1 : 0);
                array_push($pool, ...array_fill(0, $count, $total));
            }
        }

        $triples = [];
        $offset = 0;
        foreach (self::TRIPLE_BLOCK_PATTERN as $block => $type) {
            $stride = $type === 'F' ? self::FLAT_STRIDE : self::TREND_STRIDE;
            for ($item = 0; $item < $stride; $item++) {
                $low = $pool[$offset + $item];
                $middle = $pool[$offset + $item + $stride];
                $high = $pool[$offset + $item + 2 * $stride];
                $triples[] = $type === 'F'
                    ? $this->flatTriple([$low, $middle, $high], $block, $item)
                    : $this->trendTriple([$low, $middle, $high], $block, $item);
            }
            $offset += 3 * $stride;
        }

        if (count($triples) !== self::UMKM_COUNT || $offset !== count($pool)) {
            throw AnalyticsDatasetException::because('Pool total Enam Faktor tidak seimbang dengan jumlah UMKM.');
        }

        return $this->random->shuffle($triples, 'six-triple-assignment');
    }

    /**
     * @param  list<int>  $values
     * @return array{trend: string, totals: list<int>}
     */
    private function flatTriple(array $values, int $block, int $item): array
    {
        return [
            'trend' => 'stable',
            'totals' => $this->random->shuffle($values, 'flat-order', (string) $block, (string) $item),
        ];
    }

    /**
     * @param  list<int>  $values  Diurutkan naik: rendah, tengah, tinggi.
     * @return array{trend: string, totals: list<int>}
     */
    private function trendTriple(array $values, int $block, int $item): array
    {
        $improving = $this->random->int(0, 1, 'trend-direction', (string) $block, (string) $item) === 1;

        return [
            'trend' => $improving ? 'up' : 'down',
            'totals' => $improving ? $values : array_reverse($values),
        ];
    }

    /**
     * @template T
     *
     * @param  list<array{0: T, 1: int}>  $counted
     * @return list<T>
     */
    private function shuffledExpansion(array $counted, string $scope): array
    {
        $expanded = [];
        foreach ($counted as [$item, $count]) {
            array_push($expanded, ...array_fill(0, $count, $item));
        }

        if (count($expanded) !== self::UMKM_COUNT) {
            throw AnalyticsDatasetException::because('Jumlah distribusi master tidak sama dengan 428 UMKM.');
        }

        return $this->random->shuffle($expanded, 'expansion', $scope);
    }

    private function businessName(string $sectorSlug, int $index): string
    {
        $nouns = self::SECTOR_NOUNS[$sectorSlug];
        $noun = $nouns[$this->random->int(0, count($nouns) - 1, 'noun', (string) $index)];
        $brand = self::BRAND_WORDS[$this->random->int(0, count(self::BRAND_WORDS) - 1, 'brand', (string) $index)];

        return $noun.' '.$brand.' '.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
    }

    private function wibDate(CarbonImmutable $monthStart, int $day, int $hour, int $minute): CarbonImmutable
    {
        return CarbonImmutable::create($monthStart->year, $monthStart->month, $day, $hour, $minute, 0, self::PERIOD_TIMEZONE)->utc();
    }
}
