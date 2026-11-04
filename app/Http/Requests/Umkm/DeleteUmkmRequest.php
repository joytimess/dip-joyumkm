<?php

declare(strict_types=1);

namespace App\Http\Requests\Umkm;

class DeleteUmkmRequest extends ShowUmkmRequest
{
    public function rules(): array
    {
        return ['record_id' => ['required', 'integer', 'min:1']];
    }
}
