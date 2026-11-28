<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Auth\RegisterRequest;

/**
 * Sama dengan registrasi publik (nama, email unik, kata sandi), tetapi peran/status tidak boleh dikirim.
 */
class StoreAnalystRequest extends RegisterRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'role' => ['prohibited'], 'is_active' => ['prohibited']];
    }
}
