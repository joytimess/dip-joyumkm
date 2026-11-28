<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

class TransferUmkmRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'expected_owner_id' => ['required', 'integer', 'min:1'],
            'to_user_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'user_id' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'expected_owner_id' => 'Pemilik saat ini',
            'to_user_id' => 'Analyst tujuan',
            'reason' => 'Alasan',
        ];
    }
}
