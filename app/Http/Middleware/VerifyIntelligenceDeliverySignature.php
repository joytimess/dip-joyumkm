<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyIntelligenceDeliverySignature
{
    public const PATH = '/api/internal/intelligence/deliveries';

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', implode("\n", ['JMI-DELIVERY-STATUS-V1', 'POST', self::PATH, $timestamp, hash('sha256', $body)]), $secret);
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->getRealMethod() === 'POST', 405, '', ['Allow' => 'POST']);
        abort_unless($request->getRequestUri() === self::PATH && ! $request->headers->has('Transfer-Encoding')
            && ! $request->headers->has('Content-Encoding'), 400);
        abort_unless($request->isSecure() || app()->environment('testing')
            || (app()->environment('local') && $request->getHost() === 'joyumkm.test'), 400);
        abort_unless(count($request->headers->all('Content-Type')) === 1
            && preg_match('/\Aapplication\/json(?:;\s*charset=utf-8)?\z/i', $request->header('Content-Type', '')) === 1, 415);

        $raw = $request->getContent();
        abort_if(strlen($raw) > 16384, 413);
        abort_unless($request->headers->all('Content-Length') === [(string) strlen($raw)], 400);
        $secret = config('services.hermes_intelligence_delivery_status.secret');
        $otherSecrets = array_map(fn (string $service) => config("services.{$service}.secret"), [
            'hermes_webhook', 'hermes_intelligence_callback', 'hermes_intelligence_snapshot',
            'hermes_intelligence_artifact', 'hermes_intelligence_recipient',
        ]);
        $otherSecrets[] = config('services.intelligence.recipient_pepper');
        if (! is_string($secret) || strlen(trim($secret)) < 32
            || (string) config('services.hermes_intelligence_delivery_status.tolerance_seconds') !== '300'
            || in_array($secret, $otherSecrets, true)) {
            return response()->json(['code' => 'delivery_config_invalid'], 503)->header('Cache-Control', 'no-store');
        }
        $timestamp = (string) $request->header('X-Intelligence-Timestamp');
        $signature = (string) $request->header('X-Intelligence-Signature');
        abort_unless($request->headers->all('X-Intelligence-Signature-Version') === ['1']
            && $request->headers->all('X-Intelligence-Timestamp') === [$timestamp]
            && $request->headers->all('X-Intelligence-Signature') === [$signature]
            && preg_match('/\A[1-9][0-9]{0,11}\z/', $timestamp) === 1
            && abs(now()->timestamp - (int) $timestamp) <= 300
            && hash_equals(self::sign($secret, $timestamp, $raw), $signature), 401);

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
