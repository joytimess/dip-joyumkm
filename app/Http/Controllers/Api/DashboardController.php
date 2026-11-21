<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ComparisonRequest;
use App\Http\Requests\Dashboard\FactorProfileRequest;
use App\Http\Requests\Dashboard\SixFactorSummaryRequest;
use App\Http\Requests\Dashboard\ThreeDimensionDetailRequest;
use App\Http\Requests\Dashboard\ThreeDimensionHealthRequest;
use App\Http\Resources\ComparisonResource;
use App\Http\Resources\DistributionResource;
use App\Http\Resources\FactorProfileResource;
use App\Http\Resources\SixFactorSummaryCollection;
use App\Http\Resources\ThreeDimensionHealthCollection;
use App\Http\Resources\ThreeDimensionHealthItemResource;
use App\Services\DashboardService;
use App\Services\SixFactorSummaryService;
use App\Services\ThreeDimensionHealthService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly ThreeDimensionHealthService $threeDimensionHealthService,
        private readonly SixFactorSummaryService $sixFactorSummaryService,
    ) {}

    public function factorProfile(FactorProfileRequest $request): FactorProfileResource
    {
        return new FactorProfileResource($this->dashboardService->factorProfile(
            $request->user(), (int) $request->validated('umkm_id'),
        ));
    }

    public function distribution(Request $request): DistributionResource
    {
        return new DistributionResource($this->dashboardService->distribution($request->user()));
    }

    public function comparison(ComparisonRequest $request): ComparisonResource
    {
        return new ComparisonResource($this->dashboardService->comparison($request->user(), $request->validated()));
    }

    public function threeDimensionHealth(ThreeDimensionHealthRequest $request): ThreeDimensionHealthCollection
    {
        $user = $request->user();

        return (new ThreeDimensionHealthCollection($this->threeDimensionHealthService->list($user, $request->validated())))
            ->additional(['summary' => $this->threeDimensionHealthService->summary($user)]);
    }

    public function sixFactorSummary(SixFactorSummaryRequest $request): SixFactorSummaryCollection
    {
        $user = $request->user();

        return (new SixFactorSummaryCollection($this->sixFactorSummaryService->list($user, $request->validated())))
            ->additional(['summary' => $this->sixFactorSummaryService->summary($user)]);
    }

    public function threeDimensionDetail(ThreeDimensionDetailRequest $request): ThreeDimensionHealthItemResource
    {
        return new ThreeDimensionHealthItemResource($this->threeDimensionHealthService->detail(
            $request->user(), (int) $request->validated('umkm_id'),
        ));
    }
}
