<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAnalystsRequest;
use App\Http\Requests\Admin\SetAnalystStatusRequest;
use App\Http\Requests\Admin\StoreAnalystRequest;
use App\Http\Requests\Admin\UpdateAnalystRequest;
use App\Http\Resources\AnalystResource;
use App\Services\AnalystService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAnalystController extends Controller
{
    public function __construct(private readonly AnalystService $analysts) {}

    public function index(ListAnalystsRequest $request): AnonymousResourceCollection
    {
        return AnalystResource::collection($this->analysts->list($request->validated()));
    }

    public function store(StoreAnalystRequest $request): JsonResponse
    {
        return (new AnalystResource($this->analysts->create($request->safe()->only(['name', 'email', 'password']))))
            ->response()->setStatusCode(201);
    }

    public function show(int $id): AnalystResource
    {
        return new AnalystResource($this->analysts->find($id));
    }

    public function update(UpdateAnalystRequest $request, int $id): AnalystResource
    {
        return new AnalystResource($this->analysts->update($id, $request->safe()->only(['name', 'email'])));
    }

    public function status(SetAnalystStatusRequest $request, int $id): AnalystResource
    {
        return new AnalystResource($this->analysts->setActive($id, (bool) $request->validated('is_active')));
    }
}
