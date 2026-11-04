<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComparisonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'factors' => $this->resource['factors'],
            'selected_ids' => $this->resource['selected_ids'],
            'umkms' => $this->resource['umkms'],
            'portfolio_averages' => $this->resource['portfolio_averages'],
            'sample_count_per_factor' => $this->resource['sample_count_per_factor'],
        ];
    }
}
