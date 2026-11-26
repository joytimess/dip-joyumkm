<?php

declare(strict_types=1);

namespace Database\Seeders;

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
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Entry point tunggal dataset analitik sintetis. Jalankan secara eksplisit:
 *   php artisan db:seed --class=AnalyticsDatasetSeeder --force
 *
 * Tidak didaftarkan di DatabaseSeeder. Seluruh penulisan berada dalam satu transaksi dan lock MySQL yang sama.
 *
 * @phpstan-type Master array{sector_ids: array<string, int>, factors: list<array{code: string, scale_min: int, scale_max: int, questions: list<array{id: int, values: list<int>}>}>}
 */
final class AnalyticsDatasetSeeder extends Seeder
{
    private const PASSWORD_MIN_LENGTH = 12;

    private const PASSWORD_MAX_BYTES = 72;

    public function __construct(
        private readonly AnalyticsDatasetMasterData $masterData,
        private readonly AnalyticsDatasetVerifier $verifier,
        private readonly Hasher $hasher,
        private readonly AnalyticsDatasetInvocation $invocation,
    ) {}

    public function run(): void
    {
        $startedAt = hrtime(true);
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw AnalyticsDatasetException::because('AnalyticsDatasetSeeder hanya didukung pada MySQL.');
        }

        $secret = $this->resolvePassword();
        $master = $this->masterData->inspect();
        $lockName = $this->lockName();

        $this->acquireLock($connection, $lockName);
        try {
            $outcome = $connection->transaction(fn (): array => $this->seedOrVerify($connection, $lockName, $secret, $master));
        } finally {
            $this->releaseLock($connection, $lockName);
        }

        $this->report($outcome, $startedAt);
    }

    /**
     * @param  Master  $master
     * @return array<string, mixed>
     */
    private function seedOrVerify(ConnectionInterface $connection, string $lockName, string $secret, array $master): array
    {
        $run = new AnalyticsDatasetRun($connection, $lockName);
        $run->assertWritable();

        $cohort = AnalyticsDatasetCohort::query()->where('dataset_key', $this->datasetKey())->lockForUpdate()->first();
        if ($cohort !== null) {
            return $this->verifyExisting($connection, $cohort, $secret, $master);
        }

        $existingUser = User::query()->where('email', $this->accountEmail())->lockForUpdate()->first();
        if ($existingUser !== null) {
            throw AnalyticsDatasetException::because('Email akun analitik sintetis sudah dipakai akun tanpa identitas cohort. Tidak ada perubahan dilakukan; periksa kepemilikan akun secara manual.');
        }

        return $this->createCohort($connection, $run, $secret, $master);
    }

    /**
     * @param  Master  $master
     * @return array<string, mixed>
     */
    private function createCohort(ConnectionInterface $connection, AnalyticsDatasetRun $run, string $secret, array $master): array
    {
        [$startMonth, $endMonth] = $this->referencePeriod();
        $plan = (new AnalyticsDatasetPlanner(new DeterministicRandom((string) config('analytics_dataset.generator_seed'))))
            ->plan($startMonth, $master['factors']);

        $business = app()->make(BusinessProfileSeeder::class, ['run' => $run])->seed($plan, $this->account(), $secret, $master['sector_ids']);
        $threeDimensionCount = app()->make(ThreeDimensionHistorySeeder::class, ['run' => $run])->seed($plan, $business['umkm_ids'], $master['factors']);
        $sixFactorCount = app()->make(SixFactorHistorySeeder::class, ['run' => $run])->seed($plan, $business['umkm_ids']);

        $report = $this->verifier->inspect($connection, $business['user_id'], $this->accountEmail(), $master['factors'], $plan['months'], CarbonImmutable::now('UTC'));
        $this->verifier->assertPassed($report);

        $cohort = new AnalyticsDatasetCohort;
        $cohort->fill([
            'dataset_key' => $this->datasetKey(),
            'dataset_version' => (string) config('analytics_dataset.dataset_version'),
            'user_id' => $business['user_id'],
            'reference_start_month' => $startMonth,
            'reference_end_month' => $endMonth,
            'umkm_count' => $report['counts']['umkm'],
            'three_dimension_count' => $threeDimensionCount,
            'six_factor_count' => $sixFactorCount,
            'answer_count' => $report['counts']['answer'],
            'content_fingerprint' => $report['fingerprint'],
            'completed_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return ['mode' => 'created', 'reference' => [$startMonth, $endMonth], 'report' => $report, 'cohort' => $cohort];
    }

    /**
     * @param  Master  $master
     * @return array<string, mixed>
     */
    private function verifyExisting(ConnectionInterface $connection, AnalyticsDatasetCohort $cohort, string $secret, array $master): array
    {
        if ($cohort->dataset_version !== config('analytics_dataset.dataset_version')) {
            throw AnalyticsDatasetException::because('Versi dataset pada cohort berbeda dari versi yang diminta. Tidak ada perubahan dilakukan.');
        }

        $user = User::query()->find($cohort->user_id);
        if ($user === null || $user->email !== $this->accountEmail() || $user->name !== $this->account()['name']) {
            throw AnalyticsDatasetException::because('Identitas cohort tidak cocok dengan akun pemiliknya. Tidak ada auto-repair.');
        }

        if (! $this->hasher->check($secret, $user->password)) {
            throw AnalyticsDatasetException::because('Kata sandi tidak cocok dengan akun analitik sintetis. Tidak ada perubahan dilakukan.');
        }

        $configuredEnd = $this->invocation->referenceEndMonth();
        if ($configuredEnd !== null && $configuredEnd !== $cohort->reference_end_month) {
            throw AnalyticsDatasetException::because('Periode referensi yang diminta berbeda dari cohort yang sudah ada. Periode tidak digeser.');
        }

        $months = $this->monthsBetween($cohort->reference_start_month, $cohort->reference_end_month);
        $report = $this->verifier->inspect($connection, (int) $user->id, $this->accountEmail(), $master['factors'], $months, CarbonImmutable::now('UTC'));
        $this->verifier->assertPassed($report);

        if (! hash_equals((string) $cohort->content_fingerprint, $report['fingerprint'])) {
            throw AnalyticsDatasetException::because('Sidik jari konten cohort berubah (drift). Tidak ada auto-repair; lakukan diagnosis manual.');
        }

        return ['mode' => 'verified', 'reference' => [$cohort->reference_start_month, $cohort->reference_end_month], 'report' => $report, 'cohort' => $cohort];
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

    private function resolvePassword(): string
    {
        $password = $this->invocation->secret();

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

        $this->command?->line('Analytics dataset: '.($outcome['mode'] === 'created' ? 'dibuat' : 'terverifikasi (no-op, tidak ada data ditulis)'));
        $this->command?->line('Periode referensi: '.$outcome['reference'][0].' s.d. '.$outcome['reference'][1]);
        $this->command?->line('Waktu: '.number_format($elapsedSeconds, 2).' detik; memory peak: '.number_format($peakMegabytes, 1).' MB');
        $this->command?->line('Sidik jari konten: '.$outcome['report']['fingerprint']);
        foreach ($outcome['report']['checks'] as $check) {
            if (! $check['pass']) {
                $this->command?->error('Gagal: '.$check['name']);
            }
        }
        $this->command?->line('Pemeriksaan integritas: '.count($outcome['report']['checks']).' lulus.');
    }

    private function lockName(): string
    {
        return 'joyumkm:analytics-dataset:'.config('analytics_dataset.dataset_version');
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
