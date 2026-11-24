<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Kontrak tanda tangan unggah artefak versi 1 (HMAC-SHA256).
 *
 * String kanonis (UTF-8, dipisah "\n", tanpa newline akhir):
 * JMI-ARTIFACT-V1, METHOD, PATH, TIMESTAMP, EVENT_ID, KIND, CONTENT_TYPE, CONTENT_LENGTH, BODY_SHA256_HEX.
 */
final class IntelligenceArtifactSignature
{
    public const VERSION = 'JMI-ARTIFACT-V1';

    public const PATH_PREFIX = '/api/internal/intelligence/artifacts/';

    public static function path(string $eventId, string $kind): string
    {
        return self::PATH_PREFIX.$eventId.'/'.$kind;
    }

    public static function canonical(
        string $method,
        string $path,
        int $timestamp,
        string $eventId,
        string $kind,
        string $contentType,
        int $contentLength,
        string $bodySha256,
    ): string {
        return implode("\n", [
            self::VERSION, strtoupper($method), $path, (string) $timestamp, $eventId, $kind,
            $contentType, (string) $contentLength, $bodySha256,
        ]);
    }

    public static function sign(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Header lengkap untuk satu unggahan; dipakai klien QA dan pengujian.
     *
     * @return array<string, string>
     */
    public static function headers(string $secret, string $eventId, string $kind, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();
        $mime = IntelligenceArtifactContract::KINDS[$kind];
        $sha = hash('sha256', $body);
        $canonical = self::canonical('PUT', self::path($eventId, $kind), $timestamp, $eventId, $kind, $mime, strlen($body), $sha);

        return [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($body),
            'X-Intelligence-Signature-Version' => '1',
            'X-Intelligence-Timestamp' => (string) $timestamp,
            'X-Intelligence-Content-Sha256' => $sha,
            'X-Intelligence-Signature' => self::sign($secret, $canonical),
        ];
    }
}
