<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Http\Requests\ApiRequest;

class ComparisonRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->query->has('umkm_ids') && ($this->query('umkm_ids') === null || $this->query('umkm_ids') === '')) {
            $this->query->set('umkm_ids', []);
        }
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        return [
            'umkm_ids' => ['sometimes', 'array', 'list', 'max:5'],
            'umkm_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'list' => 'Pilihan UMKM harus berupa daftar berurutan.',
            'umkm_ids.max' => 'Pilihan UMKM maksimal 5 item.',
            'umkm_ids.*.min' => 'ID UMKM minimal 1.',
        ];
    }
}
