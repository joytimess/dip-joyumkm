<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Umkm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagar rute manajemen: hanya Superadmin aktif menurut UmkmPolicy::manageAnalysts. Peran lain mendapat 403.
 */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->can('manageAnalysts', Umkm::class) === true, 403);

        return $next($request);
    }
}
