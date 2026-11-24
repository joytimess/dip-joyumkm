<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Services\SixFactorEvaluationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SixFactorPeriodMonthTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function wibBoundaries(): array
    {
        return [
            'detik terakhir Oktober WIB' => ['2026-10-31 16:59:59', '2026-10'],
            'detik pertama November WIB' => ['2026-10-31 17:00:00', '2026-11'],
            'detik terakhir tahun 2026 WIB' => ['2026-12-31 16:59:59', '2026-12'],
            'detik pertama 2027 WIB' => ['2026-12-31 17:00:00', '2027-01'],
            'tahun kabisat' => ['2028-02-29 10:00:00', '2028-02'],
            'tengah malam UTC masih bulan berikutnya di WIB' => ['2026-06-30 18:00:00', '2026-07'],
        ];
    }

    #[DataProvider('wibBoundaries')]
    public function test_period_month_follows_wib_and_the_instant_stays_utc(string $utc, string $expected): void
    {
        $instant = CarbonImmutable::parse($utc, 'UTC');

        $this->assertSame($expected, SixFactorEvaluation::periodMonthFor($instant));
        $this->assertSame('UTC', $instant->timezoneName, 'Fungsi periode tidak boleh mengubah zona waktu aslinya.');
    }

    public function test_finalize_stores_the_wib_period_and_keeps_finalized_at_in_utc(): void
    {
        $service = app(SixFactorEvaluationService::class);
        $umkm = Umkm::factory()->create();
        $owner = $umkm->user;

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-31 16:59:59', 'UTC'));
        $service->saveDraft($owner, $umkm->id, $this->factors());
        $october = $service->finalize($owner, $umkm->id);

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-31 17:00:00', 'UTC'));
        $service->saveDraft($owner, $umkm->id, $this->factors());
        $november = $service->finalize($owner, $umkm->id);

        $this->assertSame(['2026-10', '2026-11'], [$october->period_month, $november->period_month]);
        $this->assertDatabaseHas('six_factor_evaluations', [
            'id' => $october->id, 'period_month' => '2026-10', 'finalized_at' => '2026-10-31 16:59:59',
        ]);
        $this->assertDatabaseHas('six_factor_evaluations', [
            'id' => $november->id, 'period_month' => '2026-11', 'finalized_at' => '2026-10-31 17:00:00',
        ]);
    }

    public function test_saving_derives_the_period_from_finalized_at_not_from_the_clock(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-10 03:00:00', 'UTC'));
        $umkm = Umkm::factory()->create();

        $final = SixFactorEvaluation::factory()->for($umkm)->final()->create([
            'finalized_at' => CarbonImmutable::parse('2026-12-31 17:30:00', 'UTC'),
        ]);

        $this->assertSame('2027-01', $final->fresh()->period_month);
    }

    public function test_drafts_never_carry_a_period_even_when_one_is_assigned(): void
    {
        $umkm = Umkm::factory()->create();
        $draft = SixFactorEvaluation::factory()->for($umkm)->create();

        $draft->forceFill(['period_month' => '2026-11'])->save();

        $this->assertNull($draft->fresh()->period_month);
    }

    /** @return array<string, int> */
    private function factors(): array
    {
        return array_fill_keys(SixFactorEvaluation::FACTORS, 3);
    }
}
