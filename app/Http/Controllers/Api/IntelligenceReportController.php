<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Intelligence\IntelligenceReportRequest;
use App\Http\Resources\IntelligenceReportResource;
use App\Http\Resources\IntelligenceReportSummaryResource;
use App\Services\IntelligenceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IntelligenceReportController extends Controller
{
    public function __construct(
        private readonly IntelligenceReportService $reports,
    ) {}

    public function latest(IntelligenceReportRequest $request): JsonResponse|IntelligenceReportResource
    {
        $report = $this->reports->latest($request->user(), $request->umkmId());
        if ($report === null) {
            return response()->json(['status' => 'awaiting_report', 'data' => null]);
        }

        return (new IntelligenceReportResource($report))->additional(['status' => 'available']);
    }

    public function index(IntelligenceReportRequest $request): AnonymousResourceCollection
    {
        return IntelligenceReportSummaryResource::collection($this->reports->history(
            $request->user(), $request->umkmId(), $request->perPage(), $request->pageNumber(),
        ));
    }

    public function show(IntelligenceReportRequest $request): IntelligenceReportResource
    {
        return new IntelligenceReportResource($this->reports->find(
            $request->user(), $request->umkmId(), $request->reportId(),
        ));
    }
}
