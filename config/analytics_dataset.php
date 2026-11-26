<?php

declare(strict_types=1);

// Tidak ada kata sandi atau periode di konfigurasi: keduanya diberikan per eksekusi (lihat AnalyticsDatasetInvocation)
// agar aman terhadap config:cache dan tidak tersimpan di .env.
return [
    'dataset_key' => 'joyumkm-analytics',
    'dataset_version' => 'v1',
    'generator_seed' => 'joyumkm-analytics-v1',
    'account' => [
        'name' => 'Akun Analitik Sintetis',
        'email' => 'analitik.sintetis@dataset.invalid',
    ],
    'lock_timeout_seconds' => (int) env('ANALYTICS_DATASET_LOCK_TIMEOUT_SECONDS', 120),
];
