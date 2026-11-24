<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Identitas penerima tanpa PII: HMAC-SHA256 atas alamat yang sudah dinormalisasi.
 */
final class IntelligenceRecipientFingerprint
{
    private const MIN_PEPPER_LENGTH = 32;

    /**
     * Mengembalikan null bila alamat kosong atau formatnya tidak valid.
     */
    public function fingerprint(?string $email): ?string
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return hash_hmac('sha256', $normalized, $this->pepper());
    }

    /**
     * @throws RuntimeException bila pepper belum dikonfigurasi.
     */
    private function pepper(): string
    {
        $pepper = (string) config('services.intelligence.recipient_pepper');
        if (strlen($pepper) < self::MIN_PEPPER_LENGTH) {
            throw new RuntimeException('INTELLIGENCE_RECIPIENT_PEPPER belum dikonfigurasi (minimal 32 karakter).');
        }

        return $pepper;
    }
}
