<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationAnswer;
use App\Models\Question;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

class ThreeDimensionHealthService
{
    public function __construct(private readonly ScoringService $scoringService) {}

    /**
     * Ringkasan portofolio Tiga Dimensi. Selalu dihitung dari seluruh UMKM dalam cakupan pengguna,
     * tidak dipengaruhi filter status daftar.
     *
     * @return array{total_umkms: int, with_final: int, draft_only: int, not_assessed: int, overall_final_average: ?float, dimension_final_averages: array{INS: ?float, ECT: ?float, OPS: ?float}}
     */
    public function summary(User $user): array
    {
        $aggregate = $this->portfolioQuery($user)
            ->selectRaw('COUNT(umkms.id) AS total_umkms')
            ->selectRaw('COUNT(latest_final.id) AS with_final')
            ->selectRaw('SUM(CASE WHEN latest_final.id IS NULL AND latest_draft.id IS NOT NULL THEN 1 ELSE 0 END) AS draft_only')
            ->selectRaw('SUM(CASE WHEN latest_final.id IS NULL AND latest_draft.id IS NULL THEN 1 ELSE 0 END) AS not_assessed')
            ->selectRaw('AVG(latest_final.average_score) AS overall_final_average')
            ->selectRaw('AVG(latest_final.ins_score) AS ins_average')
            ->selectRaw('AVG(latest_final.ect_score) AS ect_average')
            ->selectRaw('AVG(latest_final.ops_score) AS ops_average')
            ->first();

        return [
            'total_umkms' => (int) $aggregate->total_umkms,
            'with_final' => (int) $aggregate->with_final,
            'draft_only' => (int) $aggregate->draft_only,
            'not_assessed' => (int) $aggregate->not_assessed,
            'overall_final_average' => $this->roundedAverage($aggregate->overall_final_average),
            'dimension_final_averages' => [
                'INS' => $this->roundedAverage($aggregate->ins_average),
                'ECT' => $this->roundedAverage($aggregate->ect_average),
                'OPS' => $this->roundedAverage($aggregate->ops_average),
            ],
        ];
    }

    /**
     * @param  array{status?: string|null, page?: int|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $status = $filters['status'] ?? 'all';

        $query = $this->rowsQuery($user);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw("umkms.name LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }
        if ($status !== 'all') {
            $query = $this->filterByState($query, $status);
        }

        return $query->orderBy('umkms.name')
            ->orderBy('umkms.id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString();
    }

    public function detail(User $user, int $umkmId): object
    {
        $row = $this->rowsQuery($user)->where('umkms.id', $umkmId)->first();
        abort_if($row === null, 404, 'UMKM tidak ditemukan.');

        return $row;
    }

    /**
     * Relasi Final terbaru dan Draft terbaru per UMKM. Hanya baris yang dimiliki pengguna yang masuk.
     */
    private function portfolioQuery(User $user): QueryBuilder
    {
        $latestFinalIds = $this->latestEvaluationIds('final');
        $latestDraftIds = $this->latestEvaluationIds('draft');

        return $user->accessibleUmkms()->toBase()
            ->leftJoinSub($latestFinalIds, 'final_index', function (JoinClause $join): void {
                $join->on('final_index.umkm_id', '=', 'umkms.id');
            })
            ->leftJoin('evaluations as latest_final', 'latest_final.id', '=', 'final_index.id')
            ->leftJoinSub($latestDraftIds, 'draft_index', function (JoinClause $join): void {
                $join->on('draft_index.umkm_id', '=', 'umkms.id');
            })
            ->leftJoin('evaluations as latest_draft', 'latest_draft.id', '=', 'draft_index.id');
    }

    private function rowsQuery(User $user): QueryBuilder
    {
        return $this->portfolioQuery($user)
            ->select([
                'umkms.id as umkm_id',
                'umkms.name',
                'latest_final.id as final_id',
                'latest_final.average_score as final_average_score',
                'latest_final.category as final_category',
                'latest_final.ins_score as final_ins_score',
                'latest_final.ect_score as final_ect_score',
                'latest_final.ops_score as final_ops_score',
                'latest_final.submitted_at as final_finalized_at',
                'latest_draft.id as draft_id',
                'latest_draft.updated_at as draft_updated_at',
            ])
            ->selectSub($this->answeredCountQuery('latest_final.id'), 'final_answered_count')
            ->selectSub($this->answeredCountQuery('latest_draft.id'), 'draft_answered_count')
            ->selectSub(
                Question::query()->toBase()->where('questions.is_active', true)->selectRaw('COUNT(*)'),
                'question_count',
            );
    }

    private function latestEvaluationIds(string $status): QueryBuilder
    {
        return Evaluation::query()->toBase()
            ->select('umkm_id')
            ->selectRaw('MAX(id) AS id')
            ->where('status', $status)
            ->groupBy('umkm_id');
    }

    private function answeredCountQuery(string $evaluationIdColumn): QueryBuilder
    {
        return EvaluationAnswer::query()->toBase()
            ->join('questions', 'questions.id', '=', 'evaluation_answers.question_id')
            ->where('questions.is_active', true)
            ->whereColumn('evaluation_answers.evaluation_id', $evaluationIdColumn)
            ->selectRaw('COUNT(*)');
    }

    private function filterByState(QueryBuilder $query, string $status): QueryBuilder
    {
        return match ($status) {
            'final' => $query->whereNotNull('latest_final.id'),
            'draft' => $query->whereNull('latest_final.id')->whereNotNull('latest_draft.id'),
            default => $query->whereNull('latest_final.id')->whereNull('latest_draft.id'),
        };
    }

    private function roundedAverage(mixed $value): ?float
    {
        return $this->scoringService->roundScore($value === null ? null : (float) $value);
    }
}
