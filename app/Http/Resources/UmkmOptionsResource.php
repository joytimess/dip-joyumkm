<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UmkmOptionsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sectors' => $this->resource['sectors'],
            'provinces' => $this->resource['provinces'],
            'categories' => $this->resource['categories'],
        ];
    }
}
