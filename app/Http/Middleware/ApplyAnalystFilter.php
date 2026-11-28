<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filter opsional `analyst_id` pada pembacaan portofolio. Hanya Superadmin yang boleh memakainya;
 * nilainya harus Analyst nyata. Filter menyempit di User::accessibleUmkms() sehingga daftar, hitungan, grafik, dan ringkasan konsisten.
 */
class ApplyAnalystFilter
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->query->has('analyst_id')) {
            return $next($request);
        }

        abort_unless($request->user()->isSuperadmin(), 403);
        $value = $request->query('analyst_id');
        $analystId = is_string($value) && ctype_digit($value) ? (int) $value : 0;
        $exists = $analystId > 0 && User::query()->whereKey($analystId)->where('role', UserRole::Analyst->value)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['analyst_id' => ['Analyst tidak valid.']]);
        }
        $request->user()->filterToOwner($analystId);

        return $next($request);
    }
}
