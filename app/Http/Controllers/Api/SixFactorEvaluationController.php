<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\SixFactorEvaluationRequest;
use App\Http\Resources\SixFactorEvaluationResource;
use App\Services\SixFactorEvaluationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SixFactorEvaluationController extends Controller
{
    public function __construct(
        private readonly SixFactorEvaluationService $evaluations,
    ) {}

    public function draft(SixFactorEvaluationRequest $request): SixFactorEvaluationResource
    {
        return new SixFactorEvaluationResource($this->evaluations->activeDraft(
            $request->user(), $request->umkmId(),
        ));
    }

    public function save(SixFactorEvaluationRequest $request): SixFactorEvaluationResource
    {
        try {
            $draft = $this->evaluations->saveDraft(
                $request->user(), $request->umkmId(), $request->validated('scores', []),
            );
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $factor => $messages) {
                $errors['scores.'.$factor] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        return new SixFactorEvaluationResource($draft);
    }

    public function finalize(SixFactorEvaluationRequest $request): SixFactorEvaluationResource
    {
        return new SixFactorEvaluationResource($this->evaluations->finalize(
            $request->user(), $request->umkmId(), $request->evaluationId(),
        ));
    }

    public function cancel(SixFactorEvaluationRequest $request): SixFactorEvaluationResource
    {
        return new SixFactorEvaluationResource($this->evaluations->cancelDraft(
            $request->user(), $request->umkmId(), $request->evaluationId(),
        ));
    }

    public function history(SixFactorEvaluationRequest $request): AnonymousResourceCollection
    {
        return SixFactorEvaluationResource::collection($this->evaluations->finalHistory(
            $request->user(), $request->umkmId(),
        ));
    }

    public function latest(SixFactorEvaluationRequest $request): SixFactorEvaluationResource
    {
        return new SixFactorEvaluationResource($this->evaluations->latestFinal(
            $request->user(), $request->umkmId(),
        ));
    }
}
