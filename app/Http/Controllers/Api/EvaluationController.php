<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\LatestEvaluationRequest;
use App\Http\Requests\Evaluation\StoreEvaluationRequest;
use App\Http\Resources\EvaluationResource;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;

class EvaluationController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    public function latest(LatestEvaluationRequest $request): EvaluationResource
    {
        return new EvaluationResource($this->evaluationService->latest(
            $request->user(), (int) $request->validated('record_id'),
        ));
    }

    public function store(StoreEvaluationRequest $request): JsonResponse
    {
        return (new EvaluationResource($this->evaluationService->store(
            $request->user(), (int) $request->validated('record_id'),
            $request->safe()->only(['status', 'answers']),
        )))->response()->setStatusCode(201);
    }
}
