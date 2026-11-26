<?php

declare(strict_types=1);

namespace Database\Seeders\Analytics;

use App\Models\Evaluation;
use App\Services\Analytics\AnalyticsDatasetException;
use App\Services\Analytics\AnalyticsDatasetPlanner;
use App\Services\Analytics\AnalyticsDatasetRun;
use App\Services\ScoringService;
use Carbon\CarbonImmutable;

/**
 * Evaluasi final Tiga Dimensi beserta jawabannya. Skor dihitung ulang oleh ScoringService, bukan dari label generator.
 *
 * @phpstan-import-type FactorMeta from AnalyticsDatasetPlanner
 */
final class ThreeDimensionHistorySeeder
{
    private const ANSWER_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly AnalyticsDatasetRun $run,
        private readonly ScoringService $scoring,
    ) {}

    /**
     * @param  array{umkms: list<array{index: int, three: list<array{submitted_at: CarbonImmutable, answers: array<int, int>}>}>}  $plan
     * @param  array<int, int>  $umkmIds
     * @param  list<FactorMeta>  $factors
     * @return int Jumlah evaluasi yang ditulis.
     */
    public function seed(array $plan, array $umkmIds, array $factors): int
    {
        $this->run->assertWritable();
        $connection = $this->run->connection();

        $scoringFactors = array_map(
            fn (array $factor): array => ['code' => $factor['code'], 'scale_min' => $factor['scale_min'], 'scale_max' => $factor['scale_max']],
            $factors,
        );

        $written = 0;
        $answerRows = [];
        foreach ($plan['umkms'] as $profile) {
            $umkmId = $umkmIds[$profile['index']] ?? throw AnalyticsDatasetException::because('UMKM hasil tulis tidak ditemukan untuk rencana evaluasi.');

            foreach ($profile['three'] as $evaluationPlan) {
                $valuesByCode = [];
                foreach ($factors as $factor) {
                    foreach ($factor['questions'] as $question) {
                        $value = $evaluationPlan['answers'][$question['id']] ?? throw AnalyticsDatasetException::because('Jawaban pertanyaan aktif tidak lengkap.');
                        $valuesByCode[$factor['code']][] = $value;
                    }
                }

                $scores = $this->scoring->calculate($scoringFactors, $valuesByCode);
                $evaluation = new Evaluation;
                $evaluation->forceFill([
                    'umkm_id' => $umkmId,
                    'status' => 'final',
                    'ins_score' => $scores['factor_scores']['INS'],
                    'ect_score' => $scores['factor_scores']['ECT'],
                    'ops_score' => $scores['factor_scores']['OPS'],
                    'average_score' => $scores['average_score'],
                    'category' => $scores['category'],
                    'submitted_at' => $evaluationPlan['submitted_at'],
                    'created_at' => $evaluationPlan['submitted_at'],
                    'updated_at' => $evaluationPlan['submitted_at'],
                ])->save();
                $written++;

                $timestamp = $evaluationPlan['submitted_at']->format('Y-m-d H:i:s');
                foreach ($factors as $factor) {
                    foreach ($factor['questions'] as $question) {
                        $answerRows[] = [
                            'evaluation_id' => $evaluation->id,
                            'question_id' => $question['id'],
                            'value' => $evaluationPlan['answers'][$question['id']],
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }

                if (count($answerRows) >= self::ANSWER_CHUNK_SIZE) {
                    $connection->table('evaluation_answers')->insert($answerRows);
                    $answerRows = [];
                }
            }
        }

        if ($answerRows !== []) {
            $connection->table('evaluation_answers')->insert($answerRows);
        }

        return $written;
    }
}
