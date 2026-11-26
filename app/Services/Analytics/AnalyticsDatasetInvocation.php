<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Masukan satu eksekusi: kata sandi akun analitik dan (opsional) bulan akhir periode referensi.
 * Dibuat oleh command operator, tidak pernah dibaca dari konfigurasi atau env saat runtime.
 */
final class AnalyticsDatasetInvocation
{
    public function __construct(
        private readonly ?string $secret = null,
        private readonly ?string $referenceEndMonth = null,
    ) {}

    public function secret(): ?string
    {
        return $this->secret;
    }

    public function referenceEndMonth(): ?string
    {
        return $this->referenceEndMonth;
    }
}
