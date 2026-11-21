<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SixFactorEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SixFactorEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return ['data' => null];
        }

        return [
            'id' => $this->id,
            'umkm_id' => $this->umkm_id,
            'status' => $this->status,
            'scores' => $this->resource->only(SixFactorEvaluation::FACTORS),
            'total_score' => $this->total_score,
            'category' => $this->category,
            'finalized_at' => $this->finalized_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
