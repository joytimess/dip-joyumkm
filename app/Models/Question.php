<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['factor_id', 'sort_order', 'text', 'input_type', 'options', 'is_active'])]
class Question extends Model
{
    public function factor(): BelongsTo
    {
        return $this->belongsTo(Factor::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EvaluationAnswer::class);
    }

    protected function casts(): array
    {
        return [
            'factor_id' => 'integer',
            'sort_order' => 'integer',
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
