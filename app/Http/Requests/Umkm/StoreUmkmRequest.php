<?php

declare(strict_types=1);

namespace App\Http\Requests\Umkm;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class StoreUmkmRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('umkms', 'name')->where('user_id', $this->user()->id),
            ],
            'province' => ['required', 'string', Rule::in(config('provinces'))],
            'sector_id' => ['required', 'integer', 'exists:sectors,id'],
            'omzet' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'user_id' => ['prohibited'],
        ];
    }
}
