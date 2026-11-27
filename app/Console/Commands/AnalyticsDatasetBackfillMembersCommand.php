<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AnalyticsDatasetCohort;
use App\Models\AnalyticsDatasetMember;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('analytics-dataset:backfill-members')]
#[Description('Isi keanggotaan cohort sintetis dari provenance generator (idempoten, tanpa mengubah UMKM/pemilik)')]
class AnalyticsDatasetBackfillMembersCommand extends Command
{
    public function handle(): int
    {
        $cohorts = AnalyticsDatasetCohort::query()->orderBy('id')->get();
        if ($cohorts->isEmpty()) {
            $this->error('Tidak ada cohort terdaftar; backfill dihentikan.');

            return self::FAILURE;
        }

        // Keanggotaan yang sudah terbentuk adalah sumber kebenaran: hanya diverifikasi, tidak pernah diisi ulang dari pemilik.
        // Provenance pemilik hanya dipakai untuk migrasi legacy v1 sebelum keanggotaan ada, dan hanya bila kardinalitas
        // sama dengan umkm_count cohort; jika berbeda, hentikan tanpa menulis apa pun.
        $plans = [];
        foreach ($cohorts as $cohort) {
            $memberCount = AnalyticsDatasetMember::query()->where('analytics_dataset_cohort_id', $cohort->id)->count();
            if ($memberCount > 0) {
                if ($memberCount !== $cohort->umkm_count) {
                    $this->error("Cohort {$cohort->dataset_key}: {$memberCount} anggota, cohort mencatat {$cohort->umkm_count}. Rekonsiliasi manual diperlukan; tidak ada yang ditulis.");

                    return self::FAILURE;
                }

                continue;
            }
            if ($cohort->dataset_version !== config('analytics_dataset.legacy_version')) {
                $this->error("Cohort {$cohort->dataset_key} (versi {$cohort->dataset_version}) tidak memiliki anggota; backfill berbasis pemilik hanya untuk legacy. Dihentikan.");

                return self::FAILURE;
            }
            $umkms = DB::table('umkms')->where('user_id', $cohort->user_id)->orderBy('id')->get(['id', 'name']);
            if ($umkms->count() !== $cohort->umkm_count) {
                $this->error("Cohort {$cohort->dataset_key}: ditemukan {$umkms->count()} UMKM, cohort mencatat {$cohort->umkm_count}. Dihentikan.");

                return self::FAILURE;
            }
            $plans[] = [$cohort, $umkms];
        }

        $created = 0;
        DB::transaction(function () use ($plans, &$created): void {
            foreach ($plans as [$cohort, $umkms]) {
                foreach ($umkms as $umkm) {
                    $member = AnalyticsDatasetMember::query()->firstOrCreate(
                        ['umkm_id' => $umkm->id],
                        ['analytics_dataset_cohort_id' => $cohort->id, 'synthetic_key' => $umkm->name],
                    );
                    $created += $member->wasRecentlyCreated ? 1 : 0;
                }
            }
        });

        $total = AnalyticsDatasetMember::query()->count();
        $this->info("Backfill selesai: {$created} baris baru, total anggota {$total}.");

        return self::SUCCESS;
    }
}
