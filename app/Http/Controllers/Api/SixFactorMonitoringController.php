<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SixFactorMonitoringResource;
use App\Models\Umkm;
use Illuminate\Http\JsonResponse;

/**
 * Sumber data pemantauan periodik Hermes (Jobsheet 5): total skor final Enam Faktor terbaru per UMKM.
 *
 * Read-only, hanya development. UMKM anggota cohort sintetis dikecualikan sebelum ORDER BY/LIMIT (RSK-06). Autentikasi dilakukan middleware VerifyIntelligenceSnapshotSignature.
 */
class SixFactorMonitoringController extends Controller
{
    private const MAX_UMKMS = 100;

    public function __invoke(): JsonResponse
    {
        abort_if(app()->isProduction(), 404);

        $umkms = Umkm::query()
            ->whereDoesntHave('datasetMember')
            ->with('latestSixFactorFinal')
            ->orderBy('id')
            ->limit(self::MAX_UMKMS + 1)
            ->get();

        $truncated = $umkms->count() > self::MAX_UMKMS;

        return SixFactorMonitoringResource::collection($umkms->take(self::MAX_UMKMS))
            ->additional(['meta' => [
                'count' => min($umkms->count(), self::MAX_UMKMS),
                'truncated' => $truncated,
            ]])
            ->response();
    }
}
