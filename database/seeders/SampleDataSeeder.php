<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Factor;
use App\Models\Sector;
use App\Models\User;
use App\Services\EvaluationService;
use App\Services\QuestionnaireService;
use Carbon\Carbon;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * SampleDataSeeder — mengisi data dummy untuk tiga grafik dashboard (Profil Faktor,
 * Distribusi, Perbandingan), Home, dan tabel UMKM agar terlihat hidup.
 *
 * Profil aktif ditentukan lewat env SEED_PROFILE (default: "small").
 * Password akun dibaca dari env SEED_PASSWORD lewat config umkm.seed_password.
 *
 * Strategi tanggal: Carbon::setTestNow() dipanggil sesaat sebelum EvaluationService::store()
 * untuk menanam nilai now() tanpa mengubah kontrak service. Nilai direset setelah selesai.
 *
 * Seeder bersifat idempotent: jika email sample1@joyumkm.test sudah ada, seluruh
 * grup akun sample dilewati tanpa mengubah data existing.
 */
class SampleDataSeeder extends Seeder
{
    /** Email pertama yang menandai grup sample. */
    private const SAMPLE_MARKER_EMAIL = 'sample1@joyumkm.test';

    /** Seed Faker deterministik agar hasil dapat diulang. */
    private const FAKER_SEED = 20261104;

    public function __construct(
        private readonly EvaluationService $evaluationService,
        private readonly QuestionnaireService $questionnaireService,
        private readonly DatabaseManager $database,
    ) {}

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('SampleDataSeeder dilewati: hanya berjalan pada APP_ENV=local.');

