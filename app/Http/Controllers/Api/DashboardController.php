<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ComparisonRequest;
use App\Http\Requests\Dashboard\FactorProfileRequest;
use App\Http\Resources\ComparisonResource;
use App\Http\Resources\DistributionResource;
use App\Http\Resources\FactorProfileResource;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

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
}
