<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Umkm\DeleteUmkmRequest;
use App\Http\Requests\Umkm\ListUmkmRequest;
use App\Http\Requests\Umkm\ShowUmkmRequest;
use App\Http\Requests\Umkm\StoreUmkmRequest;
use App\Http\Requests\Umkm\UpdateUmkmRequest;
use App\Http\Requests\Umkm\WhatsappCheckRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UmkmCollection;
use App\Http\Resources\UmkmOptionsResource;
use App\Http\Resources\UmkmResource;
use App\Http\Resources\UmkmSummaryResource;
use App\Services\OpenWaNumberCheckService;
use App\Services\UmkmService;
use App\Services\UmkmSummaryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    public function whatsappCheck(WhatsappCheckRequest $request, OpenWaNumberCheckService $openWa): JsonResponse
    {
        $number = $request->validated('whatsapp_number');
        $status = $openWa->check($number);

        return response()->json(['data' => [
            'normalized_number' => $number,
            'status' => $status,
            'message' => match ($status) {
                OpenWaNumberCheckService::REGISTERED => 'Nomor terdaftar di WhatsApp.',
                OpenWaNumberCheckService::UNREGISTERED => 'Nomor tidak terdaftar di WhatsApp. Data tetap dapat disimpan.',
                default => 'Pemeriksaan WhatsApp belum berhasil. Data tetap dapat disimpan.',
            },
        ]]);
    }

    public function recheckWhatsapp(WhatsappCheckRequest $request): UmkmResource
    {
        return new UmkmResource($this->umkmService->recheckWhatsapp(
            $request->user(),
            (int) $request->route('id'),
            (string) $request->validated('whatsapp_number'),
        ));
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
            $request->safe()->except(['record_id', 'user_id']),
        ));
    }

    public function destroy(DeleteUmkmRequest $request): MessageResource
    {
        try {
            return new MessageResource($this->umkmService->delete(
                $request->user(),
                (int) $request->validated('record_id'),
            ));
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1451
                || ! str_contains($exception->errorInfo[2] ?? '', 'CONSTRAINT `six_factor_umkm_foreign`')) {
                throw $exception;
            }

            throw new ConflictHttpException('UMKM tidak dapat dihapus karena memiliki riwayat penilaian enam faktor.', $exception);
        }
    }
}
