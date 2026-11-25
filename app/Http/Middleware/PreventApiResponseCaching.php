<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengecualikan respons API dari cache LiteSpeed dan cache perantara.
 *
 * Halaman Blade hanya berupa kerangka tanpa data pengguna; seluruh data pribadi berasal dari API yang
 * dilindungi auth:sanctum atau signature internal. Respons error dari pengecualian tidak melewati middleware
 * ini, sehingga bootstrap/app.php memanggil apply() juga untuk respons error API.
 */
final class PreventApiResponseCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    public static function apply(Response $response): Response
    {
        $response->headers->addCacheControlDirective('private');
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');

        return $response;
    }
}
