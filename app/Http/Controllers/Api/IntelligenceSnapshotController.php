<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookOutbox;
use App\Services\IntelligenceSnapshotService;
use Illuminate\Http\JsonResponse;

class IntelligenceSnapshotController extends Controller
{
    public function __construct(
        private readonly IntelligenceSnapshotService $snapshots,
    ) {}

    public function show(string $eventId): JsonResponse
    {
        $event = WebhookOutbox::query()->where('event_id', $eventId)->first();
        if ($event === null || $event->event_type !== 'evaluation.finalized') {
            return response()->json(['message' => 'Event tidak ditemukan.', 'code' => 'event_not_found'], 404);
        }

        if ($event->event_version !== 2) {
            return response()->json([
                'message' => 'Versi event ini tidak memiliki snapshot historis.',
                'code' => 'unsupported_event_version',
            ], 422);
        }

        $snapshot = $this->snapshots->forEvent($event);
        if ($snapshot === null) {
            return response()->json([
                'message' => 'Event tidak terikat pada penilaian Final UMKM yang sama.',
                'code' => 'event_evaluation_mismatch',
            ], 409);
        }

        return response()->json($snapshot)->header('Cache-Control', 'no-store');
    }
}
