<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Umkm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Umkm
 */
class SixFactorMonitoringResource extends JsonResource
{
    /**
     * Hanya kolom yang diperlukan pemantauan. Tanpa email, telepon, maupun data pribadi pemilik.
     *
     * @return array{umkm_id: int, nama_umkm: string, evaluation_id: int|null, total_skor: int|null, finalized_at: string|null, server_active: bool}
     */
    public function toArray(Request $request): array
    {
        $evaluation = $this->latestSixFactorFinal;

        return [
            'umkm_id' => $this->id,
            'nama_umkm' => $this->name,
            'evaluation_id' => $evaluation?->id,
            'total_skor' => $evaluation?->total_score,
            'finalized_at' => $evaluation?->finalized_at?->utc()->toIso8601String(),
            'server_active' => true,
        ];
    }
}
