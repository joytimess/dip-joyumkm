<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UmkmSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'total_umkms' => $this->resource['total_umkms'],
            'total_respondents' => $this->resource['total_respondents'],
            'scored_count' => $this->resource['scored_count'],
            'draft_count' => $this->resource['draft_count'],
            'average_score' => $this->resource['average_score'],
            'category' => $this->resource['category'],
            'critical_sector' => $this->resource['critical_sector'],
            $this->mergeWhen($this->resource['portfolio'] !== null, fn (): array => ['portfolio' => $this->resource['portfolio']]),
        ];
    }
}
