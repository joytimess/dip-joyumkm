<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnalystResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => 'analyst',
            'is_active' => $this->resource->isActive(),
            'umkms_count' => (int) ($this->umkms_count ?? 0),
            'cohort_umkms_count' => (int) ($this->cohort_umkms_count ?? 0),
            'non_cohort_umkms_count' => (int) ($this->umkms_count ?? 0) - (int) ($this->cohort_umkms_count ?? 0),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
