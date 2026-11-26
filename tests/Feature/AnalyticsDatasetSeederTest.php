<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnalyticsDatasetCohort;
use App\Models\Evaluation;
use App\Models\SixFactorEvaluation;
use App\Models\User;
use App\Services\Analytics\AnalyticsDatasetException;
use App\Services\Analytics\AnalyticsDatasetExporter;
use App\Services\Analytics\AnalyticsDatasetInvocation;
use App\Services\Analytics\AnalyticsDatasetRun;
use App\Services\ScoringService;
use Carbon\CarbonImmutable;
use Database\Seeders\Analytics\BusinessProfileSeeder;
use Database\Seeders\Analytics\SixFactorHistorySeeder;
use Database\Seeders\AnalyticsDatasetSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AnalyticsDatasetSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'Sintetis-Uji-2026-Aman';

    private const WRONG_SECRET = 'Sintetis-Salah-2026-Aman';

    private const EMAIL = 'analitik.sintetis@dataset.invalid';

    private const LOCK_NAME = 'joyumkm:analytics-dataset:v1';

    private function runSeeder(?string $secret = self::SECRET, ?string $referenceEnd = null): void
    {
        $this->app->instance(AnalyticsDatasetInvocation::class, new AnalyticsDatasetInvocation($secret, $referenceEnd));

        $this->seed(AnalyticsDatasetSeeder::class);
    }

    private function cohortUser(): User
    {
        return User::query()->where('email', self::EMAIL)->firstOrFail();
    }

    private function assertNoDatasetRows(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('umkms', 0);
        $this->assertDatabaseCount('evaluations', 0);
        $this->assertDatabaseCount('evaluation_answers', 0);
        $this->assertDatabaseCount('six_factor_evaluations', 0);
        $this->assertDatabaseCount('analytics_dataset_cohorts', 0);
    }

    public function test_clean_run_meets_the_dataset_contract(): void
    {
        $this->runSeeder();

        $user = $this->cohortUser();
        $umkmIds = $user->umkms()->pluck('id');

        $this->assertCount(428, $umkmIds);
        $this->assertSame(1284, Evaluation::query()->whereIn('umkm_id', $umkmIds)->count());
        $this->assertSame(20544, DB::table('evaluation_answers')
            ->join('evaluations', 'evaluations.id', '=', 'evaluation_answers.evaluation_id')
            ->whereIn('evaluations.umkm_id', $umkmIds)->count());
        $this->assertSame(1284, SixFactorEvaluation::query()->whereIn('umkm_id', $umkmIds)->count());

        $this->assertSame(
            ['Bandung' => 43, 'Bekasi' => 43, 'Jakarta Selatan' => 43, 'Jakarta Timur' => 43, 'Malang' => 43,
                'Semarang' => 43, 'Surabaya' => 43, 'Surakarta' => 43, 'Tangerang' => 42, 'Tangerang Selatan' => 42],
            $user->umkms()->selectRaw('city, COUNT(*) as total')->groupBy('city')->pluck('total', 'city')->map(fn ($n) => (int) $n)->sortKeys()->all(),
        );

        $this->assertSame(
            ['Banten' => 84, 'DKI Jakarta' => 86, 'Jawa Barat' => 86, 'Jawa Tengah' => 86, 'Jawa Timur' => 86],
            $user->umkms()->selectRaw('province, COUNT(*) as total')->groupBy('province')->pluck('total', 'province')->map(fn ($n) => (int) $n)->sortKeys()->all(),
        );

        $sectorCounts = $user->umkms()->join('sectors', 'sectors.id', '=', 'umkms.sector_id')
            ->selectRaw('sectors.slug, COUNT(*) as total')->groupBy('sectors.slug')->pluck('total', 'slug')->map(fn ($n) => (int) $n)->sortKeys()->all();
        $this->assertSame(['fashion-kriya' => 75, 'grosir-retail' => 100, 'jasa' => 85, 'makanan-minuman' => 120, 'pertanian' => 48], $sectorCounts);

        $this->assertDatabaseHas('analytics_dataset_cohorts', [
            'dataset_key' => 'joyumkm-analytics',
            'dataset_version' => 'v1',
            'user_id' => $user->id,
            'reference_start_month' => '2025-11',
            'reference_end_month' => '2026-10',
            'umkm_count' => 428,
            'three_dimension_count' => 1284,
            'six_factor_count' => 1284,
            'answer_count' => 20544,
        ]);
    }

    public function test_evaluations_are_distributed_evenly_across_the_twelve_months(): void
    {
        $this->runSeeder();

        $user = $this->cohortUser();
        $threeByMonth = Evaluation::query()->whereIn('umkm_id', $user->umkms()->select('id'))->get(['submitted_at'])
            ->groupBy(fn (Evaluation $evaluation): string => SixFactorEvaluation::periodMonthFor($evaluation->submitted_at))
            ->map->count()->sortKeys()->all();
        $sixByMonth = SixFactorEvaluation::query()->whereIn('umkm_id', $user->umkms()->select('id'))->get()
            ->groupBy('period_month')->map->count()->sortKeys()->all();

        $expectedMonths = [];
        for ($offset = 0; $offset < 12; $offset++) {
            $expectedMonths[CarbonImmutable::create(2025, 11, 1)->addMonths($offset)->format('Y-m')] = 107;
        }

        $this->assertSame($expectedMonths, $threeByMonth);
        $this->assertSame($expectedMonths, $sixByMonth);
    }

    public function test_six_factor_totals_cover_every_score_and_follow_the_target_distribution(): void
    {
        $this->runSeeder();

        $user = $this->cohortUser();
        $finals = SixFactorEvaluation::query()->whereIn('umkm_id', $user->umkms()->select('id'))->get();

        $this->assertSame(range(6, 30), $finals->pluck('total_score')->unique()->sort()->values()->all());
        $this->assertSame(
            ['Cukup Sehat' => 309, 'Kurang Sehat' => 360, 'Sangat Sehat' => 307, 'Sehat' => 308],
            $finals->groupBy('category')->map->count()->sortKeys()->all(),
        );
        $this->assertCount(0, $finals->filter(fn (SixFactorEvaluation $evaluation): bool => $evaluation->total_score !== array_sum([
            $evaluation->sumber_daya, $evaluation->kepemimpinan, $evaluation->lingkungan_kerja,
            $evaluation->nilai_organisasi, $evaluation->stabilitas, $evaluation->kinerja,
        ])));
    }

    public function test_three_dimension_scores_are_recomputed_from_stored_answers_and_cover_all_categories(): void
    {
        $this->runSeeder();

        $user = $this->cohortUser();
        $evaluations = Evaluation::query()->whereIn('umkm_id', $user->umkms()->select('id'))->orderBy('id')->get();
        $answersByEvaluation = DB::table('evaluation_answers')
            ->join('questions', 'questions.id', '=', 'evaluation_answers.question_id')
            ->join('factors', 'factors.id', '=', 'questions.factor_id')
            ->whereIn('evaluation_answers.evaluation_id', $evaluations->pluck('id'))
            ->get(['evaluation_answers.evaluation_id', 'evaluation_answers.value', 'factors.code', 'factors.scale_min', 'factors.scale_max'])
            ->groupBy('evaluation_id');
        $scoringFactors = DB::table('factors')->orderBy('sort_order')->get(['code', 'scale_min', 'scale_max'])
            ->map(fn (object $factor): array => ['code' => $factor->code, 'scale_min' => (int) $factor->scale_min, 'scale_max' => (int) $factor->scale_max])
            ->all();
        $scoring = app(ScoringService::class);

        foreach ($evaluations as $evaluation) {
            $valuesByCode = [];
            foreach ($answersByEvaluation[$evaluation->id] ?? [] as $answer) {
                $valuesByCode[$answer->code][] = (int) $answer->value;
            }

            $recomputed = $scoring->calculate($scoringFactors, $valuesByCode);

            $this->assertEqualsWithDelta((float) $evaluation->average_score, (float) $recomputed['average_score'], 0.05);
            $this->assertEqualsWithDelta((float) $evaluation->ins_score, (float) $recomputed['factor_scores']['INS'], 0.00005);
            $this->assertSame($recomputed['category'], $evaluation->category);
        }

        $this->assertSame(['BAIK', 'CUKUP', 'KRITIS', 'KURANG', 'SANGAT BAIK'], $evaluations->pluck('category')->unique()->sort()->values()->all());
    }

    public function test_six_factor_period_uses_wib_at_the_month_boundary(): void
    {
        $this->runSeeder();

        $user = $this->cohortUser();
        $mismatches = SixFactorEvaluation::query()->whereIn('umkm_id', $user->umkms()->select('id'))->get()
            ->filter(fn (SixFactorEvaluation $evaluation): bool => $evaluation->period_month !== SixFactorEvaluation::periodMonthFor($evaluation->finalized_at))
            ->count();
        $boundaryCases = SixFactorEvaluation::query()->whereIn('umkm_id', $user->umkms()->select('id'))->get()
            ->filter(fn (SixFactorEvaluation $evaluation): bool => $evaluation->period_month !== $evaluation->finalized_at->utc()->format('Y-m'))
            ->count();

        $this->assertSame(0, $mismatches);
        $this->assertGreaterThan(0, $boundaryCases, 'Kasus batas bulan WIB harus ada agar konversi periode teruji.');
    }

    public function test_rerun_with_the_same_secret_is_a_verified_no_op(): void
    {
        $this->runSeeder();

        $user = $this->cohortUser();
        $passwordHash = $user->password;
        $cohort = AnalyticsDatasetCohort::query()->firstOrFail();
        $updatedAt = $cohort->updated_at;

        $this->runSeeder();

        $this->assertSame($passwordHash, $this->cohortUser()->password, 'Kata sandi tidak boleh berubah saat rerun.');
        $this->assertSame(1, AnalyticsDatasetCohort::query()->count());
        $this->assertSame($updatedAt?->toDateTimeString(), AnalyticsDatasetCohort::query()->firstOrFail()->updated_at?->toDateTimeString());
        $this->assertDatabaseCount('umkms', 428);
        $this->assertDatabaseCount('evaluation_answers', 20544);
        $this->assertDatabaseCount('six_factor_evaluations', 1284);
    }

    public function test_missing_secret_fails_closed_without_writes(): void
    {
        $this->expectException(AnalyticsDatasetException::class);

        try {
            $this->runSeeder(null);
        } finally {
            $this->assertNoDatasetRows();
        }
    }

    public function test_short_secret_fails_closed_without_writes(): void
    {
        try {
            $this->runSeeder('pendek');
            $this->fail('Kata sandi pendek harus ditolak.');
        } catch (AnalyticsDatasetException) {
            $this->assertNoDatasetRows();
        }
    }

    public function test_wrong_secret_on_an_existing_cohort_is_rejected_without_mutation(): void
    {
        $this->runSeeder();
        $passwordHash = $this->cohortUser()->password;

        try {
            $this->runSeeder(self::WRONG_SECRET);
            $this->fail('Kata sandi yang salah harus ditolak.');
        } catch (AnalyticsDatasetException $exception) {
            $this->assertStringNotContainsString(self::WRONG_SECRET, $exception->getMessage());
        }

        $this->assertSame($passwordHash, $this->cohortUser()->password);
        $this->assertDatabaseCount('umkms', 428);
        $this->assertDatabaseCount('evaluation_answers', 20544);
    }

    public function test_conflicting_account_without_cohort_fails_closed(): void
    {
        $this->assertTrue(User::query()->create([
            'name' => 'Akun Lain',
            'email' => self::EMAIL,
            'password' => self::SECRET,
        ])->exists);

        try {
            $this->runSeeder();
            $this->fail('Akun dengan email yang sama tanpa cohort harus menghentikan seeding.');
        } catch (AnalyticsDatasetException) {
            // Diharapkan: tidak ada perubahan pada akun lain.
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('umkms', 0);
        $this->assertDatabaseCount('analytics_dataset_cohorts', 0);
    }

    public function test_failure_after_partial_writes_rolls_back_everything_and_releases_the_lock(): void
    {
        $this->app->bind(SixFactorHistorySeeder::class, fn (): object => new class
        {
            public function seed(array $plan, array $umkmIds): int
            {
                throw new RuntimeException('Injected failure after partial writes.');
            }
        });

        try {
            $this->runSeeder();
            $this->fail('Kegagalan yang disuntikkan harus menggagalkan seluruh transaksi.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after partial writes.', $exception->getMessage());
        }

        $this->assertNoDatasetRows();
        $this->assertSame(1, (int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS free', [self::LOCK_NAME])->free);
    }

    public function test_cohort_drift_is_reported_and_not_auto_repaired(): void
    {
        $this->runSeeder();

        $drifted = $this->cohortUser()->umkms()->orderBy('id')->firstOrFail();
        $drifted->forceFill(['name' => 'Nama Drift Uji'])->save();

        try {
            $this->runSeeder();
            $this->fail('Drift pada cohort harus terdeteksi.');
        } catch (AnalyticsDatasetException) {
            // Diharapkan.
        }

        $this->assertSame('Nama Drift Uji', $drifted->fresh()->name, 'Drift tidak boleh diperbaiki otomatis.');
    }

    public function test_reference_period_is_persisted_and_never_shifted_on_rerun(): void
    {
        $this->runSeeder(self::SECRET, '2026-09');

        $this->assertDatabaseHas('analytics_dataset_cohorts', [
            'reference_start_month' => '2025-10',
            'reference_end_month' => '2026-09',
        ]);

        try {
            $this->runSeeder(self::SECRET, '2026-08');
            $this->fail('Periode yang berbeda tidak boleh menggeser cohort.');
        } catch (AnalyticsDatasetException) {
            // Diharapkan.
        }

        $this->runSeeder();

        $this->assertDatabaseHas('analytics_dataset_cohorts', ['reference_end_month' => '2026-09']);
        $this->assertDatabaseCount('umkms', 428);
    }

    public function test_reference_period_must_be_a_completed_calendar_month(): void
    {
        foreach (['2999-01', '2026-13', 'bukan-bulan'] as $invalid) {
            try {
                $this->runSeeder(self::SECRET, $invalid);
                $this->fail("Periode {$invalid} harus ditolak.");
            } catch (AnalyticsDatasetException) {
                // Diharapkan.
            }
        }

        $this->assertNoDatasetRows();
    }

    public function test_sub_seeders_refuse_to_write_outside_the_orchestrator_lock(): void
    {
        $run = new AnalyticsDatasetRun(DB::connection(), self::LOCK_NAME);

        $this->expectException(AnalyticsDatasetException::class);

        try {
            app()->make(BusinessProfileSeeder::class, ['run' => $run])->seed(
                ['months' => [], 'account_created_at' => CarbonImmutable::now('UTC'), 'umkms' => []],
                ['name' => 'Akun Uji', 'email' => self::EMAIL],
                self::SECRET,
                [],
            );
        } finally {
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_no_outbox_or_queue_side_effects_even_when_integrations_are_enabled(): void
    {
        config([
            'services.hermes_webhook.enabled' => true,
            'services.hermes_onboarding.enabled' => true,
            'services.openwa.enabled' => true,
        ]);

        $this->runSeeder();

        $this->assertDatabaseCount('webhook_outbox', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_password_is_hashed_with_the_application_hasher(): void
    {
        $this->runSeeder();

        $user = $this->cohortUser();

        $this->assertNotSame(self::SECRET, $user->password);
        $this->assertTrue(app(Hasher::class)->check(self::SECRET, $user->password));
    }

    public function test_wrapper_command_fails_noninteractively_without_a_secret(): void
    {
        $this->artisan('analytics-dataset:seed', ['--no-interaction' => true])->assertExitCode(1);

        $this->assertNoDatasetRows();
    }

    public function test_wrapper_command_reads_the_secret_from_a_file_without_printing_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'analytics-secret-');
        file_put_contents($path, self::SECRET."\n");

        try {
            $this->artisan('analytics-dataset:seed', ['--password-file' => $path, '--no-interaction' => true])
                ->doesntExpectOutputToContain(self::SECRET)
                ->assertExitCode(0);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseCount('umkms', 428);
        $this->assertTrue(app(Hasher::class)->check(self::SECRET, $this->cohortUser()->password));
    }

    public function test_export_writes_one_row_per_umkm_with_a_stable_header_and_no_credentials(): void
    {
        $this->runSeeder();

        $path = tempnam(sys_get_temp_dir(), 'analytics-export-').'.csv';

        try {
            $this->artisan('analytics-dataset:export', ['path' => $path])->assertExitCode(0);

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $this->assertCount(429, $lines, 'Harus ada header dan 428 baris data.');
            $this->assertSame(implode(',', AnalyticsDatasetExporter::HEADER), $lines[0]);
            $this->assertStringNotContainsString(self::EMAIL, implode("\n", $lines));
            $this->assertStringNotContainsString('password', strtolower($lines[0]));
            $this->assertCount(428, array_unique(array_map(fn (string $line): string => explode(',', $line)[0], array_slice($lines, 1))));
        } finally {
            @unlink($path);
        }
    }
}
