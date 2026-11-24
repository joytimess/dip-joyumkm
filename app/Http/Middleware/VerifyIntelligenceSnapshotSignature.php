<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memverifikasi pull snapshot historis dari Hermes (GET, tanpa body).
 *
 * Signature: HMAC-SHA256 atas "{timestamp}\nGET\n{path}" dengan secret khusus snapshot.
 * Path ikut ditandatangani sehingga signature untuk satu event tidak dapat dipakai pada event lain.
 */
class VerifyIntelligenceSnapshotSignature
{
    private const MIN_SECRET_LENGTH = 32;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.hermes_intelligence_snapshot.secret');
        if (strlen($secret) < self::MIN_SECRET_LENGTH || $this->reusesAnotherSecret($secret)) {
            return $this->reject(503, 'snapshot_unavailable', 'Penyedia snapshot belum dikonfigurasi.');
        }

        if ($request->getContent() !== '') {
            return $this->reject(400, 'unexpected_body', 'Permintaan snapshot tidak boleh memiliki isi.');
        }

        $timestamp = (string) $request->header('X-Intelligence-Snapshot-Timestamp');
        $signature = (string) $request->header('X-Intelligence-Snapshot-Signature');
        $canonical = $timestamp."\nGET\n/".$request->path();

        if (! $this->timestampIsFresh($timestamp)
            || ! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return $this->reject(401, 'unauthorized', 'Tidak berwenang.');
        }

        return $next($request);
    }

    private function reusesAnotherSecret(string $secret): bool
    {
        foreach ([
            config('services.hermes_intelligence_callback.secret'),
            config('services.hermes_webhook.secret'),
        ] as $other) {
            if (is_string($other) && $other !== '' && hash_equals($other, $secret)) {
                return true;
            }
        }

        return false;
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        $tolerance = max(1, (int) config('services.hermes_intelligence_snapshot.tolerance_seconds', 300));

        return abs(now()->getTimestamp() - (int) $timestamp) <= $tolerance;
    }

    private function reject(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
