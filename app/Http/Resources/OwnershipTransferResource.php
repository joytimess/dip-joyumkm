<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** Baris audit dengan nama yang sudah di-join (kolom *_name); tidak ada email atau data sensitif. */
class OwnershipTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'umkm' => ['id' => (int) $this->umkm_id, 'name' => $this->umkm_name],
            'from' => ['id' => (int) $this->from_user_id, 'name' => $this->from_name],
            'to' => ['id' => (int) $this->to_user_id, 'name' => $this->to_name],
            'performed_by' => ['id' => (int) $this->performed_by_user_id, 'name' => $this->performed_by_name],
            'reason' => $this->reason,
            'transferred_at' => Carbon::parse($this->transferred_at, 'UTC')->toISOString(),
        ];
    }
}
