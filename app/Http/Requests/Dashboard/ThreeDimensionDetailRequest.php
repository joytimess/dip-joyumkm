<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Http\Requests\ApiRequest;

class ThreeDimensionDetailRequest extends ApiRequest
{
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        return ['umkm_id' => ['required', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'umkm_id.min' => 'ID UMKM minimal 1.'];
    }
}
