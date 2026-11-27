<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_local_demo_uses_real_answers_and_expected_scores(): void
    {
        $this->enableDemo();

        $this->seed(DatabaseSeeder::class);

        $owner = User::query()->where('email', 'demo@joyumkm.test')->firstOrFail();
        $this->assertSame('Warjon', $owner->name);
        $this->assertTrue(Hash::check('JoyUMKM-Demo-2026!', $owner->password));
        // DatabaseSeeder lokal juga menanam grup sampel; kontrak demo diuji pada akun dan UMKM demo saja.
        $this->assertSame(1, User::query()->where('email', 'demo@joyumkm.test')->count());
        $this->assertSame(4, $owner->umkms()->count());
        $this->assertSame(4, $this->demoEvaluations($owner)->count());
        $this->assertSame(64, DB::table('evaluation_answers')->whereIn('evaluation_id', $this->demoEvaluations($owner)->select('id'))->count());
        $this->assertSame([96.5, 95.1, 91.7, 87.5], $this->demoEvaluations($owner)->orderBy('id')->pluck('average_score')->all());
        $this->assertSame(['final', 'final', 'final', 'draft'], $this->demoEvaluations($owner)->orderBy('id')->pluck('status')->all());
        $this->assertNotNull($this->demoEvaluations($owner)->orderBy('id')->firstOrFail()->submitted_at);
        $this->assertNull($this->demoEvaluations($owner)->orderByDesc('id')->firstOrFail()->submitted_at);
        $this->assertSame(['Kawalogy', 'Warjon', 'Upside Down', 'ayam geprek'], $owner->umkms()->orderBy('id')->pluck('name')->all());
    }

    public function test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture(): void
    {
        $this->enableDemo();
        $this->seed(DemoSeeder::class);
        $owner = User::query()->where('email', 'demo@joyumkm.test')->firstOrFail();
        $owner->update(['name' => 'Pendamping Lokal', 'password' => 'PasswordBaru2026!']);
        $first = $owner->umkms()->orderBy('id')->firstOrFail();
        $first->update(['name' => 'Usaha diubah', 'omzet' => 99]);
        $first->evaluations()->create(['status' => 'draft']);
        $owner->umkms()->orderByDesc('id')->firstOrFail()->delete();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'demo@joyumkm.test')->count());
        $this->assertSame(3, $owner->umkms()->count());
        $this->assertSame(4, $this->demoEvaluations($owner)->count());
        $this->assertSame('Pendamping Lokal', $owner->fresh()->name);
        $this->assertTrue(Hash::check('PasswordBaru2026!', $owner->fresh()->password));
        $this->assertSame('Usaha diubah', $first->fresh()->name);
        $this->assertSame(99, $first->fresh()->omzet);
    }

    public function test_existing_email_never_receives_demo_data_or_password_changes(): void
    {
        $owner = User::factory()->create(['email' => 'demo@joyumkm.test', 'name' => 'Pemilik Asli']);
        $password = $owner->password;
        $this->enableDemo();

        $this->seed(DemoSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('umkms', 0);
        $this->assertSame($password, $owner->fresh()->password);
        $this->assertSame('Pemilik Asli', $owner->fresh()->name);
    }

    public function test_nonlocal_direct_and_database_seeder_do_not_create_demo(): void
    {
        config(['umkm.demo_password' => 'JoyUMKM-Demo-2026!']);

        $this->seed(DemoSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('umkms', 0);
        $this->assertDatabaseCount('questions', 16);
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_demo_password_does_not_create_account(?string $password): void
    {
        $this->enableDemo();
        config(['umkm.demo_password' => $password]);

        $this->seed(DemoSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('umkms', 0);
    }

    public static function invalidPasswords(): array
    {
        return ['absent' => [null], 'empty' => [''], 'spaces' => ['        '], 'short' => ['short'], 'bcrypt too long' => [str_repeat('x', 73)], 'nul' => ["JoyUMKM\0password"]];
    }

    public function test_evaluation_failure_rolls_back_account_and_all_fixture_rows(): void
    {
        Question::query()->whereHas('factor', fn ($query) => $query->where('code', 'INS'))
            ->firstOrFail()->update(['options' => [['value' => 1, 'label' => 'Tidak']]]);
        $this->enableDemo();

        try {
            $this->seed(DemoSeeder::class);
            $this->fail('Jawaban yang tidak ada dalam opsi harus ditolak.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('answers.0.value', $error->errors());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('umkms', 0);
        $this->assertDatabaseCount('evaluations', 0);
        $this->assertDatabaseCount('evaluation_answers', 0);
    }

    public function test_changed_active_bank_stops_before_creating_partial_demo(): void
    {
        $question = Question::query()->firstOrFail();
        $question->update(['is_active' => false]);
        $this->enableDemo();

        try {
            $this->seed(DemoSeeder::class);
            $this->fail('Bank soal yang tidak sesuai harus ditolak.');
        } catch (LogicException $error) {
            $this->assertSame('Demo JoyUMKM V1 membutuhkan 6 soal INS, 4 soal ECT, dan 6 soal OPS aktif.', $error->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('evaluations', 0);
    }

    /**
     * @return Builder<Evaluation>
     */
    private function demoEvaluations(User $owner): Builder
    {
        return Evaluation::query()->whereIn('umkm_id', $owner->umkms()->select('id'));
    }

    private function enableDemo(): void
    {
        User::query()->count();
        $this->app['env'] = 'local';
        config(['umkm.demo_password' => 'JoyUMKM-Demo-2026!']);
    }
}
