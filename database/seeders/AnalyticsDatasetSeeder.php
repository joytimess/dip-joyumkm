<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AnalyticsDatasetCohort;
use App\Models\User;
use App\Services\Analytics\AnalyticsDatasetException;
use App\Services\Analytics\AnalyticsDatasetInvocation;
use App\Services\Analytics\AnalyticsDatasetMasterData;
use App\Services\Analytics\AnalyticsDatasetPlanner;
use App\Services\Analytics\AnalyticsDatasetRun;
use App\Services\Analytics\AnalyticsDatasetVerifier;
use App\Services\Analytics\DeterministicRandom;
use Carbon\CarbonImmutable;
use Database\Seeders\Analytics\BusinessProfileSeeder;
use Database\Seeders\Analytics\SixFactorHistorySeeder;
use Database\Seeders\Analytics\ThreeDimensionHistorySeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Entry point tunggal dataset analitik sintetis. Jalankan secara eksplisit:
 *   php artisan analytics-dataset:seed --password-file=...   (atau db:seed --class=AnalyticsDatasetSeeder --force)
 * DatabaseSeeder memanggilnya otomatis hanya di local atau saat config analytics_dataset.auto_seed = true.
 *
 * Keadaan cohort (dataset_version pada analytics_dataset_cohorts):
 *  - tidak ada     : buat 458 UMKM (428 plan dasar + 30 ekspansi), keanggotaan, dan alokasi awal 153/153/152.
 *  - legacy (v1)   : pertahankan 428, tambahkan 30, alokasikan ulang pemilik satu kali, tandai v2.
 *  - selesai (v2)  : hanya verifikasi keanggotaan; tidak menulis apa pun dan tidak mengembalikan pemilik.
 * Seluruh penulisan berada dalam satu transaksi dan lock MySQL yang sama.
 *
 * @phpstan-type Master array{sector_ids: array<string, int>, factors: list<array{code: string, scale_min: int, scale_max: int, questions: list<array{id: int, values: list<int>}>}>}
 */
final class AnalyticsDatasetSeeder extends Seeder
{
    private const PASSWORD_MIN_LENGTH = 12;

    private const PASSWORD_MAX_BYTES = 72;

    private const LOCK_NAME = 'joyumkm:analytics-dataset';

    public function __construct(
        private readonly AnalyticsDatasetMasterData $masterData,
        private readonly AnalyticsDatasetVerifier $verifier,
        private readonly AnalyticsDatasetInvocation $invocation,
    ) {}

    /**
     * True bila dataset belum mencapai v2, yaitu alokasi awal ke Analyst masih akan dilakukan.
     */
    public static function requiresInitialAllocation(): bool
    {
        $version = AnalyticsDatasetCohort::query()->where('dataset_key', (string) config('analytics_dataset.dataset_key'))->value('dataset_version');

        return $version !== config('analytics_dataset.dataset_version');
    }

    public function run(): void
    {
        $startedAt = hrtime(true);
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw AnalyticsDatasetException::because('AnalyticsDatasetSeeder hanya didukung pada MySQL.');
        }

        $master = $this->masterData->inspect();

        $this->acquireLock($connection, self::LOCK_NAME);
        try {
            $outcome = $connection->transaction(fn (): array => $this->seedOrVerify($connection, self::LOCK_NAME, $master));
        } finally {
            $this->releaseLock($connection, self::LOCK_NAME);
        }

