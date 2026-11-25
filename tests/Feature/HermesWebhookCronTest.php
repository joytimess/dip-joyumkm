<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SixFactorEvaluation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\HermesWebhookReceiver;
use Tests\TestCase;

class HermesWebhookCronTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'https://receiver.example.test/joyumkm-events';

    public function test_disabled_destination_leaves_outbox_and_other_queues_untouched(): void
    {
        config(['services.hermes_webhook.enabled' => false]);
        Http::preventStrayRequests();
        $this->finalizeEvaluation();
        DB::table('jobs')->insert($this->job('default'));

        $this->artisan('hermes:cron', ['--budget' => 60])
            ->expectsOutput('Pengiriman webhook ditunda (disabled).')
            ->assertSuccessful();

        $this->assertDatabaseHas('webhook_outbox', ['status' => 'pending', 'attempts' => 0, 'queued_job_id' => null]);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
        Http::assertNothingSent();
    }

    public function test_incomplete_destination_configuration_fails_without_dispatch(): void
    {
        Http::preventStrayRequests();
        $this->finalizeEvaluation();
        config([
            'services.hermes_webhook.enabled' => true,
            'services.hermes_webhook.url' => null,
            'services.hermes_webhook.allowed_host' => null,
            'services.hermes_webhook.secret' => null,
        ]);

        $this->artisan('hermes:cron', ['--budget' => 60])
            ->expectsOutput('Pengiriman webhook ditunda (missing_config).')
            ->assertFailed();

        $this->assertDatabaseHas('webhook_outbox', ['status' => 'pending', 'queued_job_id' => null]);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_overlapping_cycle_is_skipped_while_another_cycle_holds_the_lock(): void
    {
        Http::preventStrayRequests();
        $this->finalizeEvaluation();
        $this->configureSender();

        $lock = fopen(storage_path('app/hermes-webhook-cron.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->artisan('hermes:cron', ['--budget' => 60])
                ->expectsOutput('Siklus Hermes sebelumnya masih berjalan. Siklus ini dilewati.')
                ->assertSuccessful();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertDatabaseHas('webhook_outbox', ['status' => 'pending', 'queued_job_id' => null]);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_cycle_delivers_hermes_events_and_leaves_other_queues_alone(): void
    {
        Http::fake([self::URL => fn (Request $request) => Http::response([
            'status' => 'accepted',
            'route' => 'joyumkm-events',
            'event' => 'evaluation.finalized',
            'delivery_id' => $request->header('X-Request-ID')[0],
        ], 202)]);
        $this->finalizeEvaluation();
        $this->configureSender();
        DB::table('jobs')->insert($this->job('default'));

        $this->artisan('hermes:cron', ['--budget' => 60])
            ->expectsOutput('Job baru 1, dispatch gagal 0.')
            ->assertSuccessful();

        $this->assertDatabaseHas('webhook_outbox', ['status' => 'delivered', 'attempts' => 1]);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
        Http::assertSentCount(1);
    }

    private function configureSender(): void
    {
        config(['services.hermes_webhook' => [
            'enabled' => true,
            'url' => self::URL,
            'allowed_host' => 'receiver.example.test',
            'secret' => HermesWebhookReceiver::SECRET,
        ]]);
    }

    /** Finalisasi dilakukan saat tujuan nonaktif agar antrean hanya diisi oleh siklus cron yang diuji. */
    private function finalizeEvaluation(): void
    {
        config(['services.hermes_webhook.enabled' => false]);
        $draft = SixFactorEvaluation::factory()->create(array_fill_keys(SixFactorEvaluation::FACTORS, 3));
        $this->actingAs($draft->umkm->user, 'sanctum');

        $this->postJson(
            '/api/umkms/'.$draft->umkm_id.'/six-factor-evaluations/'.$draft->id.'/finalize',
            options: JSON_FORCE_OBJECT,
        )->assertOk();
    }

    /** @return array<string, mixed> */
    private function job(string $queue): array
    {
        return [
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'Fixture', 'job' => 'Fixture', 'data' => []], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ];
    }
}
