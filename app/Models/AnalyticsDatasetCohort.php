<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'dataset_key', 'dataset_version', 'user_id', 'reference_start_month', 'reference_end_month',
    'umkm_count', 'three_dimension_count', 'six_factor_count', 'answer_count', 'content_fingerprint', 'completed_at',
])]
class AnalyticsDatasetCohort extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'umkm_count' => 'integer',
            'three_dimension_count' => 'integer',
            'six_factor_count' => 'integer',
            'answer_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
