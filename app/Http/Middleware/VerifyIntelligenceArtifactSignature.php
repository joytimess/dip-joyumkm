<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\IntelligenceArtifactContract;
use App\Services\IntelligenceArtifactSignature;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi unggahan artefak: HMAC-SHA256 atas string kanonis yang mengikat metode, path, event, kind,
 * content type, panjang, dan digest body. Body tidak dibaca di sini; digest aktual diverifikasi saat streaming.
 */
class VerifyIntelligenceArtifactSignature
{
    private const MIN_SECRET_LENGTH = 32;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.hermes_intelligence_artifact.secret');
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            return $this->reject(503, 'artifact_receiver_unavailable', 'Penerima artefak belum dikonfigurasi.');
        }

        $eventId = (string) $request->route('eventId');
        $kind = (string) $request->route('kind');
        if (! array_key_exists($kind, IntelligenceArtifactContract::KINDS)) {
            return $this->reject(404, 'event_not_found', 'Data tidak ditemukan.');
        }

        $timestamp = (string) $request->header('X-Intelligence-Timestamp');
        $signature = strtolower((string) $request->header('X-Intelligence-Signature'));
        $bodySha = strtolower((string) $request->header('X-Intelligence-Content-Sha256'));
        $length = (string) $request->server->get('CONTENT_LENGTH', '');
        $contentType = (string) $request->server->get('CONTENT_TYPE', '');

        if ($timestamp === '' || $signature === '' || $bodySha === '' || $request->header('X-Intelligence-Signature-Version') !== '1') {
            return $this->reject(401, 'missing_authentication', 'Autentikasi tidak lengkap.');
        }
        if (preg_match('/\A[0-9]{1,12}\z/', $timestamp) !== 1 || preg_match('/\A[0-9a-f]{64}\z/', $signature) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $bodySha) !== 1 || preg_match('/\A[0-9]{1,10}\z/', $length) !== 1) {
            return $this->reject(401, 'invalid_signature', 'Tanda tangan tidak valid.');
        }
        if (abs(now()->getTimestamp() - (int) $timestamp) > $this->tolerance()) {
            return $this->reject(401, 'expired_signature', 'Tanda tangan kedaluwarsa.');
        }

        $canonical = IntelligenceArtifactSignature::canonical(
            $request->getMethod(), $request->getPathInfo(), (int) $timestamp, $eventId, $kind, $contentType, (int) $length, $bodySha,
        );
        if (! hash_equals(IntelligenceArtifactSignature::sign($secret, $canonical), $signature)) {
            return $this->reject(401, 'invalid_signature', 'Tanda tangan tidak valid.');
        }

        if ($request->headers->has('Transfer-Encoding') || $request->headers->has('Content-Encoding')) {
            return $this->reject(415, 'unsupported_media_type', 'Transfer/Content-Encoding tidak didukung.');
        }
        if ($contentType !== IntelligenceArtifactContract::KINDS[$kind]) {
            return $this->reject(415, 'unsupported_media_type', 'Content-Type tidak sesuai jenis artefak.');
        }
        if ((int) $length < 1) {
            return $this->reject(422, 'invalid_artifact', 'Artefak kosong.');
        }
        if ((int) $length > IntelligenceArtifactContract::MAX_BYTES) {
            return $this->reject(413, 'artifact_too_large', 'Artefak melebihi batas ukuran.');
        }

        $request->attributes->set('artifact_signed', ['sha256' => $bodySha, 'length' => (int) $length]);

        return $next($request);
    }

    private function tolerance(): int
    {
        return max(1, (int) config('services.hermes_intelligence_artifact.tolerance_seconds', 300));
    }

    private function reject(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
