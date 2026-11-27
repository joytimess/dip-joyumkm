<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnalyticsDatasetCohort;
use App\Models\AnalyticsDatasetMember;
use App\Models\Evaluation;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\User;
use App\Services\Analytics\AnalyticsDatasetException;
use App\Services\Analytics\AnalyticsDatasetExporter;
use App\Services\Analytics\AnalyticsDatasetInvocation;
use App\Services\Analytics\AnalyticsDatasetMasterData;
use App\Services\Analytics\AnalyticsDatasetPlanner;
use App\Services\Analytics\AnalyticsDatasetRun;
use App\Services\Analytics\AnalyticsDatasetVerifier;
use App\Services\ScoringService;
use Carbon\CarbonImmutable;
use Database\Seeders\Analytics\BusinessProfileSeeder;
use Database\Seeders\Analytics\SixFactorHistorySeeder;
use Database\Seeders\AnalyticsDatasetSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Builder;
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

    private const LOCK_NAME = 'joyumkm:analytics-dataset';

    /**
     * @return list<int> ID tiga Analyst sampel (prasyarat alokasi awal).
     */
    private function createAnalysts(): array
    {
        $ids = [];
        foreach (['sample1', 'sample2', 'sample3'] as $name) {
            $user = User::query()->firstOrCreate(['email' => $name.'@joyumkm.test'], ['name' => $name, 'password' => self::SECRET]);
            $user->forceFill(['role' => 'analyst'])->save();
            $ids[] = (int) $user->id;
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function memberIds(): array
    {
        return DB::table('analytics_dataset_members')->orderBy('umkm_id')->pluck('umkm_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @return Builder<Umkm>
     */
    private function cohortUmkms(): Builder
    {
        return Umkm::query()->whereIn('umkms.id', $this->memberIds());
    }

    private function runSeeder(?string $secret = self::SECRET, ?string $referenceEnd = null): void
    {
        $this->createAnalysts();
        $this->app->instance(AnalyticsDatasetInvocation::class, new AnalyticsDatasetInvocation($secret, $referenceEnd));

        $this->seed(AnalyticsDatasetSeeder::class);
    }

    private function cohortUser(): User
    {
        return User::query()->where('email', self::EMAIL)->firstOrFail();
    }

    private function assertNoDatasetRows(): void
    {
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
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
        $umkmIds = $this->cohortUmkms()->pluck('id');

        $this->assertCount(458, $umkmIds);
        $this->assertSame(458, AnalyticsDatasetMember::query()->count());
        $this->assertSame(0, $user->umkms()->count(), 'Akun sintetis tidak memiliki anggota cohort setelah alokasi awal.');
        $this->assertSame(1374, Evaluation::query()->whereIn('umkm_id', $umkmIds)->count());
        $this->assertSame(21984, DB::table('evaluation_answers')
            ->join('evaluations', 'evaluations.id', '=', 'evaluation_answers.evaluation_id')
            ->whereIn('evaluations.umkm_id', $umkmIds)->count());
        $this->assertSame(1374, SixFactorEvaluation::query()->whereIn('umkm_id', $umkmIds)->count());

        $this->assertSame(
            ['Bandung' => 46, 'Bekasi' => 46, 'Jakarta Selatan' => 46, 'Jakarta Timur' => 46, 'Malang' => 46,
                'Semarang' => 46, 'Surabaya' => 46, 'Surakarta' => 46, 'Tangerang' => 45, 'Tangerang Selatan' => 45],
            $this->cohortUmkms()->selectRaw('city, COUNT(*) as total')->groupBy('city')->pluck('total', 'city')->map(fn ($n) => (int) $n)->sortKeys()->all(),
        );

        $this->assertSame(
            ['Banten' => 90, 'DKI Jakarta' => 92, 'Jawa Barat' => 92, 'Jawa Tengah' => 92, 'Jawa Timur' => 92],
            $this->cohortUmkms()->selectRaw('province, COUNT(*) as total')->groupBy('province')->pluck('total', 'province')->map(fn ($n) => (int) $n)->sortKeys()->all(),
        );

        $sectorCounts = $this->cohortUmkms()->join('sectors', 'sectors.id', '=', 'umkms.sector_id')
            ->selectRaw('sectors.slug, COUNT(*) as total')->groupBy('sectors.slug')->pluck('total', 'slug')->map(fn ($n) => (int) $n)->sortKeys()->all();
        $this->assertSame(['fashion-kriya' => 80, 'grosir-retail' => 107, 'jasa' => 91, 'makanan-minuman' => 128, 'pertanian' => 52], $sectorCounts);

        $this->assertDatabaseHas('analytics_dataset_cohorts', [
            'dataset_key' => 'joyumkm-analytics',
            'dataset_version' => 'v2',
            'user_id' => $user->id,
            'reference_start_month' => '2025-11',
            'reference_end_month' => '2026-10',
            'umkm_count' => 458,
            'three_dimension_count' => 1374,
            'six_factor_count' => 1374,
            'answer_count' => 21984,
        ]);
    }

    public function test_initial_allocation_is_153_153_152_by_position_and_collision_free(): void
    {
        $analystIds = $this->createAnalysts();
        $this->runSeeder();

        $owners = $this->cohortUmkms()->orderBy('id')->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->assertSame(
            [$analystIds[0] => 153, $analystIds[1] => 153, $analystIds[2] => 152],
            array_count_values($owners),
        );
        foreach ($owners as $position => $owner) {
            $this->assertSame($analystIds[$position % 3], $owner);
        }
        $this->assertSame(0, DB::table('umkms')->selectRaw('user_id, name')->groupBy('user_id', 'name')->havingRaw('COUNT(*) > 1')->get()->count());
    }

    public function test_fresh_seed_without_analyst_accounts_fails_closed(): void
    {
        $this->app->instance(AnalyticsDatasetInvocation::class, new AnalyticsDatasetInvocation(self::SECRET, null));

        try {
            $this->seed(AnalyticsDatasetSeeder::class);
            $this->fail('Alokasi awal tanpa Analyst harus ditolak.');
        } catch (AnalyticsDatasetException) {
            $this->assertNoDatasetRows();
        }
    }

    public function test_legacy_v1_cohort_is_expanded_to_the_same_content_as_a_fresh_v2_seed(): void
    {
        $this->runSeeder();
        $fresh = AnalyticsDatasetCohort::query()->firstOrFail();
        $freshFingerprint = $fresh->content_fingerprint;
        $freshNames = $this->cohortUmkms()->orderBy('id')->pluck('name')->all();

        // Bentuk ulang keadaan legacy: 428 anggota milik akun sintetis, tanpa 30 ekspansi, versi v1.
        $legacyIds = array_slice($this->memberIds(), 0, 428);
        $extraIds = array_slice($this->memberIds(), 428);
        DB::table('evaluation_answers')->whereIn('evaluation_id', DB::table('evaluations')->whereIn('umkm_id', $extraIds)->select('id'))->delete();
        DB::table('evaluations')->whereIn('umkm_id', $extraIds)->delete();
        DB::table('six_factor_evaluations')->whereIn('umkm_id', $extraIds)->delete();
        DB::table('analytics_dataset_members')->whereIn('umkm_id', $extraIds)->delete();
        DB::table('umkms')->whereIn('id', $extraIds)->delete();
        DB::table('umkms')->whereIn('id', $legacyIds)->update(['user_id' => $fresh->user_id]);

        $factors = app(AnalyticsDatasetMasterData::class)->inspect()['factors'];
        $months = array_keys(AnalyticsDatasetPlanner::expectedPerMonthCounts(428, [
            '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10',
        ]));
        $legacy = app(AnalyticsDatasetVerifier::class)->inspect(DB::connection(), $legacyIds, self::EMAIL, $factors, $months, CarbonImmutable::now('UTC'));
        $this->assertTrue($legacy['passed']);
        $fresh->forceFill(['dataset_version' => 'v1', 'umkm_count' => 428, 'three_dimension_count' => 1284, 'six_factor_count' => 1284,
            'answer_count' => 20544, 'content_fingerprint' => $legacy['fingerprint']])->save();

        $this->runSeeder();

        $expanded = AnalyticsDatasetCohort::query()->firstOrFail();
        $this->assertSame('v2', $expanded->dataset_version);
        $this->assertSame($freshFingerprint, $expanded->content_fingerprint, 'Legacy 428 + 30 harus identik dengan seed v2 baru.');
        $this->assertSame(458, AnalyticsDatasetMember::query()->count());
        $this->assertSame($legacyIds, array_slice($this->memberIds(), 0, 428), 'ID anggota legacy dipertahankan.');
        $this->assertSame(0, $this->cohortUser()->umkms()->count());
        $this->assertSame(1374, $expanded->three_dimension_count);
        $this->assertSame($freshNames, $this->cohortUmkms()->orderBy('id')->pluck('name')->all());
    }

    public function test_evaluations_are_distributed_evenly_across_the_twelve_months(): void
    {
        $this->runSeeder();

        $threeByMonth = Evaluation::query()->whereIn('umkm_id', $this->memberIds())->get(['submitted_at'])
            ->groupBy(fn (Evaluation $evaluation): string => SixFactorEvaluation::periodMonthFor($evaluation->submitted_at))
            ->map->count()->sortKeys()->all();
        $sixByMonth = SixFactorEvaluation::query()->whereIn('umkm_id', $this->memberIds())->get()
            ->groupBy('period_month')->map->count()->sortKeys()->all();

        $months = [];
        for ($offset = 0; $offset < 12; $offset++) {
            $months[] = CarbonImmutable::create(2025, 11, 1)->addMonths($offset)->format('Y-m');
        }
        $expectedMonths = AnalyticsDatasetPlanner::expectedPerMonthCounts(458, $months);

        $this->assertSame($expectedMonths, $threeByMonth);
        $this->assertSame($expectedMonths, $sixByMonth);
    }

    public function test_six_factor_totals_cover_every_score_and_follow_the_target_distribution(): void
    {
        $this->runSeeder();

        $finals = SixFactorEvaluation::query()->whereIn('umkm_id', $this->memberIds())->get();

        $this->assertSame(range(6, 30), $finals->pluck('total_score')->unique()->sort()->values()->all());
        $this->assertSame(
            ['Cukup Sehat' => 332, 'Kurang Sehat' => 382, 'Sangat Sehat' => 329, 'Sehat' => 331],
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

        $evaluations = Evaluation::query()->whereIn('umkm_id', $this->memberIds())->orderBy('id')->get();
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

        $mismatches = SixFactorEvaluation::query()->whereIn('umkm_id', $this->memberIds())->get()
            ->filter(fn (SixFactorEvaluation $evaluation): bool => $evaluation->period_month !== SixFactorEvaluation::periodMonthFor($evaluation->finalized_at))
            ->count();
        $boundaryCases = SixFactorEvaluation::query()->whereIn('umkm_id', $this->memberIds())->get()
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
        $this->assertDatabaseCount('umkms', 458);
        $this->assertDatabaseCount('evaluation_answers', 21984);
        $this->assertDatabaseCount('six_factor_evaluations', 1374);
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

    public function test_completed_cohort_does_not_need_or_use_a_secret_and_writes_nothing(): void
    {
        $this->runSeeder();
        $passwordHash = $this->cohortUser()->password;

        $this->runSeeder(self::WRONG_SECRET);
        $this->runSeeder(null);

        $this->assertSame($passwordHash, $this->cohortUser()->password);
        $this->assertDatabaseCount('umkms', 458);
        $this->assertDatabaseCount('evaluation_answers', 21984);
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

        $this->assertSame(1, User::query()->where('email', self::EMAIL)->count());
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

    public function test_later_legitimate_changes_survive_reruns_and_are_not_treated_as_drift(): void
    {
        $analystIds = $this->createAnalysts();
        $this->runSeeder();

        $member = $this->cohortUmkms()->orderBy('id')->firstOrFail();
        $originalOwner = (int) $member->user_id;
        $newOwner = collect($analystIds)->first(fn (int $id): bool => $id !== $originalOwner);
        $member->forceFill(['name' => 'Nama Sah Setelah Seed', 'user_id' => $newOwner])->save();
        $updatedAt = AnalyticsDatasetCohort::query()->firstOrFail()->updated_at?->toDateTimeString();

        $this->runSeeder();

        $fresh = $member->fresh();
        $this->assertSame('Nama Sah Setelah Seed', $fresh->name);
        $this->assertSame($newOwner, (int) $fresh->user_id, 'Rerun tidak boleh mengembalikan alokasi awal.');
        $this->assertSame($updatedAt, AnalyticsDatasetCohort::query()->firstOrFail()->updated_at?->toDateTimeString());
        $this->assertSame(458, AnalyticsDatasetMember::query()->count());
    }

    public function test_inconsistent_membership_fails_closed_without_repair(): void
    {
        $this->runSeeder();
        AnalyticsDatasetMember::query()->orderBy('id')->firstOrFail()->delete();

        try {
            $this->runSeeder();
            $this->fail('Keanggotaan tidak konsisten harus dihentikan.');
        } catch (AnalyticsDatasetException $exception) {
            $this->assertStringContainsString('rekonsiliasi manual', $exception->getMessage());
        }

        $this->assertSame(457, AnalyticsDatasetMember::query()->count(), 'Tidak ada auto-repair.');
        $this->assertDatabaseCount('umkms', 458);
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
        $this->assertDatabaseCount('umkms', 458);
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
        $this->createAnalysts();
        $path = tempnam(sys_get_temp_dir(), 'analytics-secret-');
        file_put_contents($path, self::SECRET."\n");

        try {
            $this->artisan('analytics-dataset:seed', ['--password-file' => $path, '--no-interaction' => true])
                ->doesntExpectOutputToContain(self::SECRET)
                ->assertExitCode(0);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseCount('umkms', 458);
        $this->assertTrue(app(Hasher::class)->check(self::SECRET, $this->cohortUser()->password));
    }

    public function test_database_seeder_defaults_analytics_off_outside_local_and_on_in_local(): void
    {
        $seeder = new DatabaseSeeder;
        $enabled = (new \ReflectionMethod($seeder, 'analyticsDatasetEnabled'));

        config(['analytics_dataset.auto_seed' => null]);
        $this->assertFalse($enabled->invoke($seeder), 'Environment testing/produksi default mati.');

        config(['analytics_dataset.auto_seed' => false]);
        $this->assertFalse($enabled->invoke($seeder));

        config(['analytics_dataset.auto_seed' => true]);
        $this->assertTrue($enabled->invoke($seeder), 'Opt-in eksplisit di luar local.');

        config(['analytics_dataset.auto_seed' => null]);
        $this->app['env'] = 'local';
        $this->assertTrue($enabled->invoke($seeder), 'Local otomatis.');
    }

    public function test_export_writes_one_row_per_umkm_with_a_stable_header_and_no_credentials(): void
    {
        $this->runSeeder();

        $path = tempnam(sys_get_temp_dir(), 'analytics-export-').'.csv';

        try {
            $this->artisan('analytics-dataset:export', ['path' => $path])->assertExitCode(0);

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $this->assertCount(459, $lines, 'Harus ada header dan 458 baris data.');
            $this->assertSame(implode(',', AnalyticsDatasetExporter::HEADER), $lines[0]);
            $this->assertStringNotContainsString(self::EMAIL, implode("\n", $lines));
            $this->assertStringNotContainsString('password', strtolower($lines[0]));
            $this->assertCount(458, array_unique(array_map(fn (string $line): string => explode(',', $line)[0], array_slice($lines, 1))));
        } finally {
            @unlink($path);
        }
    }
}
