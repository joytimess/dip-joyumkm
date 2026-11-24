<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailSuppression;
use InvalidArgumentException;

class EmailSuppressionService
{
    public function __construct(private readonly IntelligenceRecipientFingerprint $fingerprints) {}

    /**
     * Mencatat suppression satu kali per alamat. Mengembalikan true bila suppression baru dibuat.
     */
    public function suppress(string $email, string $reason, string $source): bool
    {
        if (! in_array($reason, EmailSuppression::REASONS, true)) {
            throw new InvalidArgumentException('Alasan suppression tidak dikenal.');
        }

        $fingerprint = $this->fingerprints->fingerprint($email)
            ?? throw new InvalidArgumentException('Alamat email tidak valid.');

        return EmailSuppression::query()->firstOrCreate(
            ['email_fingerprint' => $fingerprint],
            ['reason' => $reason, 'source' => $source, 'created_at' => now()],
        )->wasRecentlyCreated;
    }

    public function isSuppressed(string $email): bool
    {
        $fingerprint = $this->fingerprints->fingerprint($email);

        return $fingerprint !== null && $this->containsFingerprint($fingerprint);
    }

    public function containsFingerprint(string $fingerprint): bool
    {
        return EmailSuppression::query()->where('email_fingerprint', $fingerprint)->exists();
    }
}
