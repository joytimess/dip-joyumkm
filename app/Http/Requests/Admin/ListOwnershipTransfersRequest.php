<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

class ListOwnershipTransfersRequest extends ApiRequest
{
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        return [
            'umkm_id' => ['nullable', 'integer', 'min:1'],
            'from_user_id' => ['nullable', 'integer', 'min:1'],
            'to_user_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
