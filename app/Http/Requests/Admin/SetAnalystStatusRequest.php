<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

class SetAnalystStatusRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean:strict']];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'is_active' => 'Status'];
    }
}
