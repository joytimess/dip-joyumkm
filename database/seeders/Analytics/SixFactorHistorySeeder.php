<?php

declare(strict_types=1);

namespace Database\Seeders\Analytics;

use App\Models\SixFactorEvaluation;
use App\Services\Analytics\AnalyticsDatasetException;
use App\Services\Analytics\AnalyticsDatasetRun;
use Carbon\CarbonImmutable;

/**
 * Evaluasi final Enam Faktor. Tidak memanggil SixFactorEvaluationService::finalize() sehingga outbox webhook tidak ditulis.
 * period_month diturunkan oleh model (WIB) saat disimpan.
 */
final class SixFactorHistorySeeder
{
    public function __construct(private readonly AnalyticsDatasetRun $run) {}

    /**
     * @param  array{umkms: list<array{index: int, six: list<array{total: int, factors: array<string, int>, finalized_at: CarbonImmutable, created_at: CarbonImmutable}>}>}  $plan
     * @param  array<int, int>  $umkmIds
     * @return int Jumlah evaluasi yang ditulis.
     */
    public function seed(array $plan, array $umkmIds): int
    {
        $this->run->assertWritable();

        $written = 0;
        foreach ($plan['umkms'] as $profile) {
            $umkmId = $umkmIds[$profile['index']] ?? throw AnalyticsDatasetException::because('UMKM hasil tulis tidak ditemukan untuk rencana enam faktor.');

            foreach ($profile['six'] as $evaluationPlan) {
                $total = array_sum($evaluationPlan['factors']);
                if ($total !== $evaluationPlan['total']) {
                    throw AnalyticsDatasetException::because('Total enam faktor rencana tidak sama dengan jumlah faktor.');
                }

                $evaluation = new SixFactorEvaluation;
                $evaluation->forceFill([
                    'umkm_id' => $umkmId,
                    'status' => SixFactorEvaluation::FINAL,
                    ...$evaluationPlan['factors'],
                    'total_score' => $total,
                    'category' => SixFactorEvaluation::categoryForTotal($total),
                    'finalized_at' => $evaluationPlan['finalized_at'],
                    'cancelled_at' => null,
                    'created_at' => $evaluationPlan['created_at'],
                    'updated_at' => $evaluationPlan['finalized_at'],
                ])->save();
                $written++;
            }
        }

        return $written;
    }
}
