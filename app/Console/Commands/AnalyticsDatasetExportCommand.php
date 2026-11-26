<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsDatasetException;
use App\Services\Analytics\AnalyticsDatasetExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics-dataset:export {path? : Lokasi berkas CSV keluaran}')]
#[Description('Ekspor satu baris per UMKM sintetis cohort analitik ke CSV (UTF-8)')]
class AnalyticsDatasetExportCommand extends Command
{
    private const DEFAULT_PATH = 'storage/app/analytics-dataset/umkm-analytics-dataset.csv';

    public function handle(AnalyticsDatasetExporter $exporter): int
    {
        $path = (string) ($this->argument('path') ?? base_path(self::DEFAULT_PATH));

        try {
            $rows = $exporter->export($path);
        } catch (AnalyticsDatasetException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("CSV ditulis: {$rows} baris data ke {$path}");

        return self::SUCCESS;
    }
}
