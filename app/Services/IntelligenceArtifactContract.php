<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Kontrak manifest artefak PDF/XLSX. Manifest bersifat opsional dan tidak masuk report_hash.
 */
final class IntelligenceArtifactContract
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const STORAGE_DISK = 'local';

    /** @var array<string, string> kind => MIME yang wajib cocok */
    public const KINDS = [
        'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Memvalidasi manifest callback. Field yang tidak dikenal diabaikan; kind, MIME, ukuran, dan SHA-256 wajib valid.
     *
     * @return list<array{kind: string, mime: string, size_bytes: int, sha256: string}>
     *
     * @throws ValidationException
     */
    public static function normalizeManifest(mixed $artifacts): array
    {
        if ($artifacts === null) {
            return [];
        }
        if (! is_array($artifacts) || ! array_is_list($artifacts) || count($artifacts) > count(self::KINDS)) {
            throw self::invalid();
        }

        $byKind = [];
        foreach ($artifacts as $artifact) {
            $entry = self::normalizeEntry($artifact);
            if (isset($byKind[$entry['kind']])) {
                throw self::invalid();
            }
            $byKind[$entry['kind']] = $entry;
        }

        $ordered = [];
        foreach (array_keys(self::KINDS) as $kind) {
            if (isset($byKind[$kind])) {
                $ordered[] = $byKind[$kind];
            }
        }

        return $ordered;
    }

    /**
     * SHA-256 atas manifest kanonis; dipakai sebagai artifact_set_hash pada pengiriman.
     *
     * @param  list<array{kind: string, mime: string, size_bytes: int, sha256: string}>  $manifest
     */
    public static function setHash(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{kind: string, mime: string, size_bytes: int, sha256: string}
     */
    private static function normalizeEntry(mixed $artifact): array
    {
        if (! is_array($artifact)) {
            throw self::invalid();
        }

        $kind = $artifact['kind'] ?? null;
        $mime = $artifact['mime'] ?? null;
        $sizeBytes = $artifact['size_bytes'] ?? null;
        $sha256 = $artifact['sha256'] ?? null;

        if (! is_string($kind) || ! array_key_exists($kind, self::KINDS) || $mime !== self::KINDS[$kind]
            || ! is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > self::MAX_BYTES
            || ! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw self::invalid();
        }

        return ['kind' => $kind, 'mime' => $mime, 'size_bytes' => $sizeBytes, 'sha256' => $sha256];
    }

    private static function invalid(): ValidationException
    {
        return ValidationException::withMessages(['artifacts' => ['Manifest artefak tidak valid.']]);
    }
}
