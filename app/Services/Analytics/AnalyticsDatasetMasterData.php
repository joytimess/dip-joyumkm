<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Factor;
use App\Models\Sector;
use Illuminate\Support\Facades\Schema;

/**
 * Preflight read-only: schema, master sektor, kuesioner aktif, dan provinsi. Gagal tertutup bila berbeda dari kontrak.
 *
 * @phpstan-import-type FactorMeta from AnalyticsDatasetPlanner
 */
final class AnalyticsDatasetMasterData
{
    /** @var array<string, array{code: string, scale_min: int, scale_max: int, questions: int}> */
    private const EXPECTED_FACTORS = [
        'INS' => ['code' => 'INS', 'scale_min' => 1, 'scale_max' => 3, 'questions' => 6],
        'ECT' => ['code' => 'ECT', 'scale_min' => 1, 'scale_max' => 5, 'questions' => 4],
        'OPS' => ['code' => 'OPS', 'scale_min' => 1, 'scale_max' => 5, 'questions' => 6],
    ];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'users', 'sectors', 'factors', 'questions', 'umkms', 'evaluations', 'evaluation_answers',
        'six_factor_evaluations', 'analytics_dataset_cohorts', 'webhook_outbox', 'jobs',
    ];

    /** @var list<string> */
    private const REQUIRED_UMKM_COLUMNS = [
        'user_id', 'name', 'province', 'city', 'sector_id', 'omzet', 'omzet_period', 'business_capital', 'year_founded',
        'employee_count', 'description', 'owner_name', 'whatsapp_number', 'business_email', 'wa_registration_status',
        'wa_checked_at', 'notification_consent_at', 'notification_consent_version', 'notification_consent_by_user_id',
    ];

    /**
     * @return array{sector_ids: array<string, int>, factors: list<FactorMeta>}
     *
     * @throws AnalyticsDatasetException
     */
    public function inspect(): array
    {
        $this->assertSchema();

        return [
            'sector_ids' => $this->sectorIds(),
            'factors' => $this->activeFactors(),
        ];
    }

    private function assertSchema(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw AnalyticsDatasetException::because("Tabel {$table} tidak ditemukan. Jalankan migrasi normal sebelum seeding.");
            }
        }
        if (! Schema::hasColumns('umkms', self::REQUIRED_UMKM_COLUMNS)) {
            throw AnalyticsDatasetException::because('Skema umkms tidak sesuai kontrak. Periksa migrasi profil UMKM.');
        }
        if (! Schema::hasColumn('six_factor_evaluations', 'period_month')) {
            throw AnalyticsDatasetException::because('Kolom six_factor_evaluations.period_month belum tersedia.');
        }
    }

    /**
     * @return array<string, int>
     */
    private function sectorIds(): array
    {
        $expected = ['makanan-minuman', 'grosir-retail', 'jasa', 'fashion-kriya', 'pertanian'];
        $ids = Sector::query()->whereIn('slug', $expected)->pluck('id', 'slug')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($expected as $slug) {
            if (! array_key_exists($slug, $ids)) {
                throw AnalyticsDatasetException::because("Master sektor {$slug} tidak ditemukan.");
            }
        }

        return $ids;
    }

    /**
     * @return list<FactorMeta>
     */
    private function activeFactors(): array
    {
        $factors = Factor::query()->orderBy('sort_order')->orderBy('id')
            ->with(['questions' => function ($query): void {
                $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
            }])->get();

        if ($factors->pluck('code')->sort()->values()->all() !== ['ECT', 'INS', 'OPS']) {
            throw AnalyticsDatasetException::because('Master faktor Tiga Dimensi harus berisi tepat INS, ECT, dan OPS.');
        }

        $result = [];
        foreach ($factors as $factor) {
            $expected = self::EXPECTED_FACTORS[$factor->code];
            if ((int) $factor->scale_min !== $expected['scale_min'] || (int) $factor->scale_max !== $expected['scale_max']) {
                throw AnalyticsDatasetException::because("Skala faktor {$factor->code} berbeda dari kontrak.");
            }
            if ($factor->questions->count() !== $expected['questions']) {
                throw AnalyticsDatasetException::because("Jumlah pertanyaan aktif {$factor->code} berbeda dari kontrak ({$expected['questions']}).");
            }

            $questions = [];
            foreach ($factor->questions as $question) {
                $values = array_map(fn (array $option): int => (int) $option['value'], (array) $question->options);
                sort($values);
                if ($values !== range($expected['scale_min'], $expected['scale_max'])) {
                    throw AnalyticsDatasetException::because("Opsi jawaban pertanyaan {$question->id} tidak sama dengan skala faktor {$factor->code}.");
                }
                $questions[] = ['id' => (int) $question->id, 'values' => $values];
            }

            $result[] = [
                'code' => $factor->code,
                'scale_min' => $expected['scale_min'],
                'scale_max' => $expected['scale_max'],
                'questions' => $questions,
            ];
        }

        $total = array_sum(array_map(fn (array $factor): int => count($factor['questions']), $result));
        if ($total !== 16) {
            throw AnalyticsDatasetException::because('Total pertanyaan aktif harus 16.');
        }

        return $result;
    }
}
