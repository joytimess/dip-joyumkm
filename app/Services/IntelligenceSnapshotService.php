<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\WebhookOutbox;
use Carbon\CarbonImmutable;

/**
 * Payload pull historis untuk Hermes (P3-01).
 *
 * Diturunkan dari satu event evaluation.finalized versi 2: anchor = Penilaian Enam Faktor pada event,
 * cutoff = finalized_at anchor. Hanya UMKM pada event yang dibaca. Tidak ada kontak, kredensial, atau data UMKM lain.
 */
class IntelligenceSnapshotService
{
    public const SCHEMA_VERSION = 1;

    public const HISTORY_MONTHS = 12;

    public const PERIOD_TIMEZONE = SixFactorEvaluation::PERIOD_TIMEZONE;

    public function __construct(
        private readonly SixFactorMonthlyRecapService $recap,
    ) {}

    /**
     * @return array<string, mixed>|null Null jika anchor event tidak lagi Final milik UMKM pada event.
     */
    public function forEvent(WebhookOutbox $event): ?array
    {
        $anchor = SixFactorEvaluation::query()->whereKey($event->aggregate_id)->first();
        $umkm = $anchor === null ? null : Umkm::query()->whereKey($anchor->umkm_id)->first();
        if ($anchor === null || $umkm === null || $anchor->status !== SixFactorEvaluation::FINAL
            || $anchor->finalized_at === null
            || (int) data_get($event->payload, 'data.umkm_id') !== $umkm->id) {
            return null;
        }

        $months = $this->recap->asOfEvaluation($umkm, $anchor, self::HISTORY_MONTHS);
        $current = $months[0];
        $previous = $months[1];
        $cutoffAt = CarbonImmutable::instance($anchor->finalized_at)->utc();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'event_version' => $event->event_version,
            'umkm_id' => $umkm->id,
            'evaluation_id' => $anchor->id,
            'cutoff' => [
                'at' => $cutoffAt->format('Y-m-d\TH:i:s\Z'),
                'evaluation_id' => $anchor->id,
            ],
            'period_timezone' => self::PERIOD_TIMEZONE,
            'period_month' => $current['period_month'],
            'previous_period_month' => $previous['period_month'],
            'history_months' => self::HISTORY_MONTHS,
            'current' => [
                'six_factor' => $current['six_factor'],
                'three_dimension' => $current['three_dimension'],
            ],
            'previous' => [
                'six_factor' => $previous['six_factor'],
                'three_dimension' => $previous['three_dimension'],
            ],
            'history' => $months,
            'availability' => $this->availability($months),
            'source' => $this->sources($months),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $months
     * @return array<string, mixed>
     */
    private function availability(array $months): array
    {
        return [
            'six_factor_final_months' => count(array_filter($months, static fn (array $row): bool => $row['six_factor']['status'] === 'available')),
            'three_dimension_months' => count(array_filter($months, static fn (array $row): bool => $row['three_dimension']['status'] === 'available')),
            'current_six_factor' => $months[0]['six_factor']['status'],
            'previous_six_factor' => $months[1]['six_factor']['status'],
            'current_three_dimension' => $months[0]['three_dimension']['status'],
            'previous_three_dimension' => $months[1]['three_dimension']['status'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $months
     * @return array{six_factor_evaluation_ids: list<int>, three_dimension_evaluation_ids: list<int>}
     */
    private function sources(array $months): array
    {
        $sixFactor = [];
        $threeDimension = [];
        foreach ($months as $row) {
            if ($row['six_factor']['evaluation_id'] !== null) {
                $sixFactor[] = $row['six_factor']['evaluation_id'];
            }
            if ($row['three_dimension']['evaluation_id'] !== null) {
                $threeDimension[] = $row['three_dimension']['evaluation_id'];
            }
        }

        $sixFactor = array_values(array_unique($sixFactor));
        $threeDimension = array_values(array_unique($threeDimension));
        sort($sixFactor);
        sort($threeDimension);

        return ['six_factor_evaluation_ids' => $sixFactor, 'three_dimension_evaluation_ids' => $threeDimension];
    }
}
