<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IntelligenceReport;
use App\Models\SixFactorEvaluation;
use App\Models\WebhookOutbox;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IntelligenceReport> */
class IntelligenceReportFactory extends Factory
{
    /**
     * Laporan tersimpan dengan evaluasi final dan event outbox v2 sungguhan (FK terpenuhi).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'evaluation_id' => fn (): int => SixFactorEvaluation::factory()->final()->create()->id,
            'umkm_id' => fn (array $attributes): int => SixFactorEvaluation::query()->findOrFail($attributes['evaluation_id'])->umkm_id,
            'event_id' => fn (array $attributes): string => $this->outboxEventFor((int) $attributes['evaluation_id']),
            'source_event_version' => 2,
            'schema_version' => 1,
            'prompt_version' => 'r1-prompt-5',
            'knowledge_version' => 'joyumkm-methodology-2026-11-22.1',
            'methodology_status' => IntelligenceReport::COMPLETE,
            'provider' => 'claude-subscription-directsdk-experimental',
            'model' => 'claude-sonnet-5-5[1m]',
            'report' => fn (): string => json_encode(self::sampleReport(), JSON_THROW_ON_ERROR),
            'report_hash' => fn (): string => hash('sha256', Str::random(32)),
            'generated_at' => now()->startOfSecond(),
            'received_at' => now()->startOfSecond(),
        ];
    }

    public function partial(): static
    {
        return $this->state(fn (): array => [
            'methodology_status' => IntelligenceReport::PARTIAL,
            'report' => json_encode(self::sampleReport(['limitations' => ['Data tiga dimensi tidak tersedia.']]), JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Isi `report` valid menurut kontrak callback.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function sampleReport(array $overrides = []): array
    {
        return [
            'executive_summary' => 'Ringkasan eksekutif contoh.',
            'business_profile' => ['name' => 'Usaha Contoh', 'sector' => 'Jasa', 'data_gaps' => []],
            'six_factor_analysis' => ['summary' => 'Ringkasan enam faktor.', 'findings' => []],
            'three_dimension_analysis' => ['available' => false, 'summary' => null, 'findings' => []],
            'cross_instrument_insights' => [],
            'perspectives' => [
                'business_development' => ['findings' => []],
                'marketing_sales' => ['findings' => []],
                'finance' => ['findings' => []],
                'operations_people' => ['findings' => []],
            ],
            'top_priorities' => [[
                'id' => 'P1',
                'title' => 'Rapikan pencatatan harian',
                'perspective' => 'operations_people',
                'priority' => 'high',
                'evidence_refs' => ['six_factor.scores.stabilitas'],
                'rationale' => 'Skor stabilitas rendah.',
                'actions' => ['Catat kas harian.'],
                'success_metrics' => ['Catatan kas lengkap 30 hari.'],
                'assumptions' => ['Pemilik dapat menyisihkan 15 menit per hari.'],
                'confidence' => 'medium',
            ]],
            'action_plan' => [
                'days_1_30' => [['priority_id' => 'P1', 'action' => 'Mulai buku kas.', 'metric' => '30 hari tercatat']],
                'days_31_60' => [],
                'days_61_90' => [],
            ],
            'limitations' => [],
            ...$overrides,
        ];
    }

    private function outboxEventFor(int $evaluationId): string
    {
        $evaluation = SixFactorEvaluation::query()->findOrFail($evaluationId);
        $eventId = (string) Str::uuid();
        $metadata = ['event_id' => $eventId, 'event_type' => 'evaluation.finalized', 'event_version' => 2];
        (new WebhookOutbox)->forceFill([
            ...$metadata,
            'aggregate_id' => $evaluation->id,
            'payload' => [
                ...$metadata,
                'source' => 'joyumkm',
                'occurred_at' => $evaluation->finalized_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'data' => [
                    'umkm_id' => $evaluation->umkm_id,
                    'business_context' => null,
                    'six_factor' => [
                        'evaluation_id' => $evaluation->id,
                        'status' => $evaluation->status,
                        'total_score' => $evaluation->total_score,
                        'category' => $evaluation->category,
                        'scores' => $evaluation->only(SixFactorEvaluation::FACTORS),
                    ],
                    'three_dimension' => null,
                ],
            ],
            'status' => WebhookOutbox::DELIVERED,
            'delivered_at' => now(),
        ])->save();

        return $eventId;
    }
}
