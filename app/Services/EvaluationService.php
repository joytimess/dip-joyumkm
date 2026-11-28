<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evaluation;
use App\Models\Factor;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class EvaluationService
{
    public function __construct(
        private readonly UmkmService $umkmService,
        private readonly QuestionnaireService $questionnaireService,
        private readonly ScoringService $scoringService,
        private readonly DatabaseManager $database,
    ) {}

    public function latest(User $user, int $id): ?Evaluation
    {
        $umkm = $this->umkmService->owned($user, $id);
        $evaluation = $umkm->latestEvaluation()->with('answers')->first();
        if ($evaluation === null) {
            return null;
        }

        return $this->withCompleteness($evaluation, $this->questionnaireService->activeFactors());
    }

    public function store(User $user, int $id, array $data): Evaluation
    {
        return $this->database->transaction(function () use ($user, $id, $data): Evaluation {
            // Kunci baris UMKM lebih dulu: protokol yang sama dengan jalur Enam Faktor dan transfer kepemilikan.
            $user->accessibleUmkms()->whereKey($id)->lockForUpdate()->firstOrFail();
            $umkm = $this->umkmService->owned($user, $id);
            $factors = $this->questionnaireService->activeFactors();
            $questions = $factors->flatMap(fn (Factor $factor): Collection => $factor->questions)->keyBy('id');
            $errors = [];
            $seen = [];
            $values = [];
            foreach ($data['answers'] as $index => $answer) {
                $questionId = (int) $answer['question_id'];
                $question = $questions->get($questionId);
                if ($question === null) {
                    $errors['answers.'.$index.'.question_id'] = ['Pertanyaan tidak aktif atau tidak dikenal.'];

                    continue;
                }
                if (isset($seen[$questionId])) {
                    $errors['answers.'.$index.'.question_id'] = ['Pertanyaan tidak boleh dijawab lebih dari sekali.'];
                }
                $seen[$questionId] = true;
                $value = (int) $answer['value'];
                if (! in_array($value, array_column($question->options, 'value'), true)) {
                    $errors['answers.'.$index.'.value'] = ['Nilai jawaban tidak tersedia pada pilihan pertanyaan.'];
                }
                $factor = $factors->firstWhere('id', $question->factor_id);
                $values[$factor->code][] = $value;
            }
            if ($data['status'] === 'final') {
                $missingIds = array_values(array_diff($questions->keys()->all(), array_keys($seen)));
                if ($missingIds !== []) {
                    $errors['answers'] = ['Seluruh pertanyaan aktif wajib dijawab. ID yang belum dijawab: '.implode(', ', $missingIds).'.'];
                    $errors['missing_question_ids'] = $missingIds;
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $scores = $this->scoringService->calculate($factors->toArray(), $values);
            $evaluation = $umkm->evaluations()->create([
                'status' => $data['status'],
                'ins_score' => $scores['factor_scores']['INS'],
                'ect_score' => $scores['factor_scores']['ECT'],
                'ops_score' => $scores['factor_scores']['OPS'],
                'average_score' => $scores['average_score'],
                'category' => $scores['average_score'] === null ? null : $scores['category'],
                'submitted_at' => $data['status'] === 'final' ? now() : null,
            ]);
            $evaluation->answers()->createMany(array_map(fn (array $answer): array => [
                'question_id' => (int) $answer['question_id'],
                'value' => (int) $answer['value'],
            ], $data['answers']));

            return $this->withCompleteness($evaluation->load('answers'), $factors);
        });
    }

    private function withCompleteness(Evaluation $evaluation, Collection $factors): Evaluation
    {
        $activeIds = $factors->flatMap(fn (Factor $factor): Collection => $factor->questions)->pluck('id')->all();
        $evaluation->setAttribute('answered_count', $evaluation->answers->whereIn('question_id', $activeIds)->count());
        $evaluation->setAttribute('total_questions', count($activeIds));

        return $evaluation;
    }
}