            return;
        }

        $password = config('umkm.seed_password');
        if (! $this->isValidPassword($password)) {
            $this->command?->warn('SampleDataSeeder dilewati. Isi SEED_PASSWORD lokal sepanjang 8 karakter atau lebih, maksimal 72 byte, tanpa karakter NUL.');

            return;
        }

        $profileName = (string) config('umkm.seed_profile', 'small');
        $profiles = (array) config('umkm.seed_profiles', []);
        $profile = $profiles[$profileName] ?? $profiles['small'];

        $this->seedSampleGroup((string) $password, $profile);
    }

    // -------------------------------------------------------------------------
    // Validasi password
    // -------------------------------------------------------------------------

    private function isValidPassword(mixed $password): bool
    {
        return is_string($password)
            && trim($password) !== ''
            && mb_strlen($password) >= 8
            && strlen($password) <= 72
            && ! str_contains($password, "\0");
    }

    // -------------------------------------------------------------------------
    // Grup utama
    // -------------------------------------------------------------------------

    /**
     * @param  array{accounts:int,umkms:int,max_evaluations:int}  $profile
     */
    private function seedSampleGroup(string $password, array $profile): void
    {
        // Idempotency: jika marker email sudah ada, lewati seluruh grup.
        if (User::query()->where('email', self::SAMPLE_MARKER_EMAIL)->exists()) {
            $this->command?->info('SampleDataSeeder dilewati: data sample sudah ada (email marker ditemukan).');

            return;
        }

        $faker = FakerFactory::create('id_ID');
        $faker->seed(self::FAKER_SEED);

        $provinces = config('provinces');
        $sectors = Sector::query()->orderBy('id')->get()->keyBy('slug');
        $factors = $this->questionnaireService->activeFactors();

        $totalUmkms = max(1, (int) $profile['umkms']);
        $maxEvaluations = max(1, (int) $profile['max_evaluations']);
        $accountCount = max(1, (int) $profile['accounts']);

        // Distribusi UMKM antar akun: tidak merata.
        $distribution = $this->buildDistribution($totalUmkms, $accountCount, $faker);

        // Sebaran kategori target berdasarkan porsi (small profile ≈ 20 UMKM).
        $categoryQueue = $this->buildCategoryTargets($totalUmkms);

        // Pool nama UMKM Indonesia realistis.
        $umkmNamePool = $this->buildUmkmNamePool($faker, $totalUmkms * 3);

        $usedNamesGlobal = [];

        foreach (range(1, $accountCount) as $accountIndex) {
            $email = "sample{$accountIndex}@joyumkm.test";
            $umkmCount = $distribution[$accountIndex - 1];

            $this->database->transaction(function () use (
                $email, $password, $umkmCount, $faker,
                $provinces, $sectors, $factors, $maxEvaluations,
                $umkmNamePool, &$usedNamesGlobal, &$categoryQueue,
            ): void {
                $owner = User::query()->create([
                    'name' => $this->generateOwnerName($faker),
                    'email' => $email,
                    'password' => $password,
                ]);

                $accountUsedNames = [];

                for ($umkmIndex = 0; $umkmIndex < $umkmCount; $umkmIndex++) {
                    $name = $this->pickUniqueName($umkmNamePool, $accountUsedNames, $faker);
                    $accountUsedNames[] = mb_strtolower($name);
                    $usedNamesGlobal[] = mb_strtolower($name);

                    $sector = $sectors->random();
                    $province = $provinces[array_rand($provinces)];
                    $omzet = $this->generateOmzet($faker, $sector->slug);

                    $umkm = $owner->umkms()->create([
                        'name' => $name,
                        'province' => $province,
                        'sector_id' => $sector->id,
                        'omzet' => $omzet,
                    ]);

                    // Dequeue kategori untuk UMKM ini.
                    $targetCategory = array_shift($categoryQueue) ?? 'CUKUP';

                    if ($targetCategory === 'BELUM DINILAI') {
                        // Tidak ada evaluasi — UMKM ini sengaja tanpa riwayat.
                        continue;
                    }

                    // Deteksi kasus khusus sebelum menentukan evalCount / dates.
                    $isDraftEmpty = $targetCategory === 'DRAFT_EMPTY';
                    $isPartial = $targetCategory === 'PARTIAL';

                    if ($isDraftEmpty) {
                        $targetCategory = 'CUKUP';
                    }

                    if ($isPartial) {
                        $targetCategory = 'CUKUP';
                    }

                    // Jumlah evaluasi final yang akan dibuat. Draft kosong tambahan ikut dihitung dalam batas profil.
                    $finalLimit = $isDraftEmpty ? max(1, $maxEvaluations - 1) : $maxEvaluations;
                    $finalCount = $isPartial ? 1 : $faker->numberBetween(1, $finalLimit);

                    // Total slot tanggal: final + 1 jika draft kosong.
                    $totalSlots = $isDraftEmpty ? $finalCount + 1 : $finalCount;
                    $dates = $this->buildEvaluationDates($faker, $totalSlots);

                    // Tren: naik atau turun (acak per UMKM).
                    $trend = $faker->boolean() ? 'up' : 'down';

                    // Simpan evaluasi final (dengan tren).
                    for ($evalIndex = 0; $evalIndex < $finalCount; $evalIndex++) {
                        $iterationTarget = $this->computeIterationTarget(
                            $targetCategory, $evalIndex, $finalCount, $trend,
                        );

                        $isLastPartial = $isPartial && $evalIndex === $finalCount - 1;
                        $answers = $isLastPartial
                            ? $this->buildPartialAnswers($factors)
                            : $this->buildAnswers($factors, $iterationTarget);

                        // Evaluasi parsial (OPS tidak dijawab) disimpan sebagai draft karena
                        // final membutuhkan semua soal terisi.
                        $status = $isLastPartial ? 'draft' : 'final';

                        Carbon::setTestNow($dates[$evalIndex]);
                        try {
                            $this->evaluationService->store($owner, $umkm->id, [
                                'status' => $status,
                                'answers' => $answers,
                            ]);
                        } finally {
                            Carbon::setTestNow(null);
                        }
                    }

                    // Tambahkan draft kosong setelah final jika diminta.
                    if ($isDraftEmpty) {
                        Carbon::setTestNow($dates[$finalCount]);
                        try {
                            $this->evaluationService->store($owner, $umkm->id, [
                                'status' => 'draft',
                                'answers' => [],
                            ]);
                        } finally {
                            Carbon::setTestNow(null);
                        }
                    }
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // Helpers: distribusi & kategori
    // -------------------------------------------------------------------------

    /**
     * Distribusi UMKM antar akun — tidak merata.
     *
     * @return array<int>
     */
    private function buildDistribution(int $total, int $accounts, Generator $faker): array
    {
        if ($accounts === 1) {
            return [$total];
        }

        $result = [];
        $remaining = $total;

        for ($i = 0; $i < $accounts - 1; $i++) {
            $maxForThis = $remaining - ($accounts - $i - 1);
            $val = $faker->numberBetween(1, max(1, (int) ($maxForThis * 0.7)));
            $result[] = $val;
            $remaining -= $val;
        }

        $result[] = max(1, $remaining);

        return $result;
    }

    /**
     * Antrian kategori target, dikocok agar terdistribusi merata antar akun.
     * Kategori khusus DRAFT_EMPTY dan PARTIAL diselipkan.
     *
     * @return array<string>
     */
    private function buildCategoryTargets(int $total): array
    {
        // Alokasi: SANGAT BAIK 15%, BAIK 35%, CUKUP 30%, KURANG 12%, KRITIS 5%, BELUM DINILAI sisa.
        $counts = [
            'SANGAT BAIK' => max(1, (int) round($total * 0.15)),
            'BAIK' => max(1, (int) round($total * 0.35)),
            'CUKUP' => max(1, (int) round($total * 0.30)),
            'KURANG' => max(1, (int) round($total * 0.12)),
            'KRITIS' => max(1, (int) round($total * 0.05)),
            'BELUM DINILAI' => min(2, max(1, (int) round($total * 0.03))),
            'DRAFT_EMPTY' => min(3, max(2, (int) round($total * 0.03))),
            'PARTIAL' => 1,
        ];

        // Pastikan total tidak melebihi atau kurang dari $total.
        $sum = array_sum($counts);
        if ($sum > $total) {
            $counts['CUKUP'] = max(1, $counts['CUKUP'] - ($sum - $total));
        } elseif ($sum < $total) {
            $counts['CUKUP'] += ($total - $sum);
        }

        $queue = [];
        foreach ($counts as $category => $count) {
            for ($i = 0; $i < $count; $i++) {
                $queue[] = $category;
            }
        }

        // Acak urutan dengan seed tetap agar deterministik.
        $seed = self::FAKER_SEED;
        usort($queue, function ($a, $b) use (&$seed): int {
            $seed = ($seed * 1664525 + 1013904223) & 0x7FFFFFFF;

            return ($seed % 3) - 1;
        });

        return $queue;
    }

    // -------------------------------------------------------------------------
    // Helpers: nama UMKM Indonesia realistis
    // -------------------------------------------------------------------------

    /** @return array<string> */
    private function buildUmkmNamePool(Generator $faker, int $size): array
    {
        $prefixes = [
            'Usaha', 'Toko', 'Warung', 'Kedai', 'Bengkel', 'Studio', 'Rumah', 'Karya',
            'Mitra', 'Prima', 'Jaya', 'Makmur', 'Sejahtera', 'Berkah', 'Mandiri',
            'Sentosa', 'Abadi', 'Nusantara', 'Sumber', 'Cahaya',
        ];
        $nouns = [
            'Barokah', 'Mulia', 'Bersama', 'Raya', 'Utama', 'Lestari', 'Damai',
            'Indah', 'Maju', 'Sukses', 'Rezeki', 'Berkah', 'Harapan', 'Karya',
            'Sejati', 'Setia', 'Murni', 'Asri', 'Hijau', 'Gemilang',
            'Mekar', 'Segar', 'Kencana', 'Perkasa', 'Agung', 'Sakti',
        ];
        $pool = [];
        $seen = [];
        $attempts = 0;

        while (count($pool) < $size && $attempts < $size * 10) {
            $attempts++;
            $prefix = $prefixes[array_rand($prefixes)];
            $noun = $nouns[array_rand($nouns)];
            $name = $prefix.' '.$noun;
            $key = mb_strtolower($name);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $pool[] = $name;

                continue;
            }

            // Coba dengan suffix agar tetap unik.
            foreach (['Jaya', 'Utama', 'Mandiri', 'Sejahtera', 'Berkah', 'Prima', 'Raya'] as $suffix) {
                $candidate = $name.' '.$suffix;
                $candidateKey = mb_strtolower($candidate);
                if (! isset($seen[$candidateKey])) {
                    $seen[$candidateKey] = true;
                    $pool[] = $candidate;

                    break;
                }
            }
        }

        return $pool;
    }

    /** @param  array<string>  $accountUsedNames */
    private function pickUniqueName(array $pool, array $accountUsedNames, Generator $faker): string
    {
        $shuffled = $pool;
        shuffle($shuffled);

        foreach ($shuffled as $candidate) {
            if (! in_array(mb_strtolower($candidate), $accountUsedNames, true)) {
                return $candidate;
            }
        }

        // Fallback: nama dengan random suffix.
        return 'Usaha Mandiri '.$faker->unique()->randomNumber(5, true);
    }

    private function generateOwnerName(Generator $faker): string
    {
        return $faker->name();
    }

    // -------------------------------------------------------------------------
    // Helpers: omzet per sektor
    // -------------------------------------------------------------------------

    private function generateOmzet(Generator $faker, string $sectorSlug): int
    {
        return match ($sectorSlug) {
            'makanan-minuman' => $faker->numberBetween(2_000_000, 50_000_000),
            'grosir-retail' => $faker->numberBetween(5_000_000, 200_000_000),
            'jasa' => $faker->numberBetween(1_000_000, 30_000_000),
            'fashion-kriya' => $faker->numberBetween(1_500_000, 40_000_000),
            'pertanian' => $faker->numberBetween(500_000, 25_000_000),
            default => $faker->numberBetween(1_000_000, 50_000_000),
        };
    }

    // -------------------------------------------------------------------------
    // Helpers: tanggal evaluasi
    // -------------------------------------------------------------------------

    /**
     * Buat array Carbon berurutan naik, tersebar dalam ~6 bulan terakhir.
     *
     * @return array<Carbon>
     */
    private function buildEvaluationDates(Generator $faker, int $count): array
    {
        $now = Carbon::now();
        $start = $now->copy()->subMonths(6);
        $totalSeconds = (int) $now->diffInSeconds($start);

        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $offsets[] = $faker->numberBetween(0, $totalSeconds);
        }
        sort($offsets);

        $dates = [];
        foreach ($offsets as $offset) {
            $dates[] = $start->copy()->addSeconds($offset);
        }

        return $dates;
    }

    // -------------------------------------------------------------------------
    // Helpers: skor target & jawaban
    // -------------------------------------------------------------------------

    /**
     * Hitung skor target untuk iterasi ke-$evalIndex dari total $evalCount evaluasi,
     * dengan tren naik atau turun.
     */
    private function computeIterationTarget(
        string $finalCategory,
        int $evalIndex,
        int $evalCount,
        string $trend,
    ): float {
        $finalScore = $this->categoryMidpoint($finalCategory);

        if ($evalCount === 1) {
            return $finalScore;
        }

        // Jangkauan tren: ±20 poin dari skor final.
        $spread = 20.0;
        $progress = $evalIndex / ($evalCount - 1); // 0.0 → 1.0

        if ($trend === 'up') {
            // Mulai dari (finalScore - spread), naik ke finalScore.
            $startScore = max(0.0, $finalScore - $spread);

            return $startScore + ($finalScore - $startScore) * $progress;
        }

        // Mulai dari (finalScore + spread), turun ke finalScore.
        $startScore = min(100.0, $finalScore + $spread);

        return $startScore - ($startScore - $finalScore) * $progress;
    }

    /** Titik tengah representatif tiap kategori. */
    private function categoryMidpoint(string $category): float
    {
        return match ($category) {
            'SANGAT BAIK' => 90.0,
            'BAIK' => 77.0,
            'CUKUP' => 62.0,
            'KURANG' => 47.0,
            'KRITIS' => 20.0,
            default => 62.0,
        };
    }

    /**
     * Bangun array jawaban valid untuk 16 soal aktif agar skor mendekati targetScore.
     *
     * Strategi: hitung nilai rata-rata yang dibutuhkan tiap faktor, lalu distribusikan
     * ke soal-soal dengan mempertahankan nilai dalam opsi valid. Skor aktual dihitung
     * oleh ScoringService; kita hanya mendekati target.
     *
     * @param  Collection<int,Factor>  $factors
     * @return array<array{question_id:int,value:int}>
     */
    private function buildAnswers(Collection $factors, float $targetScore): array
    {
        // targetScore adalah rata-rata ketiga faktor (normalized 0–100).
        // Kita alokasikan target yang sama untuk tiap faktor.
        $answers = [];

        foreach ($factors as $factor) {
            $min = (int) $factor->scale_min;
            $max = (int) $factor->scale_max;
            $questions = $factor->questions;
            $count = $questions->count();

            if ($count === 0) {
                continue;
            }

            // normalized = (sum - count*min) / (count*(max-min)) * 100
            // → sum = normalized/100 * count*(max-min) + count*min
            $normalizedTarget = min(100.0, max(0.0, $targetScore));
            $sumTarget = $normalizedTarget / 100.0 * $count * ($max - $min) + $count * $min;
            $sumTarget = max((float) ($count * $min), min((float) ($count * $max), $sumTarget));

            $values = $this->distributeSum((int) round($sumTarget), $count, $min, $max);

            foreach ($questions as $index => $question) {
                $options = array_column($question->options, 'value');
                $value = $this->nearestOption($values[$index], $options);
                $answers[] = ['question_id' => $question->id, 'value' => $value];
            }
        }

        return $answers;
    }

    /**
     * Jawaban parsial: hanya dua faktor dijawab, satu faktor (OPS) dikosongkan.
     * Disimpan sebagai draft agar tidak gagal validasi completeness.
     *
     * @param  Collection<int,Factor>  $factors
     * @return array<array{question_id:int,value:int}>
     */
    private function buildPartialAnswers(Collection $factors): array
    {
        $answers = [];
        $skipped = false;

        foreach ($factors as $factor) {
            // Lewati satu faktor (OPS) agar ada faktor tidak terjawab.
            if (! $skipped && $factor->code === 'OPS') {
                $skipped = true;

                continue;
            }

            $min = (int) $factor->scale_min;
            $max = (int) $factor->scale_max;
            $mid = (int) round(($min + $max) / 2);
            $questions = $factor->questions;
            $count = $questions->count();

            $sumTarget = $count * $mid;
            $values = $this->distributeSum($sumTarget, $count, $min, $max);

            foreach ($questions as $index => $question) {
                $options = array_column($question->options, 'value');
                $answers[] = ['question_id' => $question->id, 'value' => $this->nearestOption($values[$index], $options)];
            }
        }

        return $answers;
    }

    /**
     * Distribusikan $sum ke $count nilai integer dalam [$min, $max].
     *
     * @return array<int>
     */
    private function distributeSum(int $sum, int $count, int $min, int $max): array
    {
        $sum = max($count * $min, min($count * $max, $sum));
        $values = array_fill(0, $count, $min);
        $remaining = $sum - $count * $min;
        $capacity = $max - $min;

        for ($i = 0; $i < $count && $remaining > 0; $i++) {
            $add = min($capacity, $remaining);
            $values[$i] += $add;
            $remaining -= $add;
        }

        // Pseudo-shuffle deterministik untuk menyebarkan nilai.
        for ($i = $count - 1; $i > 0; $i--) {
            $j = (int) ($i * 0.618) % ($i + 1);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return $values;
    }

    /**
     * Nilai opsi terdekat dengan $target.
     *
     * @param  array<int>  $options
     */
    private function nearestOption(int $target, array $options): int
    {
        $best = $options[0];
        $bestDiff = abs($target - $best);

        foreach ($options as $option) {
            $diff = abs($target - $option);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $option;
            }
        }

        return $best;
    }
}
