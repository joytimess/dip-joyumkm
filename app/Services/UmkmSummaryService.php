<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
            'portfolio' => $user->isSuperadmin() ? $this->portfolio($user) : null,
        ];
    }

    /**
     * Ringkasan lintas-Analyst untuk Superadmin. Hitungan evaluasi mengikuti filter analyst_id; daftar per-Analyst selalu lengkap.
     *
     * @return array<string, mixed>
     */
    private function portfolio(User $user): array
    {
        $scope = $user->accessibleUmkms()->select('umkms.id');
        $members = DB::table('analytics_dataset_members')->selectRaw('1')->whereColumn('analytics_dataset_members.umkm_id', 'umkms.id');
        $analysts = User::query()->where('role', UserRole::Analyst->value)
            ->withCount(['umkms as umkms_count', 'umkms as cohort_umkms_count' => fn ($query) => $query->whereExists($members)])
            ->orderBy('name')->orderBy('id')->get();
        $sixFactor = DB::table('six_factor_evaluations')->whereIn('umkm_id', $scope)
            ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status = 'final' THEN 1 ELSE 0 END) AS final_count")->first();

        return [
            'total_analysts' => $analysts->count(),
            'active_analysts' => $analysts->where('is_active', true)->count(),
            'three_dimension_evaluations' => DB::table('evaluations')->whereIn('umkm_id', $scope)->count(),
            'six_factor_evaluations' => (int) $sixFactor->total,
            'six_factor_final_evaluations' => (int) $sixFactor->final_count,
            'per_analyst' => $analysts->map(fn (User $analyst): array => [
                'id' => (int) $analyst->id,
                'name' => $analyst->name,
                'is_active' => $analyst->isActive(),
                'umkms_count' => (int) $analyst->umkms_count,
                'cohort_umkms_count' => (int) $analyst->cohort_umkms_count,
                'non_cohort_umkms_count' => (int) $analyst->umkms_count - (int) $analyst->cohort_umkms_count,
            ])->all(),
            'umkms_by_owner_role' => DB::table('umkms')->join('users', 'users.id', '=', 'umkms.user_id')
                ->selectRaw('users.role AS role, COUNT(*) AS total')->groupBy('users.role')->pluck('total', 'role')
                ->map(fn ($total): int => (int) $total)->all(),
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
