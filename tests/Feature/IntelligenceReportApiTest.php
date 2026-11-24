<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceReport;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntelligenceReportApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    /** @return array{Umkm, User} */
    private function ownedUmkm(): array
    {
        $umkm = Umkm::factory()->create();
        $this->actingAs($umkm->user, 'sanctum');

        return [$umkm, $umkm->user];
    }

    private function reportFor(Umkm $umkm, array $attributes = []): IntelligenceReport
    {
        $evaluation = SixFactorEvaluation::factory()->for($umkm)->final()->create();

        return IntelligenceReport::factory()->create(['evaluation_id' => $evaluation->id, ...$attributes]);
    }

    private function url(Umkm|int $umkm, string $suffix = ''): string
    {
        return '/api/umkms/'.($umkm instanceof Umkm ? $umkm->id : $umkm).'/intelligence-reports'.$suffix;
    }

    public function test_every_endpoint_requires_sanctum_authentication(): void
    {
        $report = IntelligenceReport::factory()->create();

        foreach (['', '/latest', '/'.$report->id] as $suffix) {
            $this->getJson($this->url($report->umkm_id, $suffix))->assertUnauthorized();
        }
    }

    public function test_foreign_and_missing_umkms_and_reports_are_indistinguishable_404s(): void
    {
        $foreign = IntelligenceReport::factory()->create();
        [$umkm] = $this->ownedUmkm();
        $mine = $this->reportFor($umkm);
        $sibling = Umkm::factory()->for($umkm->user)->create();

        $responses = [
            $this->getJson($this->url($foreign->umkm_id)),
            $this->getJson($this->url($foreign->umkm_id, '/latest')),
            $this->getJson($this->url($foreign->umkm_id, '/'.$foreign->id)),
            $this->getJson($this->url(999999, '/latest')),
            $this->getJson($this->url($umkm, '/'.$foreign->id)),
            $this->getJson($this->url($umkm, '/999999')),
            $this->getJson($this->url($sibling, '/'.$mine->id)),
            $this->getJson($this->url($umkm, '/abc')),
            $this->getJson('/api/umkms/abc/intelligence-reports/latest'),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound()->assertExactJson(['message' => 'Data tidak ditemukan.']);
        }
        $this->getJson($this->url($umkm, '/'.$mine->id))->assertOk();
    }

    public function test_latest_without_a_stored_report_is_awaiting_report_even_with_a_final_evaluation(): void
    {
        [$umkm] = $this->ownedUmkm();
        SixFactorEvaluation::factory()->for($umkm)->final()->create();

        $this->getJson($this->url($umkm, '/latest'))
            ->assertOk()->assertExactJson(['status' => 'awaiting_report', 'data' => null]);
    }

    public function test_latest_returns_the_newest_generated_report_with_id_as_the_tie_breaker(): void
    {
        [$umkm] = $this->ownedUmkm();
        $this->reportFor($umkm, ['generated_at' => '2026-11-23 07:00:00']);
        $newest = $this->reportFor($umkm, ['generated_at' => '2026-11-23 09:00:00']);
        $tieFirst = $this->reportFor($umkm, ['generated_at' => '2026-11-23 09:00:00']);
        $this->reportFor($umkm, ['generated_at' => '2026-11-22 23:00:00']);
        $other = $this->reportFor(Umkm::factory()->create(), ['generated_at' => '2026-11-24 00:00:00']);
        SixFactorEvaluation::factory()->for($umkm)->final()->create();

        $response = $this->getJson($this->url($umkm, '/latest'))->assertOk();

        $this->assertGreaterThan($newest->id, $tieFirst->id);
        $response->assertJsonPath('status', 'available')->assertJsonPath('data.id', $tieFirst->id);
        $this->assertNotSame($other->id, $response->json('data.id'));
    }

    public function test_latest_exposes_methodology_status_limitations_metadata_and_the_full_report(): void
    {
        [$umkm] = $this->ownedUmkm();
        $report = $this->reportFor($umkm, ['generated_at' => '2026-11-23 08:00:00']);
        $partial = IntelligenceReport::factory()->partial()->create([
            'evaluation_id' => SixFactorEvaluation::factory()->for($umkm)->final()->create()->id,
            'generated_at' => '2026-11-23 09:00:00',
            'report' => json_encode(['executive_summary' => 'Ringkas', 'perspectives' => new \stdClass, 'limitations' => ['Data terbatas.']]),
        ]);

        $this->getJson($this->url($umkm, '/latest'))->assertOk()
            ->assertJsonPath('data.id', $partial->id)
            ->assertJsonPath('data.methodology_status', 'partial')
            ->assertJsonPath('data.limitations', ['Data terbatas.'])
            ->assertJsonPath('data.evaluation_id', $partial->evaluation_id)
            ->assertJsonPath('data.umkm_id', $umkm->id)
            ->assertJsonPath('data.source_event_version', 2)
            ->assertJsonPath('data.generated_at', '2026-11-23T09:00:00.000000Z')
            ->assertJsonPath('data.report.executive_summary', 'Ringkas')
            ->assertJsonMissingPath('data.report_hash');

        $this->assertStringContainsString('"perspectives":{}', $this->getJson($this->url($umkm, '/latest'))->getContent());
        $this->assertNotSame($report->id, $partial->id);
    }

    public function test_history_is_ordered_paginated_summarized_and_scoped_to_the_umkm(): void
    {
        [$umkm] = $this->ownedUmkm();
        $ids = [];
        foreach (range(1, 12) as $minute) {
            $ids[] = $this->reportFor($umkm, ['generated_at' => now()->startOfDay()->addMinutes($minute)])->id;
        }
        $this->reportFor(Umkm::factory()->create());

        $first = $this->getJson($this->url($umkm))->assertOk();
        $first->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('data.0.id', $ids[11])->assertJsonPath('data.9.id', $ids[2]);
        $this->assertArrayNotHasKey('report', $first->json('data.0'));
        $this->assertArrayNotHasKey('limitations', $first->json('data.0'));
        $this->assertArrayHasKey('methodology_status', $first->json('data.0'));
        $this->assertSame([$ids[1], $ids[0]], array_column($this->getJson($this->url($umkm).'?page=2')->assertOk()->json('data'), 'id'));
        $this->getJson($this->url($umkm).'?per_page=5&page=3')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_history_without_reports_is_an_empty_list_with_consistent_pagination(): void
    {
        [$umkm] = $this->ownedUmkm();

        $this->getJson($this->url($umkm))->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.current_page', 1);
    }

    public function test_history_rejects_invalid_pagination_parameters(): void
    {
        [$umkm] = $this->ownedUmkm();

        $this->getJson($this->url($umkm).'?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($this->url($umkm).'?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($this->url($umkm).'?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson($this->url($umkm).'?per_page=50')->assertOk();
    }

    public function test_detail_returns_the_full_report_for_the_owner_only(): void
    {
        [$umkm] = $this->ownedUmkm();
        $report = $this->reportFor($umkm);

        $this->getJson($this->url($umkm, '/'.$report->id))->assertOk()
            ->assertJsonPath('data.id', $report->id)
            ->assertJsonPath('data.event_id', $report->event_id)
            ->assertJsonPath('data.report.top_priorities.0.id', 'P1')
            ->assertJsonPath('data.limitations', []);

        $token = User::factory()->create()->createToken('test')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson($this->url($umkm, '/'.$report->id))->assertNotFound();
    }

    public function test_reading_reports_never_mutates_data(): void
    {
        [$umkm] = $this->ownedUmkm();
        $report = $this->reportFor($umkm);
        $before = IntelligenceReport::query()->whereKey($report->id)->first()->getAttributes();

        $this->getJson($this->url($umkm, '/latest'))->assertOk();
        $this->getJson($this->url($umkm))->assertOk();
        $this->getJson($this->url($umkm, '/'.$report->id))->assertOk();

        $this->assertSame($before, IntelligenceReport::query()->whereKey($report->id)->first()->getAttributes());
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}($this->url($umkm, '/'.$report->id))->assertStatus(405);
        }
    }
}
