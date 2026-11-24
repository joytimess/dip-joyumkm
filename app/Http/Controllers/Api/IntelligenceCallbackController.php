<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Intelligence\IntelligenceCallbackRequest;
use App\Services\IntelligenceReportService;
use Illuminate\Http\JsonResponse;

class IntelligenceCallbackController extends Controller
{
    public function __construct(
        private readonly IntelligenceReportService $reports,
    ) {}

    public function store(IntelligenceCallbackRequest $request): JsonResponse
    {
        ['outcome' => $outcome, 'report' => $report] = $this->reports->store($request->payload());

        if ($outcome === IntelligenceReportService::CONFLICT) {
            return response()->json([
                'message' => 'Laporan untuk event ini sudah tersimpan dengan isi yang berbeda.',
                'code' => 'report_conflict',
            ], 409);
        }

        return response()->json([
            'status' => $outcome,
            'event_id' => $report->event_id,
            'report_id' => $report->id,
        ], $outcome === IntelligenceReportService::STORED ? 201 : 200);
    }
}
