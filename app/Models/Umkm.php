<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UmkmFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name', 'province', 'sector_id', 'omzet', 'omzet_period', 'year_founded', 'employee_count', 'nib', 'description',
    'city', 'address', 'district', 'subdistrict', 'postal_code', 'latitude', 'longitude',
    'owner_name', 'whatsapp_number', 'business_email', 'pic_name', 'website_url', 'social_media_url',
])]
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

    public function sixFactorEvaluations(): HasMany
    {
        return $this->hasMany(SixFactorEvaluation::class);
    }

    public function activeSixFactorDraft(): HasOne
    {
        return $this->hasOne(SixFactorEvaluation::class)->where('status', SixFactorEvaluation::DRAFT);
    }

    public function latestSixFactorFinal(): HasOne
    {
        return $this->hasOne(SixFactorEvaluation::class)->ofMany(
            ['finalized_at' => 'max', 'id' => 'max'],
            fn (Builder $query): Builder => $query->where('status', SixFactorEvaluation::FINAL),
        );
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'sector_id' => 'integer', 'omzet' => 'integer',
            'year_founded' => 'integer', 'employee_count' => 'integer',
            'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'wa_checked_at' => 'datetime',
        ];
    }
}
