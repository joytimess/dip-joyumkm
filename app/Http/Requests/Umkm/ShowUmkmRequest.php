<?php

declare(strict_types=1);

namespace App\Http\Requests\Umkm;

use App\Http\Requests\ApiRequest;

class ShowUmkmRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['record_id' => $this->route('id')]);
    }

    public function rules(): array
    {
        return ['record_id' => ['required', 'integer', 'min:1']];
    }
}
