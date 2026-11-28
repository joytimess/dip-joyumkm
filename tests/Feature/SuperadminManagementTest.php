<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Umkm;
use App\Models\UmkmOwnershipTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuperadminManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    private function account(UserRole $role, bool $active = true): array
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role->value, 'is_active' => $active])->save();

        return [$user->refresh(), $user->createToken('test')->plainTextToken];
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_role_status_and_admin_boundaries_are_enforced_on_every_request(): void
    {
        [$admin, $adminToken] = $this->account(UserRole::Superadmin);
        [$analyst, $analystToken] = $this->account(UserRole::Analyst);
        [, $userToken] = $this->account(UserRole::User);
        Umkm::factory()->for($analyst)->create();
        Umkm::factory()->for($admin)->create();

        $this->as($adminToken)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.role', 'superadmin')->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/admin/analysts')->assertUnauthorized();
        $this->as($analystToken)->getJson('/api/admin/analysts')->assertForbidden();
        $this->as($userToken)->getJson('/api/admin/ownership-transfers')->assertForbidden();
        $this->as($analystToken)->postJson('/api/admin/analysts', [])->assertForbidden();
        $this->as($adminToken)->getJson('/api/admin/analysts')->assertOk()->assertJsonPath('data.0.id', $analyst->id)
            ->assertJsonPath('data.0.umkms_count', 1);

        // Filter pemilik: hanya Superadmin, hanya Analyst nyata, dan menyempit di daftar maupun ringkasan.
        $this->as($adminToken)->getJson('/api/umkms')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.owner.role', fn ($role) => in_array($role, ['analyst', 'superadmin'], true));
        $this->as($adminToken)->getJson('/api/umkms?analyst_id='.$analyst->id)->assertJsonPath('meta.total', 1);
        $this->as($adminToken)->getJson('/api/umkms/summary?analyst_id='.$analyst->id)
            ->assertJsonPath('data.total_umkms', 1)->assertJsonPath('data.portfolio.total_analysts', 1);
        $this->as($adminToken)->getJson('/api/umkms?analyst_id='.$admin->id)->assertUnprocessable();
        $this->as($analystToken)->getJson('/api/umkms?analyst_id='.$analyst->id)->assertForbidden();
        $this->as($analystToken)->getJson('/api/umkms')->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.owner');
        $this->as($analystToken)->getJson('/api/umkms/summary')->assertJsonMissingPath('data.portfolio');

        // Nonaktifkan: token lama mati, login ditolak, reaktivasi tidak menghidupkan token lama.
        $this->as($adminToken)->patchJson('/api/admin/analysts/'.$analyst->id.'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->as($adminToken)->patchJson('/api/admin/analysts/'.$analyst->id.'/status', ['is_active' => false])->assertOk();
        $this->as($analystToken)->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $analyst->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->as($adminToken)->patchJson('/api/admin/analysts/'.$analyst->id.'/status', ['is_active' => true])->assertOk();
        $this->as($analystToken)->getJson('/api/auth/me')->assertUnauthorized();
        $this->as($adminToken)->patchJson('/api/admin/analysts/'.$admin->id.'/status', ['is_active' => false])->assertNotFound();
    }

    public function test_analyst_creation_uses_server_role_and_dispatches_onboarding_exactly_once(): void
    {
        config([
            'services.hermes_onboarding.enabled' => true,
            'services.hermes_onboarding.url' => 'http://127.0.0.1:8650/joyumkm-onboarding',
            'services.hermes_onboarding.secret' => str_repeat('a', 32),
        ]);
        Http::preventStrayRequests();
        [, $adminToken] = $this->account(UserRole::Superadmin);
        $payload = ['name' => 'Analyst Baru', 'email' => 'Baru.Analyst@Example.test', 'password' => 'JoyUMKM-Testing-2026', 'password_confirmation' => 'JoyUMKM-Testing-2026'];

        $this->as($adminToken)->postJson('/api/admin/analysts', [...$payload, 'role' => 'superadmin'])->assertUnprocessable();
        $this->assertDatabaseCount('jobs', 0);

        $created = $this->as($adminToken)->postJson('/api/admin/analysts', $payload)->assertCreated()
            ->assertJsonPath('data.role', 'analyst')->assertJsonPath('data.email', 'baru.analyst@example.test')
            ->assertJsonMissingPath('data.password')->json('data');
        $this->assertSame('analyst', User::query()->findOrFail($created['id'])->role);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'hermes-onboarding']);

        $this->as($adminToken)->postJson('/api/admin/analysts', $payload)->assertUnprocessable();
        $this->as($adminToken)->patchJson('/api/admin/analysts/'.$created['id'], ['name' => 'Nama Diubah'])
            ->assertOk()->assertJsonPath('data.name', 'Nama Diubah');
        $this->as($adminToken)->patchJson('/api/admin/analysts/'.$created['id'], ['email' => 'lain@example.test'])->assertConflict();
        $this->as($adminToken)->patchJson('/api/admin/analysts/'.$created['id'], ['role' => 'superadmin'])->assertUnprocessable();
        $this->as($adminToken)->patchJson('/api/admin/analysts/'.$created['id'].'/status', ['is_active' => false])->assertOk();
        $this->assertDatabaseCount('jobs', 1);

        $this->postJson('/api/auth/register', [
            'name' => 'Pendaftar', 'email' => 'pendaftar@example.test', 'role' => 'analyst',
            'password' => 'JoyUMKM-Testing-2026', 'password_confirmation' => 'JoyUMKM-Testing-2026',
        ])->assertCreated();
        $this->assertSame('user', User::query()->where('email', 'pendaftar@example.test')->value('role'));
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_transfer_is_atomic_audited_and_blocked_by_every_unsafe_state(): void
    {
        [$admin, $adminToken] = $this->account(UserRole::Superadmin);
        [$from, $fromToken] = $this->account(UserRole::Analyst);
        [$to, $toToken] = $this->account(UserRole::Analyst);
        [$inactive] = $this->account(UserRole::Analyst, false);
        [$plain] = $this->account(UserRole::User);
        $umkm = Umkm::factory()->for($from)->create(['business_email' => 'usaha@example.test']);
        $url = '/api/admin/umkms/'.$umkm->id.'/transfer';
        $body = fn (array $override = []): array => ['expected_owner_id' => $from->id, 'to_user_id' => $to->id, 'reason' => 'Penugasan ulang portofolio', ...$override];

        $this->as($fromToken)->postJson($url, $body())->assertForbidden();
        $this->as($adminToken)->postJson($url, $body(['reason' => '']))->assertUnprocessable();
        $this->as($adminToken)->postJson($url, $body(['user_id' => $to->id]))->assertUnprocessable();
        $this->as($adminToken)->postJson($url, $body(['expected_owner_id' => $to->id]))->assertConflict()->assertJsonPath('code', 'stale_owner');
        $this->as($adminToken)->postJson($url, $body(['to_user_id' => $from->id]))->assertConflict()->assertJsonPath('code', 'same_owner');
        $this->as($adminToken)->postJson($url, $body(['to_user_id' => $inactive->id]))->assertConflict()->assertJsonPath('code', 'target_inactive');
        $this->as($adminToken)->postJson($url, $body(['to_user_id' => $plain->id]))->assertConflict()->assertJsonPath('code', 'invalid_target');
        Umkm::factory()->for($to)->create(['name' => $umkm->name]);
        $this->as($adminToken)->postJson($url, $body())->assertConflict()->assertJsonPath('code', 'name_collision');
        Umkm::query()->where('user_id', $to->id)->delete();

        Umkm::query()->whereKey($umkm->id)->update(['notification_consent_at' => now(), 'notification_consent_version' => 'v1']);
        $this->as($adminToken)->postJson($url, $body())->assertConflict()->assertJsonPath('code', 'consent_active');
        Umkm::query()->whereKey($umkm->id)->update(['notification_consent_at' => null, 'notification_consent_version' => null]);

        $draft = $umkm->evaluations()->forceCreate(['status' => 'draft']);
        $this->as($adminToken)->postJson($url, $body())->assertConflict()->assertJsonPath('code', 'three_dimension_draft');
        $draft->delete();

        $this->assertSame(0, UmkmOwnershipTransfer::query()->count());
        $this->assertSame($from->id, $umkm->refresh()->user_id);

        // Kegagalan audit membatalkan perubahan pemilik.
        UmkmOwnershipTransfer::creating(fn () => throw new \RuntimeException('audit gagal'));
        $this->as($adminToken)->postJson($url, $body())->assertStatus(500);
        UmkmOwnershipTransfer::flushEventListeners();
        $this->assertSame($from->id, $umkm->refresh()->user_id);
        $this->assertSame(0, UmkmOwnershipTransfer::query()->count());

        $this->as($adminToken)->postJson($url, $body())->assertCreated()
            ->assertJsonPath('data.owner.id', $to->id)->assertJsonPath('data.previous_owner.id', $from->id);
        $umkm->refresh();
        $this->assertSame($to->id, $umkm->user_id);
        $this->assertSame('usaha@example.test', $umkm->business_email);
        $audit = UmkmOwnershipTransfer::query()->sole();
        $this->assertSame([$umkm->id, $from->id, $to->id, $admin->id], [$audit->umkm_id, $audit->from_user_id, $audit->to_user_id, $audit->performed_by_user_id]);

        $this->as($fromToken)->getJson('/api/umkms/'.$umkm->id)->assertNotFound();
        $this->as($toToken)->getJson('/api/umkms/'.$umkm->id)->assertOk();
        $this->as($adminToken)->postJson($url, $body())->assertConflict()->assertJsonPath('code', 'stale_owner');
        $this->assertSame(1, UmkmOwnershipTransfer::query()->count());

        $this->as($adminToken)->getJson('/api/admin/ownership-transfers?umkm_id='.$umkm->id)->assertOk()
            ->assertJsonPath('data.0.from.id', $from->id)->assertJsonPath('data.0.performed_by.id', $admin->id)
            ->assertJsonPath('meta.total', 1);
        $this->as($toToken)->getJson('/api/admin/ownership-transfers')->assertForbidden();
        $this->as($adminToken)->deleteJson('/api/umkms/'.$umkm->id)->assertConflict();
        $this->assertSame(1, DB::table('umkms')->where('id', $umkm->id)->count());
    }
}
