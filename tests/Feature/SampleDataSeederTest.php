<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\Umkm;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SampleDataSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function enableSample(): void
    {
        $this->app['env'] = 'local';
        config(['umkm.seed_password' => 'JoyUMKM-Sample-2026!']);
    }

    private function enableDemo(): void
    {
        config(['umkm.demo_password' => 'JoyUMKM-Demo-2026!']);
    }

    // -------------------------------------------------------------------------
    // Guard: environment
    // -------------------------------------------------------------------------

    public function test_nonlocal_does_not_create_sample_data(): void
    {
        // APP_ENV=testing (default no-local), seed_password valid.
        config(['umkm.seed_password' => 'JoyUMKM-Sample-2026!']);

        $this->seed(SampleDataSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('umkms', 0);
    }

    public function test_database_seeder_nonlocal_does_not_create_sample(): void
    {
        config(['umkm.seed_password' => 'JoyUMKM-Sample-2026!']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('umkms', 0);
        // Master data tetap tersedia.
        $this->assertDatabaseCount('questions', 16);
    }

    public function test_direct_call_nonlocal_is_blocked(): void
    {
        // Pastikan guard dalam SampleDataSeeder mencegah seed meski dipanggil langsung.
        config(['umkm.seed_password' => 'JoyUMKM-Sample-2026!']);

        $this->seed(SampleDataSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    // -------------------------------------------------------------------------
    // Guard: password
    // -------------------------------------------------------------------------

    /**
     * @dataProvider invalidPasswords
     */
    public function test_invalid_seed_password_does_not_create_sample(?string $password): void
    {
        $this->enableSample();
        config(['umkm.seed_password' => $password]);

        $this->seed(SampleDataSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('umkms', 0);
    }

    public static function invalidPasswords(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'spaces' => ['        '],
            'short' => ['short'],
            'bcrypt_too_long' => [str_repeat('x', 73)],
            'nul' => ["JoyUMKM\0password"],
        ];
    }

    // -------------------------------------------------------------------------
    // Jumlah akun, UMKM, dan riwayat (profil small)
    // -------------------------------------------------------------------------

    public function test_small_profile_creates_expected_account_and_umkm_counts(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);

        $sampleUsers = User::query()
            ->where('email', 'like', 'sample%@joyumkm.test')
            ->get();

        $this->assertCount(3, $sampleUsers, 'Harus ada 3 akun sample.');

        $totalUmkms = Umkm::query()
            ->whereIn('user_id', $sampleUsers->pluck('id'))
            ->count();

        // Profil small: ~20 UMKM (antara 15 dan 25 untuk toleransi distribusi).
        $this->assertGreaterThanOrEqual(15, $totalUmkms, 'Minimal 15 UMKM untuk profil small.');
        $this->assertLessThanOrEqual(25, $totalUmkms, 'Maksimal 25 UMKM untuk profil small.');
    }

    public function test_each_umkm_has_at_most_max_evaluations_evaluations(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);

        $sampleUsers = User::query()
            ->where('email', 'like', 'sample%@joyumkm.test')
            ->pluck('id');

        $umkms = Umkm::query()->whereIn('user_id', $sampleUsers)->get();

        foreach ($umkms as $umkm) {
            $count = $umkm->evaluations()->count();
            $this->assertLessThanOrEqual(
                3,
                $count,
                "UMKM {$umkm->name} memiliki {$count} evaluasi, melebihi batas profil small (3).",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Sebaran kategori
    // -------------------------------------------------------------------------

    public function test_category_distribution_is_reasonable(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);

        $sampleUserIds = User::query()
            ->where('email', 'like', 'sample%@joyumkm.test')
            ->pluck('id');

        $umkmIds = Umkm::query()->whereIn('user_id', $sampleUserIds)->pluck('id');
        $total = $umkmIds->count();

        // Ambil latest evaluation per UMKM.
        $latestEvals = Evaluation::query()
            ->whereIn('umkm_id', $umkmIds)
            ->whereIn('id', function ($sub) use ($umkmIds): void {
                $sub->selectRaw('MAX(id)')
                    ->from('evaluations')
                    ->whereIn('umkm_id', $umkmIds)
                    ->groupBy('umkm_id');
            })
            ->get();

        // UMKM tanpa evaluasi dihitung sebagai BELUM DINILAI.
        $umkmsWithoutEval = $total - Umkm::query()
            ->whereIn('id', $umkmIds)
            ->whereHas('evaluations')
            ->count();

        $categories = $latestEvals->groupBy('category');
        $ratedTotal = $latestEvals->count();

        // Minimal ada SANGAT BAIK dan BAIK.
        $this->assertGreaterThanOrEqual(
            1,
            ($categories->get('SANGAT BAIK')?->count() ?? 0) + ($categories->get('BAIK')?->count() ?? 0),
            'Harus ada minimal satu UMKM dengan kategori SANGAT BAIK atau BAIK.',
        );

        // Minimal ada KURANG atau KRITIS.
        $this->assertGreaterThanOrEqual(
            1,
            ($categories->get('KURANG')?->count() ?? 0) + ($categories->get('KRITIS')?->count() ?? 0),
            'Harus ada minimal satu UMKM dengan kategori KURANG atau KRITIS.',
        );

        // Minimal ada 1 UMKM BELUM DINILAI.
        $this->assertGreaterThanOrEqual(1, $umkmsWithoutEval, 'Harus ada minimal satu UMKM tanpa evaluasi (BELUM DINILAI).');

        // Total kategori bernilai konsisten dengan total UMKM.
        $this->assertLessThanOrEqual($total, $ratedTotal + $umkmsWithoutEval);
    }

    // -------------------------------------------------------------------------
    // Kasus khusus
    // -------------------------------------------------------------------------

    public function test_at_least_one_umkm_has_no_evaluation(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);

        $sampleUserIds = User::query()
            ->where('email', 'like', 'sample%@joyumkm.test')
            ->pluck('id');

        $countWithoutEval = Umkm::query()
            ->whereIn('user_id', $sampleUserIds)
            ->whereDoesntHave('evaluations')
            ->count();

        $this->assertGreaterThanOrEqual(1, $countWithoutEval, 'Minimal 1 UMKM harus tanpa evaluasi.');
    }

    public function test_at_least_one_umkm_has_draft_as_latest(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);

        $sampleUserIds = User::query()
            ->where('email', 'like', 'sample%@joyumkm.test')
            ->pluck('id');

        $umkmIds = Umkm::query()->whereIn('user_id', $sampleUserIds)->pluck('id');

        $draftLatestCount = Evaluation::query()
            ->whereIn('id', function ($sub) use ($umkmIds): void {
                $sub->selectRaw('MAX(id)')
                    ->from('evaluations')
                    ->whereIn('umkm_id', $umkmIds)
                    ->groupBy('umkm_id');
            })
            ->where('status', 'draft')
            ->count();

        $this->assertGreaterThanOrEqual(1, $draftLatestCount, 'Minimal 1 UMKM harus memiliki draft sebagai latest evaluasi.');
    }

    public function test_at_least_one_umkm_has_partial_factor_coverage(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);

        $sampleUserIds = User::query()
            ->where('email', 'like', 'sample%@joyumkm.test')
            ->pluck('id');

        $umkmIds = Umkm::query()->whereIn('user_id', $sampleUserIds)->pluck('id');

        // UMKM dengan faktor parsial: average_score tidak null tapi ada factor_score null
        // (artinya satu faktor tidak terjawab).
        $partialCount = Evaluation::query()
            ->whereIn('umkm_id', $umkmIds)
            ->where(function ($query): void {
                $query->whereNull('ins_score')
                    ->orWhereNull('ect_score')
                    ->orWhereNull('ops_score');
            })
            ->count();

        $this->assertGreaterThanOrEqual(
            1,
            $partialCount,
            'Minimal 1 evaluasi harus memiliki setidaknya satu faktor skor null (faktor tidak terjawab).',
        );
    }

    // -------------------------------------------------------------------------
    // Urutan tanggal
    // -------------------------------------------------------------------------

    public function test_evaluation_dates_follow_id_order_per_umkm(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);

        $sampleUserIds = User::query()
            ->where('email', 'like', 'sample%@joyumkm.test')
            ->pluck('id');

        $umkmIds = Umkm::query()
            ->whereIn('user_id', $sampleUserIds)
            ->whereHas('evaluations', null, '>=', 2)
            ->pluck('id');

        foreach ($umkmIds as $umkmId) {
            $evals = Evaluation::query()
                ->where('umkm_id', $umkmId)
                ->orderBy('id')
                ->get(['id', 'created_at']);

            for ($i = 1; $i < $evals->count(); $i++) {
                $this->assertGreaterThanOrEqual(
                    $evals[$i - 1]->created_at->timestamp,
                    $evals[$i]->created_at->timestamp,
                    "Evaluasi UMKM {$umkmId}: created_at tidak urut sesuai ID.",
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Nama UMKM unik per akun
    // -------------------------------------------------------------------------

    public function test_umkm_names_are_unique_per_account_case_insensitive(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);

        $sampleUsers = User::query()
            ->where('email', 'like', 'sample%@joyumkm.test')
            ->get();

        foreach ($sampleUsers as $user) {
            $names = $user->umkms()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
            $uniqueNames = array_unique($names);
            $this->assertCount(
                count($names),
                $uniqueNames,
                "Akun {$user->email} memiliki nama UMKM duplikat (case-insensitive).",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_seeding_twice_does_not_add_data(): void
    {
        $this->enableSample();

        $this->seed(SampleDataSeeder::class);
        $countAfterFirst = User::query()->where('email', 'like', 'sample%@joyumkm.test')->count();
        $umkmAfterFirst = Umkm::query()->count();

        $this->seed(SampleDataSeeder::class);
        $countAfterSecond = User::query()->where('email', 'like', 'sample%@joyumkm.test')->count();
        $umkmAfterSecond = Umkm::query()->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'Seed kedua tidak boleh menambah akun sample.');
        $this->assertSame($umkmAfterFirst, $umkmAfterSecond, 'Seed kedua tidak boleh menambah UMKM.');
    }

    public function test_database_seeder_twice_does_not_add_sample(): void
    {
        $this->enableSample();
        $this->enableDemo();
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);
        $totalUsers = User::query()->count();
        $totalUmkms = Umkm::query()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($totalUsers, User::query()->count(), 'DatabaseSeeder dua kali tidak boleh menambah user.');
        $this->assertSame($totalUmkms, Umkm::query()->count(), 'DatabaseSeeder dua kali tidak boleh menambah UMKM.');
    }

    // -------------------------------------------------------------------------
    // Isolasi data demo
    // -------------------------------------------------------------------------

    public function test_sample_seeder_does_not_touch_demo_data(): void
    {
        $this->enableDemo();
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);

        $demoUser = User::query()->where('email', 'demo@joyumkm.test')->firstOrFail();
        $demoUmkmCount = $demoUser->umkms()->count();
        $demoPassword = $demoUser->password;

        $this->enableSample();
        $this->seed(SampleDataSeeder::class);

        // Data demo tidak berubah.
        $this->assertSame($demoPassword, $demoUser->fresh()->password, 'Password demo tidak boleh berubah.');
        $this->assertSame($demoUmkmCount, $demoUser->fresh()->umkms()->count(), 'Jumlah UMKM demo tidak boleh berubah.');
        $this->assertDatabaseHas('users', ['email' => 'demo@joyumkm.test']);
    }

    public function test_sample_data_does_not_affect_demo_account_names(): void
    {
        $this->enableDemo();
        $this->enableSample();
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);

        $demoUser = User::query()->where('email', 'demo@joyumkm.test')->firstOrFail();
        $this->assertSame('Warjon', $demoUser->name);
        $this->assertDatabaseHas('umkms', ['name' => 'Kawalogy', 'user_id' => $demoUser->id]);
    }

    // -------------------------------------------------------------------------
    // Rollback jika gagal
    // -------------------------------------------------------------------------

    public function test_rollback_on_failure_leaves_no_partial_data(): void
    {
        $this->enableSample();

        // Hapus semua sektor sehingga Sector::query()->random() gagal.
        \App\Models\Sector::query()->delete();

        try {
            $this->seed(SampleDataSeeder::class);
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertDatabaseCount('users', 0, 'Rollback harus menghapus semua data partial.');
        $this->assertDatabaseCount('umkms', 0);
    }
}
