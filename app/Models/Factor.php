<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'color', 'scale_min', 'scale_max', 'sort_order'])]
class Factor extends Model
{
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    protected function casts(): array
    {
        return ['scale_min' => 'integer', 'scale_max' => 'integer', 'sort_order' => 'integer'];
    }
}
