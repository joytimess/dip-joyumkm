<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SixFactorEvaluation;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class SixFactorSummaryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasFinal = $this->final_id !== null;

        return [
            'umkm_id' => (int) $this->umkm_id,
            'name' => $this->name,
            'sector' => $this->sector_name,
            'province' => $this->province,
            'has_final' => $hasFinal,
            'latest_final' => $hasFinal ? $this->finalPayload() : null,
        ];
    }

    /** @return array{id: int, total_score: int, category: string, finalized_at: ?string, factors: array<string, int>} */
    private function finalPayload(): array
    {
        $factors = [];
        foreach (SixFactorEvaluation::FACTORS as $factor) {
            $factors[$factor] = (int) $this->{'final_'.$factor};
        }

        return [
            'id' => (int) $this->final_id,
            'total_score' => (int) $this->final_total_score,
            'category' => $this->final_category,
            'finalized_at' => $this->isoDate($this->final_finalized_at),
            'factors' => $factors,
        ];
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->toISOString()
            : Carbon::parse((string) $value, config('app.timezone'))->toISOString();
    }
}
