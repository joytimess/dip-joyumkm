<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Analytics\AnalyticsDatasetException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

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

        if ($this->analyticsDatasetEnabled()) {
            $this->seedAnalyticsDataset();
        }
    }

    /**
     * Otomatis di local; di luar local hanya bila ANALYTICS_DATASET_AUTO_SEED=true (config analytics_dataset.auto_seed).
     * Produksi tetap mati secara default, dan pengujian (environment testing) tidak membuat dataset tanpa opt-in.
     */
    private function analyticsDatasetEnabled(): bool
    {
        $configured = config('analytics_dataset.auto_seed');

        return is_bool($configured) ? $configured : app()->environment('local');
    }

    private function seedAnalyticsDataset(): void
    {
        try {
            // Pemetaan peran sampel hanya sebelum alokasi awal; setelah v2 tidak pernah dijalankan ulang oleh seeder.
            if (app()->environment('local') && AnalyticsDatasetSeeder::requiresInitialAllocation()) {
                Artisan::call('joyumkm:map-sample-analysts');
            }
            $this->call(AnalyticsDatasetSeeder::class);
        } catch (AnalyticsDatasetException $exception) {
            if (! app()->environment('local')) {
                throw $exception;
            }
            $this->command?->warn('Dataset analitik dilewati: '.$exception->getMessage());
        }
    }
}
