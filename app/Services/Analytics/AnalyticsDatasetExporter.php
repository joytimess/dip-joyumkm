<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\AnalyticsDatasetCohort;
use App\Models\SixFactorEvaluation;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ekspor CSV satu baris per UMKM sintetis cohort. Hanya membaca data milik akun cohort (tanpa lintas tenant).
 */
final class AnalyticsDatasetExporter
{
    /** @var list<string> */
    public const HEADER = [
        'umkm_id', 'name', 'sector', 'province', 'city', 'omzet', 'omzet_period', 'business_capital',
        'year_founded', 'employee_count', 'owner_name',
        'three_dimension_ins', 'three_dimension_ect', 'three_dimension_ops', 'three_dimension_average',
        'three_dimension_category', 'three_dimension_period', 'three_dimension_assessed_at',
        'six_factor_sumber_daya', 'six_factor_kepemimpinan', 'six_factor_lingkungan_kerja',
        'six_factor_nilai_organisasi', 'six_factor_stabilitas', 'six_factor_kinerja',
        'six_factor_total', 'six_factor_category', 'six_factor_period', 'six_factor_finalized_at',
        'dataset_key', 'dataset_version',
    ];

    /**
     * @return int Jumlah baris data (tidak termasuk header).
     *
     * @throws AnalyticsDatasetException
     */
    public function export(string $path): int
    {
        $connection = DB::connection();
        $cohort = AnalyticsDatasetCohort::query()->where('dataset_key', (string) config('analytics_dataset.dataset_key'))->first();
        if ($cohort === null) {
            throw AnalyticsDatasetException::because('Cohort dataset analitik belum ada; jalankan AnalyticsDatasetSeeder terlebih dahulu.');
        }

        $rows = $this->rows($connection, (int) $cohort->user_id, (string) $cohort->dataset_key, (string) $cohort->dataset_version);
        if (count($rows) !== AnalyticsDatasetPlanner::UMKM_COUNT
            || count(array_unique(array_column($rows, 'umkm_id'))) !== AnalyticsDatasetPlanner::UMKM_COUNT
            || count(array_unique(array_map(fn (array $row): string => mb_strtolower($row['name']), $rows))) !== AnalyticsDatasetPlanner::UMKM_COUNT) {
            throw AnalyticsDatasetException::because('Ekspor harus berisi tepat 428 baris dengan kunci UMKM dan nama unik.');
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw AnalyticsDatasetException::because('Direktori ekspor tidak dapat dibuat.');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw AnalyticsDatasetException::because('Berkas ekspor tidak dapat dibuka untuk ditulis.');
        }

        try {
            fputcsv($handle, self::HEADER, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (string $column): mixed => $row[$column], self::HEADER), ',', '"', '\\');
            }
        } finally {
            fclose($handle);
        }

        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(ConnectionInterface $connection, int $userId, string $datasetKey, string $datasetVersion): array
    {
        $umkms = $connection->table('umkms')
            ->join('sectors', 'sectors.id', '=', 'umkms.sector_id')
            ->where('umkms.user_id', $userId)
            ->orderBy('umkms.id')
            ->get(['umkms.id', 'umkms.name', 'sectors.slug as sector', 'umkms.province', 'umkms.city', 'umkms.omzet',
                'umkms.omzet_period', 'umkms.business_capital', 'umkms.year_founded', 'umkms.employee_count', 'umkms.owner_name'])
            ->all();

        $latestThree = [];
        $threeRows = $connection->table('evaluations')
            ->join('umkms', 'umkms.id', '=', 'evaluations.umkm_id')
            ->where('umkms.user_id', $userId)
            ->where('evaluations.status', 'final')
            ->orderBy('evaluations.id')
            ->get(['evaluations.id', 'evaluations.umkm_id', 'evaluations.ins_score', 'evaluations.ect_score',
                'evaluations.ops_score', 'evaluations.average_score', 'evaluations.category', 'evaluations.submitted_at'])
            ->all();
        foreach ($threeRows as $row) {
            $latestThree[(int) $row->umkm_id] = $row;
        }

        $latestSix = [];
        $sixRows = $connection->table('six_factor_evaluations')
            ->join('umkms', 'umkms.id', '=', 'six_factor_evaluations.umkm_id')
            ->where('umkms.user_id', $userId)
            ->where('six_factor_evaluations.status', SixFactorEvaluation::FINAL)
            ->orderBy('six_factor_evaluations.finalized_at')
            ->orderBy('six_factor_evaluations.id')
            ->select('six_factor_evaluations.*')
            ->get()
            ->all();
        foreach ($sixRows as $row) {
            $latestSix[(int) $row->umkm_id] = $row;
        }

        $rows = [];
        foreach ($umkms as $umkm) {
            $three = $latestThree[(int) $umkm->id] ?? throw AnalyticsDatasetException::because('UMKM tanpa evaluasi Tiga Dimensi terbaru.');
            $six = $latestSix[(int) $umkm->id] ?? throw AnalyticsDatasetException::because('UMKM tanpa evaluasi Enam Faktor terbaru.');
            $submittedAt = CarbonImmutable::parse((string) $three->submitted_at, 'UTC');
            $finalizedAt = CarbonImmutable::parse((string) $six->finalized_at, 'UTC');

            $rows[] = [
                'umkm_id' => (int) $umkm->id,
                'name' => (string) $umkm->name,
                'sector' => (string) $umkm->sector,
                'province' => (string) $umkm->province,
                'city' => (string) $umkm->city,
                'omzet' => (int) $umkm->omzet,
                'omzet_period' => (string) $umkm->omzet_period,
                'business_capital' => (int) $umkm->business_capital,
                'year_founded' => (int) $umkm->year_founded,
                'employee_count' => (int) $umkm->employee_count,
                'owner_name' => (string) $umkm->owner_name,
                'three_dimension_ins' => $three->ins_score,
                'three_dimension_ect' => $three->ect_score,
                'three_dimension_ops' => $three->ops_score,
                'three_dimension_average' => $three->average_score,
                'three_dimension_category' => $three->category,
                'three_dimension_period' => SixFactorEvaluation::periodMonthFor($submittedAt),
                'three_dimension_assessed_at' => $submittedAt->format('Y-m-d\TH:i:s\Z'),
                'six_factor_sumber_daya' => (int) $six->sumber_daya,
                'six_factor_kepemimpinan' => (int) $six->kepemimpinan,
                'six_factor_lingkungan_kerja' => (int) $six->lingkungan_kerja,
                'six_factor_nilai_organisasi' => (int) $six->nilai_organisasi,
                'six_factor_stabilitas' => (int) $six->stabilitas,
                'six_factor_kinerja' => (int) $six->kinerja,
                'six_factor_total' => (int) $six->total_score,
                'six_factor_category' => (string) $six->category,
                'six_factor_period' => (string) $six->period_month,
                'six_factor_finalized_at' => $finalizedAt->format('Y-m-d\TH:i:s\Z'),
                'dataset_key' => $datasetKey,
                'dataset_version' => $datasetVersion,
            ];
        }

        return $rows;
    }
}
