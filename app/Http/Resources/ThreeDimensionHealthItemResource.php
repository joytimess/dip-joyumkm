<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ThreeDimensionHealthItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasFinal = $this->final_id !== null;
        $hasActiveDraft = $this->draft_id !== null
            && ($this->final_id === null || (int) $this->draft_id > (int) $this->final_id);

        return [
            'umkm_id' => (int) $this->umkm_id,
            'name' => $this->name,
            'state' => match (true) {
                $hasFinal => 'final',
                $this->draft_id !== null => 'draft',
                default => 'none',
            },
            'has_active_draft' => $hasActiveDraft,
            'latest_final' => $hasFinal ? $this->finalPayload() : null,
            'draft' => $hasActiveDraft ? [
                'evaluation_id' => (int) $this->draft_id,
                'answered_count' => (int) $this->draft_answered_count,
                'question_count' => (int) $this->question_count,
                'updated_at' => $this->isoDate($this->draft_updated_at),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function finalPayload(): array
    {
        $overallScore = $this->floatOrNull($this->final_average_score);
        $dimensions = [
            'INS' => $this->floatOrNull($this->final_ins_score),
            'ECT' => $this->floatOrNull($this->final_ect_score),
            'OPS' => $this->floatOrNull($this->final_ops_score),
        ];

        return [
            'evaluation_id' => (int) $this->final_id,
            'overall_score' => $overallScore,
            'category' => $this->final_category,
            'answered_count' => (int) $this->final_answered_count,
            'question_count' => (int) $this->question_count,
            'dimensions' => $dimensions,
            'finalized_at' => $this->isoDate($this->final_finalized_at),
            'is_complete' => $overallScore !== null
                && $this->final_category !== null
                && ! in_array(null, $dimensions, true),
        ];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->toISOString()
            : Carbon::parse((string) $value)->toISOString();
    }
}
