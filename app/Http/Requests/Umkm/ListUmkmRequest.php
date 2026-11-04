<?php

declare(strict_types=1);

namespace App\Http\Requests\Umkm;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class ListUmkmRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('search'))) {
            $this->merge(['search' => trim($this->query('search'))]);
        }
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        $categories = array_column(config('umkm.scoring.categories'), 'label');
        $categories[] = config('umkm.scoring.unrated.label');

        return [
            'search' => ['nullable', 'string', 'max:120'],
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'province' => ['nullable', 'string', Rule::in(config('provinces'))],
            'category' => ['nullable', 'string', Rule::in($categories)],
            'status' => ['nullable', 'string', Rule::in(['none', 'draft', 'final'])],
            'sort' => ['nullable', 'string', Rule::in(['score', 'name', 'omzet'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
