<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sector;
use App\Models\Umkm;
use App\Models\User;
use App\Services\UmkmSummaryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UmkmSummaryApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_summary_is_protected_and_empty_account_has_neutral_null_values(): void
    {
        $this->getJson('/api/umkms/summary')->assertUnauthorized();
        $this->authenticate();

        $this->getJson('/api/umkms/summary')->assertOk()->assertExactJson(['data' => [
            'total_umkms' => 0, 'total_respondents' => 0, 'scored_count' => 0, 'draft_count' => 0,
            'average_score' => null, 'category' => 'BELUM DINILAI', 'critical_sector' => null,
        ]]);
    }

    public function test_latest_zero_and_empty_draft_replace_old_finals_without_fallback(): void
    {
        $owner = $this->authenticate();
        $zero = Umkm::factory()->for($owner)->create();
        $zero->evaluations()->create(['status' => 'final', 'average_score' => 100, 'category' => 'SANGAT BAIK']);
        $zero->evaluations()->create(['status' => 'final', 'average_score' => 0, 'category' => 'KRITIS']);
        $emptyDraft = Umkm::factory()->for($owner)->create();
        $emptyDraft->evaluations()->create(['status' => 'final', 'average_score' => 80, 'category' => 'BAIK']);
        $emptyDraft->evaluations()->create(['status' => 'draft']);
        Umkm::factory()->for($owner)->create();

        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.total_umkms', 3)->assertJsonPath('data.total_respondents', 2)
            ->assertJsonPath('data.scored_count', 1)->assertJsonPath('data.draft_count', 1)
            ->assertJsonPath('data.average_score', 0)->assertJsonPath('data.category', 'KRITIS')
            ->assertJsonPath('data.critical_sector.average_score', 0)
            ->assertJsonPath('data.critical_sector.needs_intervention', true);
    }

    public function test_accounts_are_isolated_and_summary_ignores_table_filters(): void
    {
        $owner = $this->authenticate();
        $this->scoredUmkm($owner, 80);
        $this->scoredUmkm($owner, 0, 'draft');
        $other = User::factory()->create();
        $this->scoredUmkm($other, 100);

        $this->getJson('/api/umkms/summary?search=TidakCocok&status=none')->assertOk()
            ->assertJsonPath('data.total_umkms', 2)->assertJsonPath('data.total_respondents', 2)
            ->assertJsonPath('data.average_score', 40)->assertJsonPath('data.draft_count', 1);
        $this->authenticate($other);
        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.total_umkms', 1)->assertJsonPath('data.average_score', 100);
    }

    public function test_unscored_sector_counts_are_retained_but_do_not_become_critical(): void
    {
        $owner = $this->authenticate();
        $sector = Sector::query()->where('slug', 'jasa')->firstOrFail();
        Umkm::factory()->for($owner)->create(['sector_id' => $sector->id]);
        $draft = Umkm::factory()->for($owner)->create(['sector_id' => $sector->id]);
        $draft->evaluations()->create(['status' => 'draft']);

        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.total_umkms', 2)->assertJsonPath('data.total_respondents', 1)
            ->assertJsonPath('data.scored_count', 0)->assertJsonPath('data.draft_count', 1)
            ->assertJsonPath('data.average_score', null)->assertJsonPath('data.critical_sector', null);
        $this->assertSame([[
            'id' => $sector->id, 'name' => 'Jasa', 'average_score' => null,
            'total_count' => 2, 'scored_count' => 0, 'draft_count' => 1,
        ]], $this->app->make(UmkmSummaryService::class)->sectorAverages($owner));
    }

    public function test_sector_change_moves_latest_contribution_and_delete_refreshes_summary(): void
    {
        $owner = $this->authenticate();
        $firstSector = Sector::query()->where('slug', 'jasa')->firstOrFail();
        $secondSector = Sector::query()->where('slug', 'pertanian')->firstOrFail();
        $moving = $this->scoredUmkm($owner, 20, 'final', $firstSector);
        $this->scoredUmkm($owner, 80, 'final', $secondSector);
        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.critical_sector.id', $firstSector->id);

        $this->patchJson('/api/umkms/'.$moving->id, ['sector_id' => $secondSector->id])->assertOk();
        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.critical_sector.id', $secondSector->id)
            ->assertJsonPath('data.critical_sector.average_score', 50)
            ->assertJsonPath('data.critical_sector.total_count', 2);
        $this->deleteJson('/api/umkms/'.$moving->id)->assertOk();
        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.total_umkms', 1)->assertJsonPath('data.average_score', 80)
            ->assertJsonPath('data.critical_sector.needs_intervention', false);
    }

    public function test_sector_intervention_uses_rounded_mean_at_seventy_boundary(): void
    {
        $owner = $this->authenticate();
        $sector = Sector::query()->where('slug', 'jasa')->firstOrFail();
        $this->scoredUmkm($owner, 69.9, 'draft', $sector);
        $this->scoredUmkm($owner, 70, 'final', $sector);

        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.average_score', 70)->assertJsonPath('data.category', 'BAIK')
            ->assertJsonPath('data.critical_sector.average_score', 70)
            ->assertJsonPath('data.critical_sector.draft_count', 1)
            ->assertJsonPath('data.critical_sector.scored_count', 2)
            ->assertJsonPath('data.critical_sector.needs_intervention', false);
    }

    public function test_critical_sector_tie_uses_rounded_mean_then_name_instead_of_unrounded_mean(): void
    {
        $owner = $this->authenticate();
        $lastName = Sector::query()->create(['name' => 'Zeta', 'slug' => 'zeta']);
        $firstName = Sector::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->scoredUmkm($owner, 70, 'final', $lastName);
        foreach ([70, 70, 70, 70.1, 70.1] as $score) {
            $this->scoredUmkm($owner, $score, 'final', $firstName);
        }

        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.critical_sector.id', $firstName->id)
            ->assertJsonPath('data.critical_sector.average_score', 70);
    }

    public function test_critical_sector_equal_name_and_score_use_id_ascending(): void
    {
        $owner = $this->authenticate();
        $firstSector = Sector::query()->create(['name' => 'Kembar', 'slug' => 'kembar-awal']);
        $secondSector = Sector::query()->create(['name' => 'Kembar', 'slug' => 'kembar-akhir']);
        $this->scoredUmkm($owner, 40, 'final', $secondSector);
        $this->scoredUmkm($owner, 40, 'final', $firstSector);

        $this->getJson('/api/umkms/summary')->assertOk()
            ->assertJsonPath('data.critical_sector.id', $firstSector->id);
    }

    private function authenticate(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    private function scoredUmkm(User $owner, float $score, string $status = 'final', ?Sector $sector = null): Umkm
    {
        $umkm = Umkm::factory()->for($owner)->create(
            $sector === null ? [] : ['sector_id' => $sector->id],
        );
        $umkm->evaluations()->create(['status' => $status, 'average_score' => $score, 'category' => 'BAIK']);

        return $umkm;
    }
}
