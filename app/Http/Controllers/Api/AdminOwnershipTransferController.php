<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListOwnershipTransfersRequest;
use App\Http\Requests\Admin\TransferUmkmRequest;
use App\Http\Resources\OwnershipTransferResource;
use App\Services\OwnershipTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AdminOwnershipTransferController extends Controller
{
    public function __construct(private readonly OwnershipTransferService $transfers) {}

    public function index(ListOwnershipTransfersRequest $request): AnonymousResourceCollection
    {
        $query = DB::table('umkm_ownership_transfers as t')
            ->join('umkms', 'umkms.id', '=', 't.umkm_id')
            ->join('users as from_user', 'from_user.id', '=', 't.from_user_id')
            ->join('users as to_user', 'to_user.id', '=', 't.to_user_id')
            ->join('users as actor', 'actor.id', '=', 't.performed_by_user_id')
            ->select([
                't.id', 't.umkm_id', 't.from_user_id', 't.to_user_id', 't.performed_by_user_id', 't.reason', 't.transferred_at',
                'umkms.name as umkm_name', 'from_user.name as from_name', 'to_user.name as to_name', 'actor.name as performed_by_name',
            ]);
        foreach (['umkm_id', 'from_user_id', 'to_user_id'] as $filter) {
            if ($request->validated($filter) !== null) {
                $query->where('t.'.$filter, (int) $request->validated($filter));
            }
        }
        $page = $query->orderByDesc('t.transferred_at')->orderByDesc('t.id')
            ->paginate((int) ($request->validated('per_page') ?? 10), ['*'], 'page', (int) ($request->validated('page') ?? 1))
            ->withQueryString();

        return OwnershipTransferResource::collection($page);
    }

    public function store(TransferUmkmRequest $request, int $id): JsonResponse
    {
        $audit = $this->transfers->transfer(
            $request->user(),
            $id,
            (int) $request->validated('expected_owner_id'),
            (int) $request->validated('to_user_id'),
            (string) $request->validated('reason'),
        );

        return response()->json(['data' => [
            'audit_id' => (int) $audit->id,
            'umkm_id' => (int) $audit->umkm_id,
            'owner' => ['id' => (int) $audit->to_user_id],
            'previous_owner' => ['id' => (int) $audit->from_user_id],
            'transferred_at' => $audit->transferred_at->toISOString(),
        ]], 201);
    }
}
