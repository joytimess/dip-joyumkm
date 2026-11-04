<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status', 'ins_score', 'ect_score', 'ops_score', 'average_score', 'category', 'submitted_at'])]
class Evaluation extends Model
{
    public function umkm(): BelongsTo
    {
        return $this->belongsTo(Umkm::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EvaluationAnswer::class);
    }

    protected function casts(): array
    {
        return [
            'umkm_id' => 'integer',
            'ins_score' => 'float',
            'ect_score' => 'float',
            'ops_score' => 'float',
            'average_score' => 'float',
            'submitted_at' => 'datetime',
        ];
    }
}
