<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SectorSeeder::class,
            QuestionnaireSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
            $this->call(SampleDataSeeder::class);
        }
    }
}
