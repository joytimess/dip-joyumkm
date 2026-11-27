<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class SixFactorSummaryService
{
    /**
     * KPI ringkasan seluruh UMKM milik pengguna. Tidak dipengaruhi pencarian, filter, atau halaman.
     *
     * @return array{total_umkm: int, sudah_final: int, belum_ada_final: int, rata_rata_skor_final: ?float}
     */
    public function summary(User $user): array
    {
        $aggregate = $this->portfolioQuery($user)
            ->selectRaw('COUNT(umkms.id) AS total_umkm')
            ->selectRaw('COUNT(latest_final.id) AS sudah_final')
            ->selectRaw('AVG(latest_final.total_score) AS average_final_score')
            ->first();

        $totalUmkm = (int) $aggregate->total_umkm;
        $sudahFinal = (int) $aggregate->sudah_final;

        return [
            'total_umkm' => $totalUmkm,
            'sudah_final' => $sudahFinal,
            'belum_ada_final' => $totalUmkm - $sudahFinal,
            'rata_rata_skor_final' => $aggregate->average_final_score === null
                ? null
                : round((float) $aggregate->average_final_score, 1),
        ];
    }

    /**
     * @param  array{search?: string|null, status?: string|null, sort?: string|null, page?: int|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->rowsQuery($user);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function (QueryBuilder $match) use ($pattern): void {
                $match->whereRaw("LOWER(umkms.name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(sectors.name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(umkms.province) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        $status = $filters['status'] ?? 'all';
        if ($status === 'final') {
            $query->whereNotNull('latest_final.id');
        } elseif ($status === 'none') {
            $query->whereNull('latest_final.id');
        }

        $this->applySort($query, $filters['sort'] ?? 'name_asc');

        return $query->paginate(
            (int) ($filters['per_page'] ?? 4),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        )->withQueryString();
    }

    /**
     * Setiap UMKM milik pengguna dengan paling banyak satu Final terbaru. Draft dan Cancelled tidak masuk.
     */
    private function portfolioQuery(User $user): QueryBuilder
    {
        return $user->accessibleUmkms()->toBase()
            ->leftJoin('sectors', 'sectors.id', '=', 'umkms.sector_id')
            ->leftJoinSub($this->latestFinalIndex($user), 'final_index', function (JoinClause $join): void {
                $join->on('final_index.umkm_id', '=', 'umkms.id');
            })
            ->leftJoin('six_factor_evaluations as latest_final', 'latest_final.id', '=', 'final_index.id');
    }

    private function rowsQuery(User $user): QueryBuilder
    {
        $factorColumns = array_map(
            fn (string $factor): string => 'latest_final.'.$factor.' as final_'.$factor,
            SixFactorEvaluation::FACTORS,
        );

        return $this->portfolioQuery($user)->select([
            'umkms.id as umkm_id',
            'umkms.name',
            'umkms.province',
            'sectors.name as sector_name',
            'latest_final.id as final_id',
            'latest_final.total_score as final_total_score',
            'latest_final.category as final_category',
            'latest_final.finalized_at as final_finalized_at',
            ...$factorColumns,
        ]);
    }

    /**
     * Satu Final terbaru per UMKM dengan urutan finalized_at DESC, lalu id DESC.
     * Aturan ini sama dengan Umkm::latestSixFactorFinal().
     */
    private function latestFinalIndex(User $user): QueryBuilder
    {
        $ranked = SixFactorEvaluation::query()->toBase()
            ->select(['id', 'umkm_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY umkm_id ORDER BY finalized_at DESC, id DESC) AS final_position')
            ->where('status', SixFactorEvaluation::FINAL)
            ->whereIn('umkm_id', $user->accessibleUmkms()->toBase()->select('umkms.id'));

        return DB::query()
            ->fromSub($ranked, 'ranked_final')
            ->select(['id', 'umkm_id'])
            ->where('final_position', 1);
    }

    private function applySort(QueryBuilder $query, string $sort): void
    {
        if ($sort === 'name_asc') {
            $query->orderBy('umkms.name')->orderBy('umkms.id');

            return;
        }

        // UMKM tanpa Final selalu di belakang untuk kedua arah skor.
        $query->orderByRaw('latest_final.id IS NULL')
            ->orderBy('latest_final.total_score', $sort === 'score_desc' ? 'desc' : 'asc')
            ->orderBy('umkms.name')
            ->orderBy('umkms.id');
    }
}
