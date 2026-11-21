<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sector;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SixFactorSummaryApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = '/api/dashboard/six-factor-summary';

    public function test_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_empty_portfolio_reports_zero_and_no_average(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary', [
                'total_umkm' => 0,
                'sudah_final' => 0,
                'belum_ada_final' => 0,
                'rata_rata_skor_final' => null,
            ])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_summary_uses_one_latest_final_per_umkm_and_ignores_draft_and_cancelled(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $revised = $this->umkm($user, 'Jaya Barokah');
        $this->final($revised, [2, 2, 2, 2, 2, 2], '2026-11-01 08:00:00');
        $latest = $this->final($revised, [4, 4, 4, 4, 4, 4], '2026-11-10 08:00:00');
        SixFactorEvaluation::factory()->draft()->create(['umkm_id' => $revised->id, 'sumber_daya' => 1]);

        $this->final($this->umkm($user, 'Batik Pesisir'), [5, 5, 5, 5, 5, 5], '2026-11-05 08:00:00');
        SixFactorEvaluation::factory()->draft()->create(['umkm_id' => $this->umkm($user, 'Roti Bahagia')->id]);
        SixFactorEvaluation::factory()->cancelled()->create(['umkm_id' => $this->umkm($user, 'Laundry Bersih')->id]);

        $response = $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('summary.total_umkm', 4)
            ->assertJsonPath('summary.sudah_final', 2)
            ->assertJsonPath('summary.belum_ada_final', 2)
            ->assertJsonPath('summary.rata_rata_skor_final', 27);

        $item = collect($response->json('data'))->firstWhere('name', 'Jaya Barokah');
        $this->assertTrue($item['has_final']);
        $this->assertSame($latest->id, $item['latest_final']['id']);
        $this->assertSame(24, $item['latest_final']['total_score']);
        $this->assertSame('Sehat', $item['latest_final']['category']);
        $this->assertSame(['sumber_daya' => 4, 'kepemimpinan' => 4, 'lingkungan_kerja' => 4, 'nilai_organisasi' => 4, 'stabilitas' => 4, 'kinerja' => 4], $item['latest_final']['factors']);

        $withoutFinal = collect($response->json('data'))->firstWhere('name', 'Roti Bahagia');
        $this->assertFalse($withoutFinal['has_final']);
        $this->assertNull($withoutFinal['latest_final']);
    }

    public function test_latest_final_orders_by_finalized_at_then_id(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $umkm = $this->umkm($user, 'Kopi Nusantara');

        $this->final($umkm, [3, 3, 3, 3, 3, 3], '2026-11-10 08:00:00');
        $this->final($umkm, [1, 1, 1, 1, 1, 1], '2026-11-01 08:00:00');
        $sameTimeLaterId = $this->final($umkm, [5, 5, 5, 5, 5, 5], '2026-11-10 08:00:00');

        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.0.latest_final.id', $sameTimeLaterId->id)
            ->assertJsonPath('data.0.latest_final.total_score', 30);
    }

    public function test_search_status_sort_and_pagination_apply_before_paging_while_kpi_stays_whole_scope(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $kuliner = Sector::query()->firstOrCreate(['slug' => 'kuliner'], ['name' => 'Kuliner']);

        $this->final($this->umkm($user, 'Jaya Barokah', ['sector_id' => $kuliner->id]), [5, 5, 5, 5, 5, 5], '2026-11-10 08:00:00');
        $this->final($this->umkm($user, 'Batik Pesisir'), [4, 4, 4, 4, 4, 4], '2026-11-09 08:00:00');
        $this->final($this->umkm($user, 'Toko Sinar Jaya'), [2, 2, 2, 2, 2, 2], '2026-11-08 08:00:00');
        $this->umkm($user, 'Mebel Mandiri');

        $this->assertSame(
            ['Jaya Barokah', 'Batik Pesisir', 'Toko Sinar Jaya', 'Mebel Mandiri'],
            $this->namesFor(['sort' => 'score_desc', 'per_page' => 2]),
        );
        $this->assertSame(
            ['Toko Sinar Jaya', 'Batik Pesisir', 'Jaya Barokah', 'Mebel Mandiri'],
            $this->namesFor(['sort' => 'score_asc', 'per_page' => 2]),
        );
        $this->assertSame(
            ['Batik Pesisir', 'Jaya Barokah', 'Mebel Mandiri', 'Toko Sinar Jaya'],
            $this->namesFor(['sort' => 'name_asc', 'per_page' => 3]),
        );

        $this->assertSame(['Jaya Barokah', 'Toko Sinar Jaya'], $this->namesFor(['search' => 'JAYA']));
        $this->assertSame(['Jaya Barokah'], $this->namesFor(['search' => 'kuliner']));
        $this->assertSame(['Mebel Mandiri'], $this->namesFor(['status' => 'none']));
        $this->assertSame(['Batik Pesisir', 'Jaya Barokah', 'Toko Sinar Jaya'], $this->namesFor(['status' => 'final', 'sort' => 'name_asc']));

        $whole = $this->getJson(self::ENDPOINT)->assertOk()->json('summary');
        $this->assertSame($whole, $this->getJson(self::ENDPOINT.'?'.http_build_query(['status' => 'none', 'search' => 'mebel', 'per_page' => 1]))->assertOk()->json('summary'));
        $this->assertSame(['total_umkm' => 4, 'sudah_final' => 3, 'belum_ada_final' => 1, 'rata_rata_skor_final' => 22], $whole);
    }

    public function test_pagination_reports_stable_counts_and_limits_per_page(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        foreach (['Alfa', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
            $this->umkm($user, $name);
        }

        $this->getJson(self::ENDPOINT.'?per_page=2&page=3&sort=name_asc')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Echo')
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('summary.total_umkm', 5);

        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.per_page', 4);
    }

    public function test_other_accounts_are_never_listed_or_counted(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->umkm($owner, 'Warung Owner');

        $foreign = $this->umkm(User::factory()->create(), 'Warung Owner Lain');
        $this->final($foreign, [5, 5, 5, 5, 5, 5], '2026-11-10 08:00:00');

        $this->getJson(self::ENDPOINT.'?search=warung')->assertOk()
            ->assertJsonPath('data.0.name', 'Warung Owner')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.total_umkm', 1)
            ->assertJsonPath('summary.sudah_final', 0)
            ->assertJsonPath('summary.rata_rata_skor_final', null);
    }

    public function test_invalid_parameters_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'sort' => 'umkms.id; DROP TABLE umkms',
            'status' => 'draft',
            'page' => 0,
            'per_page' => 25,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['sort', 'status', 'page', 'per_page']);
    }

    private function umkm(User $user, string $name, array $attributes = []): Umkm
    {
        return Umkm::factory()->for($user)->create(['name' => $name, ...$attributes]);
    }

    /** @param list<int> $scores */
    private function final(Umkm $umkm, array $scores, string $finalizedAt): SixFactorEvaluation
    {
        $total = array_sum($scores);

        return SixFactorEvaluation::factory()->create([
            ...array_combine(SixFactorEvaluation::FACTORS, $scores),
            'umkm_id' => $umkm->id,
            'status' => SixFactorEvaluation::FINAL,
            'total_score' => $total,
            'category' => match (true) {
                $total <= 12 => 'Kurang Sehat',
                $total <= 18 => 'Cukup Sehat',
                $total <= 24 => 'Sehat',
                default => 'Sangat Sehat',
            },
            'finalized_at' => $finalizedAt,
            'cancelled_at' => null,
        ]);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return list<string>
     */
    private function namesFor(array $query): array
    {
        $names = [];
        $page = 1;
        do {
            $response = $this->getJson(self::ENDPOINT.'?'.http_build_query([...$query, 'page' => $page]))->assertOk();
            array_push($names, ...array_column($response->json('data'), 'name'));
            $lastPage = $response->json('meta.last_page');
            $page++;
        } while ($page <= $lastPage);

        return $names;
    }
}
