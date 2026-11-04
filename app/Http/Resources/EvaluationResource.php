<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EvaluationAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return ['data' => null];
        }

        return [
            'id' => (int) $this->id,
            'umkm_id' => (int) $this->umkm_id,
            'status' => $this->status,
            'factor_scores' => [
                'INS' => $this->ins_score,
                'ECT' => $this->ect_score,
                'OPS' => $this->ops_score,
            ],
            'average_score' => $this->average_score,
            'category' => $this->category ?? config('umkm.scoring.unrated.label'),
            'answers' => $this->answers->sortBy('question_id')->values()->map(fn (EvaluationAnswer $answer): array => [
                'question_id' => (int) $answer->question_id, 'value' => (int) $answer->value,
            ])->all(),
            'answered_count' => (int) $this->answered_count,
            'total_questions' => (int) $this->total_questions,
            'created_at' => $this->created_at->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
        ];
    }
}
