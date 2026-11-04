<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Sector;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_all_dashboard_endpoints_require_bearer_authentication(): void
    {
        foreach (['factor-profile?umkm_id=1', 'distribution', 'comparison'] as $path) {
            $this->getJson('/api/dashboard/'.$path)->assertUnauthorized();
        }
    }

    public function test_empty_portfolio_has_null_baselines_and_zero_category_counts(): void
    {
        $this->authenticate();

        $this->getJson('/api/dashboard/comparison')->assertOk()
            ->assertJsonPath('data.umkms', [])->assertJsonPath('data.selected_ids', [])
            ->assertJsonPath('data.portfolio_averages', [null, null, null])
            ->assertJsonPath('data.sample_count_per_factor', [0, 0, 0]);
        $data = $this->getJson('/api/dashboard/distribution')->assertOk()
            ->assertJsonPath('data.total_umkms', 0)->assertJsonPath('data.sectors', [])->json('data');
        $this->assertSame(0, array_sum(array_column($data['categories'], 'count')));
        $this->assertCount(6, $data['categories']);
    }

    public function test_factor_profile_uses_latest_draft_and_per_factor_non_null_denominators(): void
    {
        $owner = $this->authenticate();
        $selected = $this->scoredUmkm($owner, 100);
        $ins = Question::query()->whereHas('factor', fn ($query) => $query->where('code', 'INS'))->firstOrFail();
        $this->postJson('/api/umkms/'.$selected->id.'/evaluations', [
            'status' => 'draft', 'answers' => [['question_id' => $ins->id, 'value' => 1]],
        ])->assertCreated();
        $this->scoredUmkm($owner, 100);
        $empty = $this->scoredUmkm($owner, 80);
        $empty->evaluations()->create(['status' => 'draft']);
        Umkm::factory()->for($owner)->create();
        $this->scoredUmkm(User::factory()->create(), 20);

        $this->getJson('/api/dashboard/factor-profile?umkm_id='.$selected->id)->assertOk()
            ->assertJsonPath('data.umkm.id', $selected->id)
            ->assertJsonPath('data.factors', ['INS', 'ECT', 'OPS'])
            ->assertJsonPath('data.selected_scores', [0, null, null])
            ->assertJsonPath('data.portfolio_averages', [50, 100, 100])
            ->assertJsonPath('data.sample_count_per_factor', [2, 1, 1])
            ->assertJsonPath('data.evaluation_status', 'draft')
            ->assertJsonPath('data.answered_count', 1)->assertJsonPath('data.total_questions', 16);
    }

    public function test_foreign_and_missing_dashboard_ids_return_identical_404_without_partial_data(): void
    {
        $owner = $this->authenticate();
        $own = $this->scoredUmkm($owner, 50);
        $foreign = $this->scoredUmkm(User::factory()->create(), 100);
        $missing = $foreign->id + 100;

        $profile = $this->getJson('/api/dashboard/factor-profile?umkm_id='.$foreign->id)->assertNotFound()->json();
        $this->getJson('/api/dashboard/factor-profile?umkm_id='.$missing)->assertNotFound()->assertExactJson($profile);
        $comparison = $this->getJson('/api/dashboard/comparison?'.http_build_query(['umkm_ids' => [$own->id, $foreign->id]]))
            ->assertNotFound()->json();
        $this->getJson('/api/dashboard/comparison?'.http_build_query(['umkm_ids' => [$own->id, $missing]]))
            ->assertNotFound()->assertExactJson($comparison);
    }

    public function test_distribution_counts_all_umkms_once_and_excludes_old_scores_and_other_accounts(): void
    {
        $owner = $this->authenticate();
        $zero = $this->scoredUmkm($owner, 100);
        $zero->evaluations()->create(['status' => 'final', 'ins_score' => 0, 'ect_score' => 0, 'ops_score' => 0, 'average_score' => 0, 'category' => 'KRITIS']);
        $empty = $this->scoredUmkm($owner, 100);
        $empty->evaluations()->create(['status' => 'draft']);
        Umkm::factory()->for($owner)->create();
        $this->scoredUmkm(User::factory()->create(), 100);

        $data = $this->getJson('/api/dashboard/distribution')->assertOk()->assertJsonPath('data.total_umkms', 3)->json('data');
        $this->assertSame(['SANGAT BAIK' => 0, 'BAIK' => 0, 'CUKUP' => 0, 'KURANG' => 0, 'KRITIS' => 1, 'BELUM DINILAI' => 2], array_column($data['categories'], 'count', 'label'));
        $this->assertSame(0, $data['sectors'][0]['average_score']);
        $this->assertSame(1, $data['sectors'][0]['scored_count']);
        $this->assertSame(1, $data['sectors'][0]['draft_count']);
        $this->assertSame(3, $data['sectors'][0]['total_count']);
    }

    public function test_sector_without_scores_stays_null_and_sector_edit_and_delete_change_datasets(): void
    {
        $owner = $this->authenticate();
        $jasa = Sector::query()->where('slug', 'pertanian')->firstOrFail();
        $moving = $this->scoredUmkm($owner, 50);
        Umkm::factory()->for($owner)->create(['sector_id' => $jasa->id]);
        $this->getJson('/api/dashboard/distribution')->assertOk()
            ->assertJsonCount(2, 'data.sectors')->assertJsonPath('data.sectors.1.average_score', null);

        $this->patchJson('/api/umkms/'.$moving->id, ['sector_id' => $jasa->id])->assertOk();
        $this->getJson('/api/dashboard/distribution')->assertOk()->assertJsonCount(1, 'data.sectors')
            ->assertJsonPath('data.sectors.0.id', $jasa->id)->assertJsonPath('data.sectors.0.average_score', 50);
        $this->deleteJson('/api/umkms/'.$moving->id)->assertOk();
        $this->getJson('/api/dashboard/distribution')->assertOk()
            ->assertJsonPath('data.total_umkms', 1)->assertJsonPath('data.sectors.0.average_score', null);
        $this->getJson('/api/dashboard/comparison')->assertOk()->assertJsonPath('data.selected_ids', []);
    }

    public function test_comparison_defaults_to_top_five_but_baseline_includes_all_scored_umkms(): void
    {
        $owner = $this->authenticate();
        $items = [];
        foreach ([0, 20, 40, 60, 90, 90] as $score) {
            $items[] = $this->scoredUmkm($owner, $score);
        }
        $this->scoredUmkm(User::factory()->create(), 100);

        $this->getJson('/api/dashboard/comparison')->assertOk()
            ->assertJsonPath('data.selected_ids', [$items[4]->id, $items[5]->id, $items[3]->id, $items[2]->id, $items[1]->id])
            ->assertJsonPath('data.portfolio_averages', [50, 50, 50])
            ->assertJsonPath('data.sample_count_per_factor', [6, 6, 6]);
        $this->getJson('/api/dashboard/comparison?'.http_build_query(['umkm_ids' => [$items[2]->id, $items[0]->id]]))->assertOk()
            ->assertJsonPath('data.selected_ids', [$items[2]->id, $items[0]->id])
            ->assertJsonPath('data.umkms.1.scores', [0, 0, 0])
            ->assertJsonPath('data.portfolio_averages', [50, 50, 50]);
    }

    public function test_explicit_empty_comparison_is_distinct_from_default_and_get_body_is_ignored(): void
    {
        $owner = $this->authenticate();
        $this->scoredUmkm($owner, 100);

        $this->getJson('/api/dashboard/comparison?umkm_ids=')->assertOk()
            ->assertJsonPath('data.umkms', [])->assertJsonPath('data.selected_ids', [])
            ->assertJsonPath('data.portfolio_averages', [100, 100, 100]);
        $this->json('GET', '/api/dashboard/comparison', ['umkm_ids' => []])->assertOk()->assertJsonCount(1, 'data.umkms');
    }

    #[DataProvider('invalidComparisonQueries')]
    public function test_invalid_comparison_selection_returns_indonesian_422(string $query, string $field, string $message): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/dashboard/comparison?'.$query)->assertUnprocessable()
            ->assertJsonValidationErrors($field);
        $this->assertSame($message, $response->json('errors')[$field][0]);
    }

    public static function invalidComparisonQueries(): array
    {
        return [
            'scalar' => ['umkm_ids=1', 'umkm_ids', 'Pilihan UMKM harus berupa daftar.'],
            'more than five' => ['umkm_ids[]=1&umkm_ids[]=2&umkm_ids[]=3&umkm_ids[]=4&umkm_ids[]=5&umkm_ids[]=6', 'umkm_ids', 'Pilihan UMKM maksimal 5 item.'],
            'duplicate' => ['umkm_ids[]=1&umkm_ids[]=1', 'umkm_ids.0', 'ID UMKM tidak boleh duplikat.'],
            'non numeric' => ['umkm_ids[]=abc', 'umkm_ids.0', 'ID UMKM harus berupa bilangan bulat.'],
            'not positive' => ['umkm_ids[]=0', 'umkm_ids.0', 'ID UMKM minimal 1.'],
            'object' => ['umkm_ids[chosen]=1', 'umkm_ids', 'Pilihan UMKM harus berupa daftar berurutan.'],
        ];
    }

    public function test_factor_profile_requires_numeric_query_id_and_unrated_umkm_has_null_scores(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();

        $this->getJson('/api/dashboard/factor-profile')->assertUnprocessable()->assertJsonValidationErrors('umkm_id');
        $this->getJson('/api/dashboard/factor-profile?umkm_id=abc')->assertUnprocessable()->assertJsonValidationErrors('umkm_id');
        $this->getJson('/api/dashboard/factor-profile?umkm_id=0')->assertUnprocessable()
            ->assertJsonPath('errors.umkm_id.0', 'ID UMKM minimal 1.');
        $this->getJson('/api/dashboard/factor-profile?umkm_id='.$umkm->id)->assertOk()
            ->assertJsonPath('data.selected_scores', [null, null, null])->assertJsonPath('data.evaluation_status', 'none');
    }

    private function authenticate(): User
    {
        $owner = User::factory()->create();
        $this->withToken($owner->createToken('test')->plainTextToken);

        return $owner;
    }

    private function scoredUmkm(User $owner, float $score): Umkm
    {
        $umkm = Umkm::factory()->for($owner)->create();
        $umkm->evaluations()->create([
            'status' => 'final', 'ins_score' => $score, 'ect_score' => $score, 'ops_score' => $score,
            'average_score' => $score, 'category' => match (true) {
                $score >= 85 => 'SANGAT BAIK', $score >= 70 => 'BAIK', $score >= 55 => 'CUKUP',
                $score >= 40 => 'KURANG', default => 'KRITIS',
            },
        ]);

        return $umkm;
    }
}
