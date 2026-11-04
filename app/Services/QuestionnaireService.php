<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Factor;
use App\Models\Question;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionnaireService
{
    public function activeFactors(): Collection
    {
        return Factor::query()->orderBy('sort_order')->orderBy('id')
            ->with(['questions' => function (HasMany $query): void {
                $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
            }])->get();
    }

    public function get(): array
    {
        return [
            'factors' => $this->activeFactors()->map(function (Factor $factor): array {
                return [
                    ...$factor->only(['id', 'code', 'name', 'description', 'color', 'scale_min', 'scale_max', 'sort_order']),
                    'questions' => $factor->questions->map(fn (Question $question): array => $question->only([
                        'id', 'sort_order', 'text', 'input_type', 'options',
                    ]))->all(),
                ];
            })->all(),
            'scoring' => config('umkm.scoring'),
        ];
    }
}
