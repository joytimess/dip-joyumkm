<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['analytics_dataset_cohort_id', 'umkm_id', 'synthetic_key'])]
class AnalyticsDatasetMember extends Model
{
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(AnalyticsDatasetCohort::class, 'analytics_dataset_cohort_id');
    }

    public function umkm(): BelongsTo
    {
        return $this->belongsTo(Umkm::class);
    }
}
