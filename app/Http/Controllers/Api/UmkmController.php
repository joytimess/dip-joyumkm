<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Umkm\DeleteUmkmRequest;
use App\Http\Requests\Umkm\ListUmkmRequest;
use App\Http\Requests\Umkm\ShowUmkmRequest;
use App\Http\Requests\Umkm\StoreUmkmRequest;
use App\Http\Requests\Umkm\UpdateUmkmRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UmkmCollection;
use App\Http\Resources\UmkmOptionsResource;
use App\Http\Resources\UmkmResource;
use App\Http\Resources\UmkmSummaryResource;
use App\Services\UmkmService;
use App\Services\UmkmSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UmkmController extends Controller
{
    public function __construct(
        private readonly UmkmService $umkmService,
        private readonly UmkmSummaryService $umkmSummaryService,
    ) {}

    public function summary(Request $request): UmkmSummaryResource
    {
        return new UmkmSummaryResource($this->umkmSummaryService->get($request->user()));
    }

    public function index(ListUmkmRequest $request): UmkmCollection
    {
        return new UmkmCollection($this->umkmService->list($request->user(), $request->validated()));
    }

    public function store(StoreUmkmRequest $request): JsonResponse
    {
        return (new UmkmResource($this->umkmService->store($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function options(): UmkmOptionsResource
    {
        return new UmkmOptionsResource($this->umkmService->options());
    }

    public function show(ShowUmkmRequest $request): UmkmResource
    {
        return new UmkmResource($this->umkmService->owned(
            $request->user(),
            (int) $request->validated('record_id'),
        ));
    }

    public function update(UpdateUmkmRequest $request): UmkmResource
    {
        return new UmkmResource($this->umkmService->update(
            $request->user(),
            (int) $request->validated('record_id'),
            $request->safe()->only(['name', 'province', 'sector_id', 'omzet']),
        ));
    }

    public function destroy(DeleteUmkmRequest $request): MessageResource
    {
        return new MessageResource($this->umkmService->delete(
            $request->user(),
            (int) $request->validated('record_id'),
        ));
    }
}
