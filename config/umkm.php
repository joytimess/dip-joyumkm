<?php

declare(strict_types=1);

return [
    'intervention_threshold' => 70,
    'token_expiration_days' => (int) env('SANCTUM_TOKEN_EXPIRATION_DAYS', 7),
    'demo_password' => env('DEMO_PASSWORD'),
    'seed_password' => env('SEED_PASSWORD'),
    'seed_profile' => env('SEED_PROFILE', 'small'),
    'seed_profiles' => [
        'small' => [
            'accounts' => 3,
            'umkms' => 20,
            'max_evaluations' => 3,
        ],
        'benchmark' => [
            'accounts' => 10,
            'umkms' => 1000,
            'max_evaluations' => 10,
        ],
    ],
    'scoring' => [
        'factor_precision' => 4,
        'score_precision' => 1,
        'rounding' => 'half_up',
        'categories' => [
            ['minimum' => 85, 'label' => 'SANGAT BAIK', 'color' => '#166534'],
            ['minimum' => 70, 'label' => 'BAIK', 'color' => '#15803d'],
            ['minimum' => 55, 'label' => 'CUKUP', 'color' => '#a16207'],
            ['minimum' => 40, 'label' => 'KURANG', 'color' => '#c2410c'],
            ['minimum' => 0, 'label' => 'KRITIS', 'color' => '#b91c1c'],
        ],
        'unrated' => ['label' => 'BELUM DINILAI', 'color' => '#6b7280'],
    ],
];
