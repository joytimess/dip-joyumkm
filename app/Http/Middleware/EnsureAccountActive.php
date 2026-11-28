<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Status akun diperiksa pada setiap request terautentikasi: token lama dari akun nonaktif tidak pernah berlaku.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->isActive()) {
            $user->tokens()->delete();

            throw new AuthenticationException('Unauthenticated.');
        }

        return $next($request);
    }
}
