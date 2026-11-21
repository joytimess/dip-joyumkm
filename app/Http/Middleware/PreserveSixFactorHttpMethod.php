<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreserveSixFactorHttpMethod
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getRealMethod() === 'POST' && $request->is(
            'api/umkms/*/six-factor-evaluations',
            'api/umkms/*/six-factor-evaluations/*',
        )) {
            // Keep the raw JSON intact so validation can still reject a body containing _method.
            $request->headers->remove('X-HTTP-Method-Override');
            $request->request->remove('_method');
            $request->query->remove('_method');
            $request->setMethod('POST');
        }

        return $next($request);
    }
}
