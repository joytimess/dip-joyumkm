<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AnalyticsDatasetCohort;
use App\Models\AnalyticsDatasetMember;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RoleFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    private const SECRET = 'snapshot-test-secret-0123456789abcdef';

    private function tokenFor(UserRole $role): array
    {
        $user = User::factory()->create();
        User::query()->whereKey($user->id)->update(['role' => $role->value]);

        return [$user->refresh(), $user->createToken('test')->plainTextToken];
    }

    public function test_public_registration_always_creates_a_plain_user_and_ignores_role_input(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Pendaftar Baru', 'email' => 'baru@example.test', 'role' => 'superadmin',
            'password' => 'rahasia-panjang-1', 'password_confirmation' => 'rahasia-panjang-1',
        ])->assertCreated();

        $this->assertSame('user', User::query()->where('email', 'baru@example.test')->value('role'));
    }

    public function test_database_rejects_unknown_roles_and_unknown_stored_roles_never_become_privileged(): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        try {
            DB::table('users')->where('id', $user->id)->update(['role' => 'root']);
        } finally {
            $unknown = (new User)->forceFill(['role' => 'root']);
            $this->assertSame(UserRole::User, $unknown->userRole());
            $this->assertFalse($unknown->isSuperadmin());
        }
    }

    public function test_superadmin_reads_globally_while_analyst_and_user_only_see_their_own(): void
    {
        [$admin, $adminToken] = $this->tokenFor(UserRole::Superadmin);
        [$analyst, $analystToken] = $this->tokenFor(UserRole::Analyst);
        [$user, $userToken] = $this->tokenFor(UserRole::User);
        $adminUmkm = Umkm::factory()->for($admin)->create();
        $analystUmkm = Umkm::factory()->for($analyst)->create();
        $userUmkm = Umkm::factory()->for($user)->create();

        $this->withToken($adminToken)->getJson('/api/umkms/'.$userUmkm->id)->assertOk();
        $this->withToken($adminToken)->getJson('/api/umkms/'.$analystUmkm->id.'/intelligence-reports')->assertOk();
        $this->withToken($adminToken)->getJson('/api/umkms/summary')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($analystToken)->getJson('/api/umkms/'.$analystUmkm->id)->assertOk();
        $this->withToken($analystToken)->getJson('/api/umkms/'.$userUmkm->id)->assertNotFound();
        $this->withToken($analystToken)->getJson('/api/umkms/'.$userUmkm->id.'/intelligence-reports')->assertNotFound();
        $this->withToken($analystToken)->getJson('/api/umkms/'.$userUmkm->id.'/six-factor-evaluations/draft')->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($userToken)->getJson('/api/umkms/'.$adminUmkm->id)->assertNotFound();
        $this->withToken($userToken)->patchJson('/api/umkms/'.$analystUmkm->id, ['name' => 'Ambil Alih'])->assertNotFound();

        $this->assertSame($user->id, $userUmkm->fresh()->user_id);
        $this->assertSame($analyst->id, $analystUmkm->fresh()->user_id);
    }

    public function test_superadmin_edit_keeps_the_owner_and_cannot_grant_notification_consent_for_someone_else(): void
    {
        [, $adminToken] = $this->tokenFor(UserRole::Superadmin);
        $owner = User::factory()->create();
        $umkm = Umkm::factory()->for($owner)->create(['business_email' => 'pemilik@example.test']);

        $this->withToken($adminToken)->patchJson('/api/umkms/'.$umkm->id, ['name' => 'Nama Diubah Superadmin'])->assertOk();
        $this->assertSame($owner->id, $umkm->fresh()->user_id);
        $this->assertSame('Nama Diubah Superadmin', $umkm->fresh()->name);

        $this->withToken($adminToken)->patchJson('/api/umkms/'.$umkm->id, [
            'notification_consent' => 'grant',
            'expected_business_email' => (string) $umkm->business_email,
            'consented_business_email' => (string) $umkm->business_email,
        ])->assertForbidden();
        $this->assertNull($umkm->fresh()->notification_consent_at);
    }

    public function test_transfer_and_analyst_management_abilities_are_denied_for_every_role(): void
    {
        $umkm = Umkm::factory()->create();
        foreach (UserRole::cases() as $role) {
            [$actor] = $this->tokenFor($role);
            $this->assertTrue(Gate::forUser($actor)->denies('transfer', $umkm));
            $this->assertTrue(Gate::forUser($actor)->denies('manageAnalysts', Umkm::class));
        }
    }

    public function test_monitoring_excludes_cohort_members_before_the_limit(): void
    {
        config([
            'services.hermes_intelligence_snapshot.secret' => self::SECRET,
            'services.hermes_intelligence_snapshot.tolerance_seconds' => 300,
        ]);
        $this->freezeSecond();
        $cohortOwner = User::factory()->create();
        $cohort = AnalyticsDatasetCohort::query()->forceCreate([
            'dataset_key' => 'test-cohort', 'dataset_version' => 'v1', 'user_id' => $cohortOwner->id,
            'reference_start_month' => '2026-01', 'reference_end_month' => '2026-12', 'umkm_count' => 101,
            'three_dimension_count' => 0, 'six_factor_count' => 0, 'answer_count' => 0,
            'content_fingerprint' => str_repeat('a', 64), 'completed_at' => now(),
        ]);
        foreach (Umkm::factory()->count(101)->for($cohortOwner)->create() as $member) {
            AnalyticsDatasetMember::query()->create([
                'analytics_dataset_cohort_id' => $cohort->id, 'umkm_id' => $member->id, 'synthetic_key' => $member->name,
            ]);
        }
        $real = Umkm::factory()->create();

        $path = '/api/internal/monitoring/six-factor/latest-finals';
        $timestamp = now()->getTimestamp();
        $response = $this->get($path, [
            'Accept' => 'application/json',
            'X-Intelligence-Snapshot-Timestamp' => (string) $timestamp,
            'X-Intelligence-Snapshot-Signature' => hash_hmac('sha256', $timestamp."\nGET\n".$path, self::SECRET),
        ])->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.umkm_id', $real->id);
        $response->assertJsonPath('meta.count', 1)->assertJsonPath('meta.truncated', false);
    }
}
