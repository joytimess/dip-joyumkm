<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SixFactorMonitoringApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'snapshot-test-secret-0123456789abcdef';

    private const PATH = '/api/internal/monitoring/six-factor/latest-finals';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        config([
            'services.hermes_intelligence_snapshot.secret' => self::SECRET,
            'services.hermes_intelligence_snapshot.tolerance_seconds' => 300,
        ]);
    }

    private function createFinal(Umkm $umkm, string $finalizedAtUtc, int $total): SixFactorEvaluation
    {
        // Evaluasi Final tidak dapat diubah setelah disimpan dan total harus sama dengan jumlah enam faktor.
        $base = intdiv($total, count(SixFactorEvaluation::FACTORS));
        $remainder = $total % count(SixFactorEvaluation::FACTORS);
        $factors = [];
        foreach (SixFactorEvaluation::FACTORS as $index => $factor) {
            $factors[$factor] = $base + ($index < $remainder ? 1 : 0);
        }

        return SixFactorEvaluation::factory()->for($umkm)->final()->create([
            ...$factors,
            'total_score' => $total,
            'category' => match (true) {
                $total <= 12 => 'Kurang Sehat',
                $total <= 18 => 'Cukup Sehat',
                $total <= 24 => 'Sehat',
                default => 'Sangat Sehat',
            },
            'finalized_at' => CarbonImmutable::parse($finalizedAtUtc, 'UTC'),
        ]);
    }

    /** @param  array<string, string>  $overrides */
    private function signedGet(?int $timestamp = null, ?string $secret = self::SECRET, array $overrides = []): TestResponse
    {
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp."\nGET\n".self::PATH, (string) $secret);

        return $this->get(self::PATH, array_merge([
            'Accept' => 'application/json',
            'X-Intelligence-Snapshot-Timestamp' => (string) $timestamp,
            'X-Intelligence-Snapshot-Signature' => $signature,
        ], $overrides));
    }

    public function test_signed_pull_returns_latest_final_total_per_umkm_in_id_order(): void
    {
        $first = Umkm::factory()->create([
            'name' => 'UMKM Demo',
            'owner_name' => 'Pemilik Rahasia',
            'business_email' => 'pemilik-rahasia@example.test',
            'whatsapp_number' => '081200000000',
        ]);
        $this->createFinal($first, '2026-10-05 02:00:00', 9);
        $latest = $this->createFinal($first, '2026-11-10 08:00:00', 10);

        $second = Umkm::factory()->create(['name' => 'UMKM Tanpa Evaluasi']);
        SixFactorEvaluation::factory()->for($second)->draft()->create();

        $response = $this->signedGet()->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.umkm_id', $first->id);
        $response->assertJsonPath('data.0.nama_umkm', 'UMKM Demo');
        $response->assertJsonPath('data.0.evaluation_id', $latest->id);
        $response->assertJsonPath('data.0.total_skor', 10);
        $response->assertJsonPath('data.0.finalized_at', '2026-11-10T08:00:00+00:00');
        $response->assertJsonPath('data.0.server_active', true);
        $response->assertJsonPath('data.1.umkm_id', $second->id);
        $response->assertJsonPath('data.1.evaluation_id', null);
        $response->assertJsonPath('data.1.total_skor', null);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.truncated', false);
        $this->assertStringNotContainsString('pemilik-rahasia@example.test', (string) $response->getContent());
        $this->assertStringNotContainsString('081200000000', (string) $response->getContent());
        $this->assertStringNotContainsString('Pemilik Rahasia', (string) $response->getContent());
        $response->assertJsonMissingPath('data.0.business_email');
        $response->assertJsonMissingPath('data.0.whatsapp_number');
    }

    public function test_draft_and_cancelled_evaluations_are_never_reported_as_scores(): void
    {
        $umkm = Umkm::factory()->create();
        SixFactorEvaluation::factory()->for($umkm)->draft()->create();
        SixFactorEvaluation::factory()->for($umkm)->cancelled()->create();

        $this->signedGet()->assertOk()
            ->assertJsonPath('data.0.evaluation_id', null)
            ->assertJsonPath('data.0.total_skor', null);
    }

    public function test_request_without_signature_headers_is_rejected(): void
    {
        $this->get(self::PATH, ['Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthorized');
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->signedGet(secret: 'secret-yang-salah-0123456789abcdef0123')->assertStatus(401);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $this->signedGet(timestamp: now()->getTimestamp() - 3600)->assertStatus(401);
    }

    public function test_signature_for_another_path_is_rejected(): void
    {
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp."\nGET\n/api/internal/intelligence/events/x/snapshot", self::SECRET);

        $this->get(self::PATH, [
            'Accept' => 'application/json',
            'X-Intelligence-Snapshot-Timestamp' => (string) $timestamp,
            'X-Intelligence-Snapshot-Signature' => $signature,
        ])->assertStatus(401);
    }

    public function test_empty_umkm_list_returns_empty_data(): void
    {
        $this->signedGet()->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.count', 0)
            ->assertJsonPath('meta.truncated', false);
    }
}
