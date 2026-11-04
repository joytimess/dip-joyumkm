<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UmkmFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'province', 'sector_id', 'omzet'])]
class Umkm extends Model
{
    /** @use HasFactory<UmkmFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function latestEvaluation(): HasOne
    {
        return $this->hasOne(Evaluation::class)->latestOfMany('id');
    }

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'sector_id' => 'integer', 'omzet' => 'integer'];
    }
}
