<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FactorProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'umkm' => $this->resource['umkm'],
            'factors' => $this->resource['factors'],
            'selected_scores' => $this->resource['selected_scores'],
            'portfolio_averages' => $this->resource['portfolio_averages'],
            'sample_count_per_factor' => $this->resource['sample_count_per_factor'],
            'evaluation_status' => $this->resource['evaluation_status'],
            'answered_count' => $this->resource['answered_count'],
            'total_questions' => $this->resource['total_questions'],
        ];
    }
}
