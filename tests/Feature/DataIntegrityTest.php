<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Factor;
use App\Models\Question;
use App\Models\Umkm;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DataIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_master_seed_is_repeatable_and_preserves_existing_question_definitions(): void
    {
        $question = Question::query()->firstOrFail();
        $question->update(['text' => 'Definisi yang sudah dipakai']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('factors', 3);
        $this->assertDatabaseCount('questions', 16);
        $this->assertDatabaseCount('sectors', 5);
        $this->assertSame(38, count(config('provinces')));
        $this->assertSame(['INS', 'ECT', 'OPS'], Factor::query()->orderBy('sort_order')->pluck('code')->all());
        $this->assertSame('Definisi yang sudah dipakai', $question->fresh()->text);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_latest_relation_includes_empty_draft_and_delete_cascades_answers(): void
    {
        $umkm = Umkm::factory()->create();
        $final = $umkm->evaluations()->create(['status' => 'final', 'average_score' => 100, 'submitted_at' => now()]);
        $answer = $final->answers()->create(['question_id' => Question::query()->firstOrFail()->id, 'value' => 3]);
        $draft = $umkm->evaluations()->create(['status' => 'draft']);

        $this->assertSame($draft->id, $umkm->fresh()->latestEvaluation->id);
        $this->assertNull($umkm->fresh()->latestEvaluation->average_score);
        $umkm->delete();

        $this->assertModelMissing($final);
        $this->assertModelMissing($draft);
        $this->assertModelMissing($answer);
    }

    public function test_same_name_is_allowed_for_different_owners(): void
    {
        Umkm::factory()->create(['name' => 'Usaha Bersama']);

        $other = Umkm::factory()->create(['name' => 'Usaha Bersama']);

        $this->assertModelExists($other);
        $this->assertDatabaseCount('umkms', 2);
    }

    public function test_database_enforces_case_insensitive_name_unique_per_owner(): void
    {
        $umkm = Umkm::factory()->create(['name' => 'Usaha Bersama']);

        $this->expectException(QueryException::class);
        Umkm::factory()->create(['user_id' => $umkm->user_id, 'name' => 'USAHA BERSAMA']);
    }
}
