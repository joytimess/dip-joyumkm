<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'total_umkms' => $this->resource['total_umkms'],
            'categories' => $this->resource['categories'],
            'sectors' => $this->resource['sectors'],
        ];
    }
}
