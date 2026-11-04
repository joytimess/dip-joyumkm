<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UmkmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->latest_category ?? config('umkm.scoring.unrated.label');

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'province' => $this->province,
            'sector' => ['id' => (int) $this->sector->id, 'name' => $this->sector->name],
            'omzet' => (int) $this->omzet,
            'latest_score' => $this->latest_score === null ? null : (float) $this->latest_score,
            'category' => $category,
            'recommendation' => $category,
            'evaluation_status' => $this->evaluation_status ?? 'none',
            'evaluation_id' => $this->evaluation_id === null ? null : (int) $this->evaluation_id,
        ];
    }
}
