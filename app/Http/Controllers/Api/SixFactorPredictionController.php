<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\SixFactorPredictionRequest;
use App\Services\HermesPredictionService;
use Illuminate\Http\JsonResponse;

class SixFactorPredictionController extends Controller
{
    public function __invoke(SixFactorPredictionRequest $request, HermesPredictionService $prediction): JsonResponse
    {
        return response()->json(['data' => $prediction->predict($request->validated('scores'))]);
    }
}
