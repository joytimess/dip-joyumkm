<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyIntelligenceRecipientSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = '/api/internal/intelligence/recipients/'.$request->route('eventId');
        abort_unless($request->getRealMethod() === 'GET', 405, '', ['Allow' => 'GET']);
        abort_unless($request->getRequestUri() === $path && $request->getContent() === '', 400);
        abort_unless($request->isSecure() || app()->environment('local', 'testing'), 400);

        $secret = config('services.hermes_intelligence_recipient.secret');
        $tolerance = filter_var(config('services.hermes_intelligence_recipient.tolerance_seconds'), FILTER_VALIDATE_INT);
        abort_unless(is_string($secret) && strlen(trim($secret)) >= 32 && $tolerance === 300, 503);

        foreach (['hermes_webhook.secret', 'hermes_intelligence_callback.secret',
            'hermes_intelligence_snapshot.secret', 'hermes_intelligence_artifact.secret', 'intelligence.recipient_pepper'] as $key) {
            $other = config('services.'.$key);
            abort_if(is_string($other) && hash_equals($other, $secret), 503);
        }

        $timestamp = (string) $request->header('X-Intelligence-Timestamp');
        $signature = (string) $request->header('X-Intelligence-Signature');
        abort_unless(
            $request->headers->all('X-Intelligence-Signature-Version') === ['1']
                && count($request->headers->all('X-Intelligence-Timestamp')) === 1
                && count($request->headers->all('X-Intelligence-Signature')) === 1
                && preg_match('/\\A[1-9][0-9]{0,11}\\z/', $timestamp) === 1
                && abs(now()->timestamp - (int) $timestamp) <= $tolerance
                && hash_equals(hash_hmac('sha256', "JMI-RECIPIENT-V1\nGET\n{$path}\n{$timestamp}", $secret), $signature),
            401,
        );

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
