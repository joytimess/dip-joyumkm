<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit append-only. Belum ada penulis pada P2; layanan transfer dibangun di P4.
 */
class UmkmOwnershipTransfer extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public function umkm(): BelongsTo
    {
        return $this->belongsTo(Umkm::class);
    }

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }
}
