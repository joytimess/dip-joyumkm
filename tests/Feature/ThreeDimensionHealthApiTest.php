<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\Question;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ThreeDimensionHealthApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_three_dimension_endpoints_require_bearer_authentication(): void
    {
        $this->getJson('/api/dashboard/three-dimension-health')->assertUnauthorized();
        $this->getJson('/api/dashboard/three-dimension-detail?umkm_id=1')->assertUnauthorized();
    }

    public function test_empty_portfolio_returns_null_averages_and_zero_counts(): void
    {
        $this->authenticate();

        $this->getJson('/api/dashboard/three-dimension-health')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary.total_umkms', 0)
            ->assertJsonPath('summary.with_final', 0)
            ->assertJsonPath('summary.draft_only', 0)
            ->assertJsonPath('summary.not_assessed', 0)
            ->assertJsonPath('summary.overall_final_average', null)
            ->assertJsonPath('summary.dimension_final_averages', ['INS' => null, 'ECT' => null, 'OPS' => null]);
    }

    public function test_item_uses_latest_final_and_flags_newer_draft_without_replacing_score(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create(['name' => 'Final dan Draft']);
        $this->final($umkm, 60, 'CUKUP');
        $latestFinal = $this->final($umkm, 90.3, 'SANGAT BAIK', ins: 83.3, ect: 100, ops: 87.5);
        $draft = $this->draft($umkm, [$this->activeQuestionId(0), $this->activeQuestionId(1)]);

        $this->getJson('/api/dashboard/three-dimension-health')->assertOk()
            ->assertJsonPath('data.0.umkm_id', $umkm->id)
            ->assertJsonPath('data.0.state', 'final')
            ->assertJsonPath('data.0.has_active_draft', true)
            ->assertJsonPath('data.0.latest_final.evaluation_id', $latestFinal->id)
            ->assertJsonPath('data.0.latest_final.overall_score', 90.3)
            ->assertJsonPath('data.0.latest_final.category', 'SANGAT BAIK')
            ->assertJsonPath('data.0.latest_final.dimensions.INS', 83.3)
            ->assertJsonPath('data.0.latest_final.is_complete', true)
            ->assertJsonPath('data.0.draft.evaluation_id', $draft->id)
            ->assertJsonPath('data.0.draft.answered_count', 2)
            ->assertJsonPath('data.0.draft.question_count', Question::query()->where('is_active', true)->count());
    }

    public function test_draft_older_than_final_is_not_active_and_draft_only_umkm_has_no_official_score(): void
    {
        $owner = $this->authenticate();
        $finalized = Umkm::factory()->for($owner)->create(['name' => 'Draft Lama']);
        $this->draft($finalized, []);
        $this->final($finalized, 70, 'BAIK');
        $draftOnly = Umkm::factory()->for($owner)->create(['name' => 'Baru Draft']);
        $this->draft($draftOnly, [$this->activeQuestionId(0)]);

        $response = $this->getJson('/api/dashboard/three-dimension-health')->assertOk();
        $items = collect($response->json('data'))->keyBy('umkm_id');

        $this->assertFalse($items[$finalized->id]['has_active_draft']);
        $this->assertNull($items[$finalized->id]['draft']);
        $this->assertSame('draft', $items[$draftOnly->id]['state']);
        $this->assertNull($items[$draftOnly->id]['latest_final']);
        $this->assertSame(1, $items[$draftOnly->id]['draft']['answered_count']);
    }

    public function test_summary_averages_only_latest_final_per_umkm_and_ignores_drafts_and_other_accounts(): void
    {
        $owner = $this->authenticate();
        $first = Umkm::factory()->for($owner)->create();
        $this->final($first, 50, 'KURANG', ins: 40, ect: 60, ops: 50);
        $this->final($first, 80, 'BAIK', ins: 80, ect: 80, ops: 80);
        $second = Umkm::factory()->for($owner)->create();
        $this->final($second, 90, 'SANGAT BAIK', ins: 90, ect: 90, ops: 90);
        $this->draft($second, [$this->activeQuestionId(0)]);
        $this->draft(Umkm::factory()->for($owner)->create(), [$this->activeQuestionId(0)]);
        Umkm::factory()->for($owner)->create();
        $this->final(Umkm::factory()->for(User::factory()->create())->create(), 0, 'KRITIS');

        $this->getJson('/api/dashboard/three-dimension-health')->assertOk()
            ->assertJsonPath('summary.total_umkms', 4)
            ->assertJsonPath('summary.with_final', 2)
            ->assertJsonPath('summary.draft_only', 1)
            ->assertJsonPath('summary.not_assessed', 1)
            ->assertJsonPath('summary.overall_final_average', 85)
            ->assertJsonPath('summary.dimension_final_averages', ['INS' => 85, 'ECT' => 85, 'OPS' => 85]);
    }

    public function test_status_filter_changes_list_but_not_summary_and_paginates_full_scope(): void
    {
        $owner = $this->authenticate();
        $final = Umkm::factory()->for($owner)->create(['name' => 'A Final']);
        $this->final($final, 70, 'BAIK');
        $draft = Umkm::factory()->for($owner)->create(['name' => 'B Draft']);
        $this->draft($draft, []);
        Umkm::factory()->for($owner)->create(['name' => 'C Kosong']);

        $this->getJson('/api/dashboard/three-dimension-health?status=draft')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.umkm_id', $draft->id)
            ->assertJsonPath('summary.total_umkms', 3)->assertJsonPath('summary.with_final', 1);
        $this->getJson('/api/dashboard/three-dimension-health?status=none')->assertOk()
            ->assertJsonPath('data.0.state', 'none')->assertJsonPath('data.0.latest_final', null);
        $this->getJson('/api/dashboard/three-dimension-health?status=final')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.umkm_id', $final->id);
        $this->getJson('/api/dashboard/three-dimension-health?per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.total_umkms', 3);
    }

    public function test_invalid_status_and_per_page_return_validation_errors(): void
    {
        $this->authenticate();

        $this->getJson('/api/dashboard/three-dimension-health?status=unknown')->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->getJson('/api/dashboard/three-dimension-health?per_page=51')->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_detail_returns_one_umkm_and_foreign_or_missing_ids_return_identical_404(): void
    {
        $owner = $this->authenticate();
        $own = Umkm::factory()->for($owner)->create();
        $this->final($own, 85, 'SANGAT BAIK', ins: 85, ect: 85, ops: 85);
        $foreign = Umkm::factory()->for(User::factory()->create())->create();
        $this->final($foreign, 10, 'KRITIS', ins: 10, ect: 10, ops: 10);
        $missing = $foreign->id + 100;

        $this->getJson('/api/dashboard/three-dimension-detail?umkm_id='.$own->id)->assertOk()
            ->assertJsonPath('data.umkm_id', $own->id)->assertJsonPath('data.state', 'final');

        $profile = $this->getJson('/api/dashboard/three-dimension-detail?umkm_id='.$foreign->id)->assertNotFound()->json();
        $this->getJson('/api/dashboard/three-dimension-detail?umkm_id='.$missing)->assertNotFound()->assertExactJson($profile);
    }

    public function test_detail_for_unassessed_umkm_has_no_official_fields(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();

        $this->getJson('/api/dashboard/three-dimension-detail?umkm_id='.$umkm->id)->assertOk()
            ->assertJsonPath('data.state', 'none')
            ->assertJsonPath('data.has_active_draft', false)
            ->assertJsonPath('data.latest_final', null)
            ->assertJsonPath('data.draft', null);
    }

    public function test_final_without_complete_scores_is_flagged_incomplete(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();
        $umkm->evaluations()->create(['status' => 'final', 'average_score' => 70, 'category' => 'BAIK']);

        $this->getJson('/api/dashboard/three-dimension-detail?umkm_id='.$umkm->id)->assertOk()
            ->assertJsonPath('data.state', 'final')
            ->assertJsonPath('data.latest_final.dimensions.INS', null)
            ->assertJsonPath('data.latest_final.is_complete', false);
    }

    private function authenticate(): User
    {
        $owner = User::factory()->create();
        $this->withToken($owner->createToken('test')->plainTextToken);

        return $owner;
    }

    private function final(Umkm $umkm, float $average, string $category, ?float $ins = null, ?float $ect = null, ?float $ops = null): Evaluation
    {
        return $umkm->evaluations()->create([
            'status' => 'final',
            'ins_score' => $ins ?? $average,
            'ect_score' => $ect ?? $average,
            'ops_score' => $ops ?? $average,
            'average_score' => $average,
            'category' => $category,
            'submitted_at' => now(),
        ]);
    }

    /** @param  array<int, int>  $questionIds */
    private function draft(Umkm $umkm, array $questionIds): Evaluation
    {
        $draft = $umkm->evaluations()->create(['status' => 'draft']);
        foreach ($questionIds as $questionId) {
            $draft->answers()->create(['question_id' => $questionId, 'value' => 1]);
        }

        return $draft;
    }

    private function activeQuestionId(int $offset): int
    {
        return (int) Question::query()->where('is_active', true)->orderBy('id')->skip($offset)->value('id');
    }
}
