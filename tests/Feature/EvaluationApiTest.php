<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\Question;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class EvaluationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_questionnaire_is_protected_dynamic_and_has_special_capital_options(): void
    {
        $this->getJson('/api/questionnaire')->assertUnauthorized();
        $this->authenticate();
        $response = $this->getJson('/api/questionnaire')->assertOk()
            ->assertJsonCount(3, 'data.factors')
            ->assertJsonCount(6, 'data.factors.0.questions')
            ->assertJsonCount(4, 'data.factors.1.questions')
            ->assertJsonCount(6, 'data.factors.2.questions')
            ->assertJsonPath('data.factors.0.code', 'INS')
            ->assertJsonPath('data.factors.0.questions.5.options.0.label', 'Modal Sendiri')
            ->assertJsonPath('data.factors.0.questions.5.options.2.label', 'Bank/Kredit');
        $this->assertSame(config('umkm.scoring'), $response->json('data.scoring'));
    }

    public function test_latest_without_evaluation_returns_explicit_data_null(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();

        $this->getJson('/api/umkms/'.$umkm->id.'/evaluations/latest')
            ->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_all_shared_fixture_scores_are_recomputed_and_persisted_through_api(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();
        $questionnaire = $this->getJson('/api/questionnaire')->assertOk()->json('data');
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/scoring.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures['cases'] as $case) {
            $answers = [];
            foreach ($questionnaire['factors'] as $factor) {
                foreach ($case['values'][$factor['code']] as $index => $value) {
                    $answers[] = ['question_id' => $factor['questions'][$index]['id'], 'value' => $value];
                }
            }
            $status = count($answers) === 16 ? 'final' : 'draft';
            $response = $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', [
                'status' => $status, 'answers' => $answers,
            ])->assertCreated()->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.category', $case['expected']['category'])
                ->assertJsonPath('data.answered_count', count($answers))
                ->assertJsonPath('data.total_questions', 16)
                ->assertJsonCount(count($answers), 'data.answers');
            $this->assertEquals($case['expected']['factor_scores'], $response->json('data.factor_scores'), $case['name']);
            $this->assertEquals($case['expected']['average_score'], $response->json('data.average_score'), $case['name']);
            $evaluation = Evaluation::query()->findOrFail($response->json('data.id'));
            $this->assertSame(count($answers), $evaluation->answers()->count());
            $this->assertSame($status === 'final', $evaluation->submitted_at !== null);
            $this->assertEquals($case['expected']['average_score'], $evaluation->average_score);
            if ($case['expected']['average_score'] === null) {
                $this->assertNull($evaluation->category);
            }
        }
        $this->assertSame(10, $umkm->evaluations()->count());
    }

    public function test_empty_draft_after_final_is_new_latest_without_overwriting_history(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();
        $final = $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', [
            'status' => 'final', 'answers' => $this->allAnswers(),
        ])->assertCreated()->assertJsonPath('data.average_score', 100);
        $draft = $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', [
            'status' => 'draft', 'answers' => [], 'record_id' => 999999,
        ])->assertCreated()->assertJsonPath('data.average_score', null)
            ->assertJsonPath('data.category', 'BELUM DINILAI')
            ->assertJsonPath('data.factor_scores.INS', null)->assertJsonPath('data.submitted_at', null);

        $this->assertGreaterThan($final->json('data.id'), $draft->json('data.id'));
        $this->getJson('/api/umkms/'.$umkm->id.'/evaluations/latest')->assertOk()
            ->assertJsonPath('data.id', $draft->json('data.id'))->assertJsonPath('data.average_score', null);
        $this->getJson('/api/umkms/'.$umkm->id)->assertOk()
            ->assertJsonPath('data.latest_score', null)->assertJsonPath('data.evaluation_status', 'draft');
        $this->assertDatabaseHas('evaluations', ['id' => $final->json('data.id'), 'status' => 'final', 'average_score' => 100]);
        $this->assertSame(2, $umkm->evaluations()->count());
    }

    public function test_final_completeness_provides_missing_question_ids_and_writes_nothing(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();
        $answers = $this->allAnswers();
        $response = $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', [
            'status' => 'final', 'answers' => [$answers[0]],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers')
            ->assertJsonCount(15, 'errors.missing_question_ids');

        $this->assertSame(array_column(array_slice($answers, 1), 'question_id'), $response->json('errors.missing_question_ids'));
        $this->assertStringContainsString((string) $answers[1]['question_id'], $response->json('errors.answers.0'));
        $this->assertDatabaseCount('evaluations', 0);
        $this->assertDatabaseCount('evaluation_answers', 0);
    }

    public function test_invalid_shapes_status_duplicates_options_and_client_scores_return_422_without_rows(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();
        $answer = $this->allAnswers()[0];
        foreach ([
            [[], 'status'],
            [['status' => 'draft'], 'answers'],
            [['status' => 'bad', 'answers' => []], 'status'],
            [['status' => 'draft', 'answers' => null], 'answers'],
            [['status' => 'draft', 'answers' => [$answer, $answer]], 'answers.0.question_id'],
            [['status' => 'draft', 'answers' => [['question_id' => $answer['question_id'], 'value' => 99]]], 'answers.0.value'],
            [['status' => 'draft', 'answers' => [['question_id' => $answer['question_id'], 'value' => 1.5]]], 'answers.0.value'],
            [['status' => 'draft', 'answers' => [['question_id' => 999999, 'value' => 1]]], 'answers.0.question_id'],
            [['status' => 'draft', 'answers' => [], 'average_score' => 100], 'average_score'],
            [['status' => 'draft', 'answers' => ['x' => $answer]], 'answers'],
        ] as [$payload, $errorField]) {
            $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors($errorField);
        }
        $this->assertDatabaseCount('evaluations', 0);
        $this->assertDatabaseCount('evaluation_answers', 0);
    }

    public function test_inactive_questions_are_rejected_and_new_questions_remain_unanswered_on_prefill(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();
        $answers = $this->allAnswers();
        $saved = $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', [
            'status' => 'final', 'answers' => $answers,
        ])->assertCreated();
        $oldQuestion = Question::query()->findOrFail($answers[0]['question_id']);
        $oldQuestion->update(['is_active' => false]);
        $newQuestion = $oldQuestion->factor->questions()->create([
            'sort_order' => 99, 'text' => 'Pertanyaan tambahan',
            'input_type' => 'labeled_radio', 'options' => $oldQuestion->options, 'is_active' => true,
        ]);

        $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', [
            'status' => 'draft', 'answers' => [$answers[0]],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.0.question_id');
        $latest = $this->getJson('/api/umkms/'.$umkm->id.'/evaluations/latest')->assertOk()
            ->assertJsonPath('data.id', $saved->json('data.id'))
            ->assertJsonPath('data.answered_count', 15)->assertJsonPath('data.total_questions', 16)
            ->assertJsonPath('data.average_score', 100);
        $this->assertNotContains($newQuestion->id, array_column($latest->json('data.answers'), 'question_id'));
        $this->assertDatabaseCount('evaluations', 1);
    }

    public function test_foreign_latest_and_save_with_bad_payload_return_404_before_validation(): void
    {
        $foreign = Umkm::factory()->create();
        $this->authenticate();

        $this->getJson('/api/umkms/'.$foreign->id.'/evaluations/latest')->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $this->postJson('/api/umkms/'.$foreign->id.'/evaluations', [])->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $this->postJson('/api/umkms/999999/evaluations', [])->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $this->assertDatabaseCount('evaluations', 0);
    }

    public function test_storage_failure_after_an_answer_insert_rolls_back_evaluation_and_answers(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();
        $answers = $this->allAnswers();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into `evaluation_answers`')) {
                throw new RuntimeException('Simulasi kegagalan penyimpanan jawaban.');
            }
        });

        $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', [
            'status' => 'final', 'answers' => $answers,
        ])->assertServerError()->assertExactJson([
            'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.',
        ]);
        $this->assertDatabaseCount('evaluations', 0);
        $this->assertDatabaseCount('evaluation_answers', 0);
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    private function allAnswers(): array
    {
        $questionnaire = $this->getJson('/api/questionnaire')->assertOk()->json('data');

        return collect($questionnaire['factors'])->flatMap(fn (array $factor): array => array_map(
            fn (array $question): array => ['question_id' => $question['id'], 'value' => $factor['scale_max']],
            $factor['questions'],
        ))->values()->all();
    }
}
