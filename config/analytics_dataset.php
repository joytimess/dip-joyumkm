<?php

declare(strict_types=1);

// Tidak ada kata sandi atau periode di konfigurasi: keduanya diberikan per eksekusi (lihat AnalyticsDatasetInvocation)
// agar aman terhadap config:cache dan tidak tersimpan di .env.
return [
    'dataset_key' => 'joyumkm-analytics',
    // v1 = 428 UMKM dimiliki akun sintetis (legacy). v2 = 458 UMKM, keanggotaan cohort, alokasi awal ke tiga Analyst.
    'dataset_version' => 'v2',
    'legacy_version' => 'v1',
    // Seed generator tidak boleh berubah: 428 profil awal harus tetap identik. 30 profil ekspansi memakai scope terpisah.
    'generator_seed' => 'joyumkm-analytics-v1',
    // Pemilik awal cohort (alokasi satu kali, posisi anggota % 3). Peran harus Analyst.
    'analyst_emails' => ['sample1@joyumkm.test', 'sample2@joyumkm.test', 'sample3@joyumkm.test'],
    // null = otomatis hanya di environment local; true/false eksplisit untuk opt-in di luar local (produksi default mati).
    'auto_seed' => env('ANALYTICS_DATASET_AUTO_SEED') === null ? null : filter_var(env('ANALYTICS_DATASET_AUTO_SEED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
    // Hanya dibaca saat membuat akun sintetis pada cohort baru; tidak pernah dicetak.
    'password' => env('ANALYTICS_DATASET_PASSWORD'),
    'account' => [
        'name' => 'Akun Analitik Sintetis',
        'email' => 'analitik.sintetis@dataset.invalid',
    ],
    'lock_timeout_seconds' => (int) env('ANALYTICS_DATASET_LOCK_TIMEOUT_SECONDS', 120),
];
