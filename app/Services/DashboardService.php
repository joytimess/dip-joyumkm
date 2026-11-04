<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvaluationAnswer;
use App\Models\Question;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    private const FACTOR_COLUMNS = ['INS' => 'ins_score', 'ECT' => 'ect_score', 'OPS' => 'ops_score'];

    public function __construct(
        private readonly UmkmService $umkmService,
        private readonly UmkmSummaryService $umkmSummaryService,
    ) {}

    public function factorProfile(User $user, int $id): array
    {
        $umkm = $this->selectedQuery($user)->where('umkms.id', $id)->firstOrFail();
        $portfolio = $this->portfolio($user);

        return [
            'umkm' => ['id' => (int) $umkm->id, 'name' => $umkm->name],
            'factors' => array_keys(self::FACTOR_COLUMNS),
            'selected_scores' => $this->scores($umkm),
            'portfolio_averages' => $portfolio['averages'],
            'sample_count_per_factor' => $portfolio['counts'],
            'evaluation_status' => $umkm->evaluation_status ?? 'none',
            'answered_count' => (int) $umkm->answered_count,
            'total_questions' => Question::query()->where('is_active', true)->count(),
        ];
    }

    public function distribution(User $user): array
    {
        $counts = $this->umkmService->queryWithLatest($user)->toBase()
            ->select('latest_evaluation.category')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('latest_evaluation.category')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $row->category ?? config('umkm.scoring.unrated.label') => (int) $row->total,
            ])->all();
        $categories = array_map(fn (array $category): array => [
            'label' => $category['label'],
            'color' => $category['color'],
            'count' => $counts[$category['label']] ?? 0,
        ], [...config('umkm.scoring.categories'), config('umkm.scoring.unrated')]);

        return [
            'total_umkms' => array_sum($counts),
            'categories' => $categories,
            'sectors' => $this->umkmSummaryService->sectorAverages($user),
        ];
    }

    public function comparison(User $user, array $data): array
    {
        if (array_key_exists('umkm_ids', $data)) {
            $ids = array_map('intval', $data['umkm_ids']);
            $selected = $this->selectedQuery($user)->whereIn('umkms.id', $ids)->get();
            abort_unless($selected->count() === count($ids), 404);
            $selected = $selected->sortBy(fn (Umkm $umkm): int => (int) array_search($umkm->id, $ids, true))->values();
        } else {
            $selected = $this->selectedQuery($user)->whereNotNull('latest_evaluation.average_score')
                ->orderByDesc('latest_evaluation.average_score')->orderBy('umkms.id')->limit(5)->get();
        }
        $portfolio = $this->portfolio($user);
        $totalQuestions = Question::query()->where('is_active', true)->count();

        return [
            'factors' => array_keys(self::FACTOR_COLUMNS),
            'selected_ids' => $selected->modelKeys(),
            'umkms' => $selected->map(fn (Umkm $umkm): array => [
                'id' => (int) $umkm->id,
                'name' => $umkm->name,
                'scores' => $this->scores($umkm),
                'sample_count_per_factor' => array_map(fn (?float $score): int => $score === null ? 0 : 1, $this->scores($umkm)),
                'evaluation_status' => $umkm->evaluation_status ?? 'none',
                'answered_count' => (int) $umkm->answered_count,
                'total_questions' => $totalQuestions,
            ])->all(),
            'portfolio_averages' => $portfolio['averages'],
            'sample_count_per_factor' => $portfolio['counts'],
        ];
    }

    private function selectedQuery(User $user): Builder
    {
        return $this->umkmService->queryWithLatest($user)->addSelect([
            'answered_count' => EvaluationAnswer::query()
                ->join('questions', 'questions.id', '=', 'evaluation_answers.question_id')
                ->where('questions.is_active', true)
                ->whereColumn('evaluation_answers.evaluation_id', 'latest_evaluation.id')
                ->selectRaw('COUNT(*)'),
        ]);
    }

    private function portfolio(User $user): array
    {
        $query = $this->umkmService->queryWithLatest($user)->toBase()->select([]);
        foreach (self::FACTOR_COLUMNS as $column) {
            $query->selectRaw('AVG(latest_evaluation.'.$column.') AS '.$column)
                ->selectRaw('COUNT(latest_evaluation.'.$column.') AS '.$column.'_count');
        }
        $aggregate = $query->first();
        $averages = [];
        $counts = [];
        foreach (self::FACTOR_COLUMNS as $column) {
            $averages[] = $aggregate->{$column} === null ? null : (float) $aggregate->{$column};
            $counts[] = (int) $aggregate->{$column.'_count'};
        }

        return ['averages' => $averages, 'counts' => $counts];
    }

    private function scores(Umkm $umkm): array
    {
        return array_map(
            fn (string $column): ?float => $umkm->{$column} === null ? null : (float) $umkm->{$column},
            array_values(self::FACTOR_COLUMNS),
        );
    }
}
