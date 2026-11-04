<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

class SectorSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'makanan-minuman' => 'Makanan & Minuman',
            'grosir-retail' => 'Grosir & Retail',
            'jasa' => 'Jasa',
            'fashion-kriya' => 'Fashion & Kriya',
            'pertanian' => 'Pertanian',
        ] as $slug => $name) {
            Sector::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
