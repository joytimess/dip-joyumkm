<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionnaireResource;
use App\Services\QuestionnaireService;

class QuestionnaireController extends Controller
{
    public function __construct(private readonly QuestionnaireService $questionnaireService) {}

    public function show(): QuestionnaireResource
    {
        return new QuestionnaireResource($this->questionnaireService->get());
    }
}
