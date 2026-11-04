<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sector;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Umkm> */
class UmkmFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->company(),
            'province' => 'DKI Jakarta',
            'sector_id' => fn (): int => (int) Sector::query()->firstOrCreate(
                ['slug' => 'jasa'],
                ['name' => 'Jasa'],
            )->id,
            'omzet' => fake()->numberBetween(0, 100000000),
        ];
    }
}
