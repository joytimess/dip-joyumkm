<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memverifikasi callback Hermes: HMAC-SHA256 atas "{timestamp}.{rawBody}" dengan secret khusus callback.
 */
class VerifyIntelligenceCallbackSignature
{
    private const MIN_SECRET_LENGTH = 32;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.hermes_intelligence_callback.secret');
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            return $this->reject(503, 'callback_unavailable', 'Penerima callback belum dikonfigurasi.');
        }

        $body = $request->getContent();
        if (strlen($body) > $this->maxBodyBytes()) {
            return $this->reject(413, 'payload_too_large', 'Isi permintaan terlalu besar.');
        }

        $timestamp = (string) $request->header('X-Intelligence-Timestamp');
        $signature = (string) $request->header('X-Intelligence-Signature');
        if (! $this->timestampIsFresh($timestamp)
            || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature)) {
            return $this->reject(401, 'unauthorized', 'Tidak berwenang.');
        }

        if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/json') {
            return $this->reject(415, 'unsupported_media_type', 'Content-Type harus application/json.');
        }

        return $next($request);
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        return abs(now()->getTimestamp() - (int) $timestamp) <= $this->tolerance();
    }

    private function tolerance(): int
    {
        return max(1, (int) config('services.hermes_intelligence_callback.tolerance_seconds', 300));
    }

    private function maxBodyBytes(): int
    {
        return max(1, (int) config('services.hermes_intelligence_callback.max_body_bytes', 2 * 1024 * 1024));
    }

    private function reject(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
