<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class UmkmSummaryService
{
    public function __construct(
        private readonly UmkmService $umkmService,
        private readonly ScoringService $scoringService,
    ) {}

    public function get(User $user): array
    {
        $aggregate = $this->umkmService->queryWithLatest($user)->toBase()
            ->select([])
            ->selectRaw('COUNT(*) AS total_umkms, COUNT(latest_evaluation.id) AS total_respondents')
            ->selectRaw('COUNT(latest_evaluation.average_score) AS scored_count')
            ->selectRaw("SUM(CASE WHEN latest_evaluation.status = 'draft' THEN 1 ELSE 0 END) AS draft_count")
            ->selectRaw('AVG(latest_evaluation.average_score) AS average_score')
            ->first();
        $average = $this->scoringService->roundScore(
            $aggregate->average_score === null ? null : (float) $aggregate->average_score,
        );
        $criticalSector = null;
        foreach ($this->sectorAverages($user) as $sector) {
            if ($sector['average_score'] !== null) {
                $criticalSector = [
                    ...$sector,
                    'needs_intervention' => $sector['average_score'] < (float) config('umkm.intervention_threshold'),
                ];

                break;
            }
        }

        return [
            'total_umkms' => (int) $aggregate->total_umkms,
            'total_respondents' => (int) $aggregate->total_respondents,
            'scored_count' => (int) $aggregate->scored_count,
            'draft_count' => (int) $aggregate->draft_count,
            'average_score' => $average,
            'category' => $this->scoringService->category($average),
            'critical_sector' => $criticalSector,
        ];
    }

    public function sectorAverages(User $user): array
    {
        return $this->umkmService->queryWithLatest($user)->toBase()
            ->join('sectors', 'sectors.id', '=', 'umkms.sector_id')
            ->select(['sectors.id', 'sectors.name'])
            ->selectRaw('COUNT(*) AS total_count, COUNT(latest_evaluation.average_score) AS scored_count')
            ->selectRaw("SUM(CASE WHEN latest_evaluation.status = 'draft' THEN 1 ELSE 0 END) AS draft_count")
            ->selectRaw('AVG(latest_evaluation.average_score) AS average_score')
            ->groupBy('sectors.id', 'sectors.name')
            ->orderByRaw('AVG(latest_evaluation.average_score) IS NULL ASC')
            ->orderByRaw('ROUND(AVG(latest_evaluation.average_score), ?) ASC', [(int) config('umkm.scoring.score_precision')])
            ->orderBy('sectors.name')
            ->orderBy('sectors.id')
            ->get()
            ->map(fn (object $sector): array => [
                'id' => (int) $sector->id,
                'name' => $sector->name,
                'average_score' => $this->scoringService->roundScore(
                    $sector->average_score === null ? null : (float) $sector->average_score,
                ),
                'total_count' => (int) $sector->total_count,
                'scored_count' => (int) $sector->scored_count,
                'draft_count' => (int) $sector->draft_count,
            ])->all();
    }
}