        $this->report($outcome, $startedAt);
    }

    /**
     * @param  Master  $master
     * @return array<string, mixed>
     */
    private function seedOrVerify(ConnectionInterface $connection, string $lockName, array $master): array
    {
        $run = new AnalyticsDatasetRun($connection, $lockName);
        $run->assertWritable();

        $cohort = AnalyticsDatasetCohort::query()->where('dataset_key', $this->datasetKey())->lockForUpdate()->first();
        if ($cohort === null) {
            $existingUser = User::query()->where('email', $this->accountEmail())->lockForUpdate()->first();
            if ($existingUser !== null) {
                throw AnalyticsDatasetException::because('Email akun analitik sintetis sudah dipakai akun tanpa identitas cohort. Tidak ada perubahan dilakukan; periksa kepemilikan akun secara manual.');
            }

            return $this->createCohort($connection, $run, $master);
        }

        return match ($cohort->dataset_version) {
            config('analytics_dataset.dataset_version') => $this->verifyCompleted($connection, $cohort),
            config('analytics_dataset.legacy_version') => $this->expandLegacy($connection, $run, $cohort, $master),
            default => throw AnalyticsDatasetException::because('Versi dataset pada cohort tidak dikenal. Tidak ada perubahan dilakukan.'),
        };
    }

    /**
     * Cohort baru: 458 UMKM langsung dimiliki tiga Analyst (posisi % 3), akun sintetis tidak memiliki UMKM.
     *
     * @param  Master  $master
     * @return array<string, mixed>
     */
    private function createCohort(ConnectionInterface $connection, AnalyticsDatasetRun $run, array $master): array
    {
        $secret = $this->resolvePassword();
        $analystIds = $this->analystIds();
        [$startMonth, $endMonth] = $this->referencePeriod();

        $planner = $this->planner();
        $plan = $planner->plan($startMonth, $master['factors']);
        $plan['umkms'] = array_merge($plan['umkms'], $planner->planExpansion($startMonth, $master['factors'])['umkms']);
        $this->assertNoNameCollisions($connection, $analystIds, array_map(fn (array $profile): string => $profile['name'], $plan['umkms']), []);

        $profiles = app()->make(BusinessProfileSeeder::class, ['run' => $run]);
        $user = $profiles->createAccount($plan['account_created_at'], $this->account(), $secret);
        $owners = [];
        foreach ($plan['umkms'] as $profile) {
            $owners[$profile['index']] = $analystIds[$profile['index'] % count($analystIds)];
        }

        $umkmIds = $profiles->seedProfiles($plan['umkms'], $owners, $master['sector_ids']);
        $threeDimensionCount = app()->make(ThreeDimensionHistorySeeder::class, ['run' => $run])->seed($plan, $umkmIds, $master['factors']);
        $sixFactorCount = app()->make(SixFactorHistorySeeder::class, ['run' => $run])->seed($plan, $umkmIds);

        $report = $this->verifier->inspect($connection, array_values($umkmIds), $this->accountEmail(), $master['factors'], $plan['months'], CarbonImmutable::now('UTC'));
        $this->verifier->assertPassed($report);

        $cohort = new AnalyticsDatasetCohort;
        $cohort->fill($this->cohortAttributes($report, $threeDimensionCount, $sixFactorCount) + [
            'dataset_key' => $this->datasetKey(),
            'user_id' => (int) $user->id,
            'reference_start_month' => $startMonth,
            'reference_end_month' => $endMonth,
        ])->save();

        $names = array_column($plan['umkms'], 'name', 'index');
        $this->insertMembers($connection, (int) $cohort->id, $umkmIds, $names);

        return ['mode' => 'created', 'reference' => [$startMonth, $endMonth], 'report' => $report, 'allocation' => $this->allocationCounts($connection, $analystIds, array_values($umkmIds))];
    }

    /**
     * Legacy v1 (428 UMKM milik akun sintetis, keanggotaan sudah dibackfill): tambah 30 anggota, alokasi awal satu kali, tandai v2.
     *
     * @param  Master  $master
     * @return array<string, mixed>
     */
    private function expandLegacy(ConnectionInterface $connection, AnalyticsDatasetRun $run, AnalyticsDatasetCohort $cohort, array $master): array
    {
        $configuredEnd = $this->invocation->referenceEndMonth();
        if ($configuredEnd !== null && $configuredEnd !== $cohort->reference_end_month) {
            throw AnalyticsDatasetException::because('Periode referensi yang diminta berbeda dari cohort yang sudah ada. Periode tidak digeser.');
        }

        $members = DB::table('analytics_dataset_members')->where('analytics_dataset_cohort_id', $cohort->id)->orderBy('umkm_id')->lockForUpdate()->get(['umkm_id', 'synthetic_key']);
        $memberIds = $members->pluck('umkm_id')->map(fn (mixed $id): int => (int) $id)->all();
        if ($cohort->umkm_count !== AnalyticsDatasetPlanner::UMKM_COUNT || count($memberIds) !== AnalyticsDatasetPlanner::UMKM_COUNT) {
            throw AnalyticsDatasetException::because('Keanggotaan cohort legacy tidak berisi 428 anggota. Jalankan analytics-dataset:backfill-members terlebih dahulu; tidak ada perubahan dilakukan.');
        }

        $elsewhere = $connection->table('umkms')->whereIn('id', $memberIds)->where('user_id', '!=', $cohort->user_id)->count();
        if ($elsewhere > 0) {
            throw AnalyticsDatasetException::because("Kepemilikan {$elsewhere} anggota legacy sudah berubah dari akun cohort. Alokasi awal dihentikan; rekonsiliasi manual diperlukan.");
        }

        $months = $this->monthsBetween($cohort->reference_start_month, $cohort->reference_end_month);
        $legacyReport = $this->verifier->inspect($connection, $memberIds, $this->accountEmail(), $master['factors'], $months, CarbonImmutable::now('UTC'));
        $this->verifier->assertPassed($legacyReport);
        if (! hash_equals((string) $cohort->content_fingerprint, $legacyReport['fingerprint'])) {
            throw AnalyticsDatasetException::because('Sidik jari konten cohort legacy berubah (drift). Tidak ada auto-repair; lakukan diagnosis manual.');
        }

        $analystIds = $this->analystIds();
        $expansion = $this->planner()->planExpansion($cohort->reference_start_month, $master['factors']);
        $existingNames = $connection->table('umkms')->whereIn('id', $memberIds)->orderBy('id')->pluck('name')->all();
        $this->assertNoNameCollisions($connection, $analystIds, array_merge($existingNames, array_map(fn (array $profile): string => $profile['name'], $expansion['umkms'])), $memberIds);

        $owners = [];
        foreach ($expansion['umkms'] as $profile) {
            $owners[$profile['index']] = $analystIds[$profile['index'] % count($analystIds)];
        }
        $newIds = app()->make(BusinessProfileSeeder::class, ['run' => $run])->seedProfiles($expansion['umkms'], $owners, $master['sector_ids']);
        $threeAdded = app()->make(ThreeDimensionHistorySeeder::class, ['run' => $run])->seed($expansion, $newIds, $master['factors']);
        $sixAdded = app()->make(SixFactorHistorySeeder::class, ['run' => $run])->seed($expansion, $newIds);

        // Alokasi awal anggota lama: hanya kolom user_id (tanpa menyentuh updated_at), hanya dari akun cohort ke Analyst.
        foreach ($analystIds as $slot => $analystId) {
            $slotIds = [];
            foreach ($memberIds as $position => $umkmId) {
                if ($position % count($analystIds) === $slot) {
                    $slotIds[] = $umkmId;
                }
            }
            $updated = $connection->table('umkms')->whereIn('id', $slotIds)->where('user_id', $cohort->user_id)->update(['user_id' => $analystId]);
            if ($updated !== count($slotIds)) {
                throw AnalyticsDatasetException::because('Alokasi awal tidak mengubah jumlah baris yang diharapkan; transaksi dibatalkan.');
            }
        }

        $allIds = array_merge($memberIds, array_values($newIds));
        $report = $this->verifier->inspect($connection, $allIds, $this->accountEmail(), $master['factors'], $months, CarbonImmutable::now('UTC'));
        $this->verifier->assertPassed($report);

        $this->insertMembers($connection, (int) $cohort->id, $newIds, array_column($expansion['umkms'], 'name', 'index'));
        $cohort->fill($this->cohortAttributes($report, $cohort->three_dimension_count + $threeAdded, $cohort->six_factor_count + $sixAdded))->save();

        $allocation = $this->allocationCounts($connection, $analystIds, $allIds);

        return ['mode' => 'expanded', 'reference' => [$cohort->reference_start_month, $cohort->reference_end_month], 'report' => $report, 'allocation' => $allocation];
    }

    /**
     * Cohort v2 selesai: tidak ada penulisan. Pemilik, nama, dan evaluasi tambahan yang sah tidak dianggap drift.
     *
     * @return array<string, mixed>
     */
    private function verifyCompleted(ConnectionInterface $connection, AnalyticsDatasetCohort $cohort): array
    {
        $configuredEnd = $this->invocation->referenceEndMonth();
        if ($configuredEnd !== null && $configuredEnd !== $cohort->reference_end_month) {
            throw AnalyticsDatasetException::because('Periode referensi yang diminta berbeda dari cohort yang sudah ada. Periode tidak digeser.');
        }

        if (User::query()->whereKey($cohort->user_id)->doesntExist()) {
            throw AnalyticsDatasetException::because('Akun legacy cohort tidak ditemukan. Tidak ada auto-repair.');
        }

        $problems = $this->verifier->inspectMembership($connection, (int) $cohort->id, (int) $cohort->umkm_count);
        if ($problems !== []) {
            throw AnalyticsDatasetException::because('Keanggotaan cohort tidak konsisten: '.implode('; ', $problems).'. Tidak ada auto-repair; rekonsiliasi manual diperlukan.');
        }

        return ['mode' => 'verified', 'reference' => [$cohort->reference_start_month, $cohort->reference_end_month], 'report' => null, 'allocation' => []];
    }

    /**
     * @param  array{counts: array{umkm: int, answer: int}, fingerprint: string}  $report
     * @return array<string, mixed>
     */
    private function cohortAttributes(array $report, int $threeDimensionCount, int $sixFactorCount): array
    {
        return [
            'dataset_version' => (string) config('analytics_dataset.dataset_version'),
            'umkm_count' => $report['counts']['umkm'],
            'three_dimension_count' => $threeDimensionCount,
            'six_factor_count' => $sixFactorCount,
            'answer_count' => $report['counts']['answer'],
            'content_fingerprint' => $report['fingerprint'],
            'completed_at' => CarbonImmutable::now('UTC'),
        ];
    }

    /**
     * @param  array<int, int>  $umkmIds  Indeks plan => ID UMKM.
     * @param  array<int, string>  $namesByIndex
     */
    private function insertMembers(ConnectionInterface $connection, int $cohortId, array $umkmIds, array $namesByIndex): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($umkmIds as $index => $umkmId) {
            $rows[] = ['analytics_dataset_cohort_id' => $cohortId, 'umkm_id' => $umkmId, 'synthetic_key' => $namesByIndex[$index], 'created_at' => $now, 'updated_at' => $now];
        }
        $connection->table('analytics_dataset_members')->insert($rows);
    }

    /**
     * @return list<int> ID tiga Analyst sesuai urutan config.
     */
    private function analystIds(): array
    {
        $ids = [];
        foreach ((array) config('analytics_dataset.analyst_emails') as $email) {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user === null || $user->userRole() !== UserRole::Analyst) {
                throw AnalyticsDatasetException::because("Akun Analyst {$email} belum ada atau bukan Analyst. Sediakan akun sampel dan jalankan joyumkm:map-sample-analysts; tidak ada perubahan dilakukan.");
            }
            $ids[] = (int) $user->id;
        }

        if (count($ids) !== 3) {
            throw AnalyticsDatasetException::because('Alokasi awal memerlukan tepat tiga akun Analyst.');
        }

        return $ids;
    }

    /**
     * UNIQUE(user_id, name) memakai collation MySQL (tidak peka huruf besar-kecil). Nama rencana per pemilik harus unik
     * dan tidak boleh bentrok dengan UMKM non-cohort yang sudah dimiliki Analyst.
     *
     * @param  list<int>  $analystIds
     * @param  list<string>  $plannedNames  Urutan posisi anggota (posisi % 3 menentukan pemilik).
     * @param  list<int>  $ignoredUmkmIds  UMKM cohort lama yang bukan milik Analyst saat ini.
     */
    private function assertNoNameCollisions(ConnectionInterface $connection, array $analystIds, array $plannedNames, array $ignoredUmkmIds): void
    {
        $taken = [];
        $rows = $connection->table('umkms')->whereIn('user_id', $analystIds)->whereNotIn('id', $ignoredUmkmIds)->get(['user_id', 'name']);
        foreach ($rows as $row) {
            $taken[(int) $row->user_id][mb_strtolower((string) $row->name)] = true;
        }

        foreach ($plannedNames as $position => $name) {
            $owner = $analystIds[$position % count($analystIds)];
            $key = mb_strtolower($name);
            if (isset($taken[$owner][$key])) {
                throw AnalyticsDatasetException::because('Bentrok nama UMKM per pemilik pada alokasi awal ('.$name.'). Tidak ada perubahan dilakukan; tidak ada penggantian nama.');
            }
            $taken[$owner][$key] = true;
        }
    }

    /**
     * @param  list<int>  $analystIds
     * @param  list<int>  $umkmIds
     * @return array<int, int> ID Analyst => jumlah anggota cohort.
     */
    private function allocationCounts(ConnectionInterface $connection, array $analystIds, array $umkmIds): array
    {
        $counts = $connection->table('umkms')->whereIn('id', $umkmIds)->selectRaw('user_id, COUNT(*) as total')->groupBy('user_id')->pluck('total', 'user_id')->map(fn (mixed $total): int => (int) $total)->all();
        $expected = [];
        foreach ($analystIds as $slot => $analystId) {
            $expected[$analystId] = intdiv(count($umkmIds) - $slot - 1, count($analystIds)) + 1;
        }
        ksort($counts);
        ksort($expected);
        if ($counts !== $expected) {
            throw AnalyticsDatasetException::because('Distribusi alokasi awal tidak sesuai target; transaksi dibatalkan.');
        }

        return $counts;
    }

    private function planner(): AnalyticsDatasetPlanner
    {
        return new AnalyticsDatasetPlanner(new DeterministicRandom((string) config('analytics_dataset.generator_seed')));
    }

    /**
     * Periode default: 12 bulan kalender terakhir yang telah selesai (WIB). Hanya dihitung saat cohort dibuat.
     *
     * @return array{0: string, 1: string}
     */
    private function referencePeriod(): array
    {
        $lastCompleted = CarbonImmutable::now(AnalyticsDatasetPlanner::PERIOD_TIMEZONE)->startOfMonth()->subMonth();
        $configured = $this->invocation->referenceEndMonth();

        if ($configured !== null) {
            if (preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $configured) !== 1) {
                throw AnalyticsDatasetException::because('Bulan akhir periode referensi harus berformat YYYY-MM.');
            }
            $end = CarbonImmutable::createFromFormat('!Y-m', $configured, AnalyticsDatasetPlanner::PERIOD_TIMEZONE);
            if ($end->greaterThan($lastCompleted)) {
                throw AnalyticsDatasetException::because('Periode referensi belum selesai; pilih bulan kalender yang sudah berakhir.');
            }
        } else {
            $end = $lastCompleted;
        }

        return [$end->subMonths(AnalyticsDatasetPlanner::MONTH_COUNT - 1)->format('Y-m'), $end->format('Y-m')];
    }

    /**
     * @return list<string>
     */
    private function monthsBetween(string $startMonth, string $endMonth): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $startMonth, AnalyticsDatasetPlanner::PERIOD_TIMEZONE);

        return array_map(
            fn (int $offset): string => $start->addMonths($offset)->format('Y-m'),
            range(0, AnalyticsDatasetPlanner::MONTH_COUNT - 1),
        );
    }

    /**
     * Hanya dipakai saat akun sintetis baru dibuat: pemanggil (invocation), config analytics_dataset.password, atau prompt interaktif.
     */
    private function resolvePassword(): string
    {
        $password = $this->invocation->secret() ?? config('analytics_dataset.password');

        if ($password === null && $this->command !== null && $this->command->option('no-interaction') !== true) {
            $password = (string) $this->command->secret('Kata sandi akun analitik sintetis (minimal '.self::PASSWORD_MIN_LENGTH.' karakter)');
        }

        if (! $this->isAcceptablePassword($password)) {
            throw AnalyticsDatasetException::because('Kata sandi akun analitik tidak tersedia atau tidak valid. Jalankan php artisan analytics-dataset:seed dengan --password-file atau --password-stdin, atau jalankan secara interaktif.');
        }

        return $password;
    }

    private function isAcceptablePassword(mixed $password): bool
    {
        return is_string($password)
            && trim($password) !== ''
            && mb_strlen($password) >= self::PASSWORD_MIN_LENGTH
            && strlen($password) <= self::PASSWORD_MAX_BYTES
            && ! str_contains($password, "\0");
    }

    private function acquireLock(ConnectionInterface $connection, string $lockName): void
    {
        $timeout = max(1, (int) config('analytics_dataset.lock_timeout_seconds'));
        $row = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lockName, $timeout]);

        if ((int) ($row->acquired ?? 0) !== 1) {
            throw AnalyticsDatasetException::because('Lock dataset analitik tidak didapat dalam batas waktu; proses lain mungkin sedang berjalan.');
        }
    }

    private function releaseLock(ConnectionInterface $connection, string $lockName): void
    {
        $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function report(array $outcome, int|float $startedAt): void
    {
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $peakMegabytes = memory_get_peak_usage(true) / 1_048_576;

        $label = match ($outcome['mode']) {
            'created' => 'dibuat (458 UMKM, alokasi awal 153/153/152)',
            'expanded' => 'diperluas dari legacy v1 ke v2 (+30 UMKM, alokasi awal satu kali)',
            default => 'terverifikasi (no-op, tidak ada data ditulis)',
        };
        $this->command?->line('Analytics dataset: '.$label);
        $this->command?->line('Periode referensi: '.$outcome['reference'][0].' s.d. '.$outcome['reference'][1]);
        $this->command?->line('Waktu: '.number_format($elapsedSeconds, 2).' detik; memory peak: '.number_format($peakMegabytes, 1).' MB');
        if ($outcome['report'] !== null) {
            $this->command?->line('Sidik jari konten: '.$outcome['report']['fingerprint']);
            foreach ($outcome['report']['checks'] as $check) {
                if (! $check['pass']) {
                    $this->command?->error('Gagal: '.$check['name']);
                }
            }
            $this->command?->line('Pemeriksaan integritas: '.count($outcome['report']['checks']).' lulus.');
        }
        if ($outcome['allocation'] !== []) {
            $this->command?->line('Alokasi awal per ID Analyst: '.json_encode($outcome['allocation']));
        }
    }

    private function datasetKey(): string
    {
        return (string) config('analytics_dataset.dataset_key');
    }

    private function accountEmail(): string
    {
        return (string) config('analytics_dataset.account.email');
    }

    /**
     * @return array{name: string, email: string}
     */
    private function account(): array
    {
        return [
            'name' => (string) config('analytics_dataset.account.name'),
            'email' => $this->accountEmail(),
        ];
    }
}
