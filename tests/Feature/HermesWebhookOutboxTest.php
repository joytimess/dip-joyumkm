<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverHermesWebhook;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\WebhookOutbox;
use App\Services\HermesWebhookOutbox;
use App\Services\HermesWebhookSender;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Fixtures\HermesWebhookReceiver;
use Tests\TestCase;

class HermesWebhookOutboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'https://receiver.example.test/joyumkm-events';

    public function test_only_official_finalization_creates_the_minimal_server_snapshot(): void
    {
        $this->freezeSecond();
        Http::preventStrayRequests();
        $umkm = Umkm::factory()->create();
        $this->actingAs($umkm->user, 'sanctum');
        $base = '/api/umkms/'.$umkm->id.'/six-factor-evaluations';
        $this->getJson($base)->assertOk();
        $this->getJson($base.'/latest')->assertOk();
        $id = $this->putJson($base.'/draft', ['scores' => ['kinerja' => 4]])->assertCreated()->json('data.id');
        $this->postJson($base.'/'.$id.'/finalize', options: JSON_FORCE_OBJECT)->assertUnprocessable();
        $this->postJson($base.'/'.$id.'/cancel', options: JSON_FORCE_OBJECT)->assertOk();
        $this->assertDatabaseEmpty('webhook_outbox');

        $id = $this->putJson($base.'/draft', ['scores' => array_fill_keys(SixFactorEvaluation::FACTORS, 4)])
            ->assertCreated()->json('data.id');
        $final = $this->postJson($base.'/'.$id.'/finalize', options: JSON_FORCE_OBJECT)->assertOk()->json('data');
        $this->postJson($base.'/'.$id.'/finalize', options: JSON_FORCE_OBJECT)->assertConflict();
        $this->getJson($base.'/latest')->assertOk()->assertExactJson(['data' => $final]);
        $this->getJson($base)->assertOk()->assertExactJson(['data' => [$final]]);

        $this->assertDatabaseCount('webhook_outbox', 1);
        $event = DB::table('webhook_outbox')->sole();
        $this->assertTrue(Str::isUuid($event->event_id));
        $this->assertSame('pending', $event->status);
        $this->assertSame('evaluation.finalized', $event->event_type);
        $this->assertSame($id, $event->aggregate_id);
        $this->assertSame(1, $event->event_version);
        $this->assertEquals([
            'event_id' => $event->event_id,
            'event_type' => 'evaluation.finalized',
            'event_version' => 1,
            'source' => 'joyumkm',
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                'evaluation_id' => $id,
                'umkm_id' => $umkm->id,
                'status' => 'final',
                'scores' => array_fill_keys(SixFactorEvaluation::FACTORS, 4),
                'total_score' => 24,
                'category' => 'Sehat',
            ],
        ], json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseEmpty('jobs');
        Http::assertNothingSent();
    }

    public function test_unique_constraint_failure_rolls_back_the_evaluation_update(): void
    {
        $draft = $this->completeDraft();
        DB::table('webhook_outbox')->insert([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'evaluation.finalized',
            'event_version' => 1,
            'aggregate_id' => $draft->id,
            'payload' => '{}',
            'next_attempt_at' => now(),
        ]);
        Log::spy();

        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertServerError();

        Log::shouldHaveReceived('error')->with('Event finalisasi gagal disimpan.', Mockery::on(
            fn (array $context): bool => $context['exception'] instanceof RuntimeException
                && $context['exception']->getPrevious() === null,
        ))->once();
        $this->assertDatabaseHas('six_factor_evaluations', [
            'id' => $draft->id, 'status' => 'draft', 'finalized_at' => null, 'total_score' => null,
        ]);
        $this->assertDatabaseCount('webhook_outbox', 1);
        $this->assertDatabaseEmpty('jobs');
    }

    public function test_outer_rollback_discards_both_the_transition_and_the_after_commit_dispatch(): void
    {
        $draft = $this->completeDraft();
        $this->configureSender();
        DB::beginTransaction();
        try {
            $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk();
            $this->assertDatabaseHas('webhook_outbox', ['aggregate_id' => $draft->id, 'status' => 'pending']);
            $this->assertDatabaseEmpty('jobs');
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('six_factor_evaluations', ['id' => $draft->id, 'status' => 'draft']);
        $this->assertDatabaseEmpty('webhook_outbox');
        $this->assertDatabaseEmpty('jobs');
    }

    public function test_dispatch_failure_preserves_final_and_scanner_enqueues_once_after_recovery(): void
    {
        $draft = $this->completeDraft();
        $this->configureSender();
        config(['queue.connections.database.table' => 'missing_webhook_queue_test']);
        Log::spy();
        Http::preventStrayRequests();

        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk()->assertJsonPath('data.status', 'final');
        $this->assertDatabaseHas('webhook_outbox', ['aggregate_id' => $draft->id, 'status' => 'pending', 'attempts' => 0]);
        Log::shouldHaveReceived('warning')->with('Hermes webhook queue dispatch failed.', ['outbox_id' => DB::table('webhook_outbox')->value('id')])->once();
        $this->assertDatabaseEmpty('jobs');

        config(['queue.connections.database.table' => 'jobs']);
        app()->forgetInstance('queue');
        $this->artisan('hermes:webhook-scan')->assertSuccessful();
        $this->artisan('hermes:webhook-scan')->assertSuccessful();
        $this->assertDatabaseHas('jobs', ['queue' => 'hermes-webhooks']);
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    #[TestWith([408])]
    #[TestWith([429])]
    #[TestWith([500])]
    #[TestWith([503])]
    public function test_retryable_statuses_use_bounded_backoff_and_stop_at_the_limit(int $status): void
    {
        $this->freezeSecond();
        $draft = $this->completeDraft();
        $this->configureSender();
        config(['services.hermes_webhook.max_attempts' => 3, 'services.hermes_webhook.backoff_cap' => 100]);
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response('response is private', $status)]);
        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk();

        foreach ([[30, 36], [60, 72]] as $index => [$min, $max]) {
            $this->runWorker();
            $event = WebhookOutbox::query()->sole();
            $this->assertSame($index + 1, $event->attempts);
            $this->assertSame('retry_wait', $event->status);
            $this->assertSame($status, $event->last_http_status);
            $this->assertSame('http_retryable', $event->last_error_code);
            $this->assertStringNotContainsString('response is private', $event->last_error_summary);
            $this->assertTrue($event->next_attempt_at->betweenIncluded(now()->addSeconds($min), now()->addSeconds($max)));
            $this->artisan('hermes:webhook-scan')->expectsOutput('Job baru 0, dispatch gagal 0.')->assertSuccessful();
            $this->travelTo($event->next_attempt_at);
            $this->artisan('hermes:webhook-scan')->expectsOutput('Job baru 1, dispatch gagal 0.')->assertSuccessful();
        }

        $this->runWorker();
        $this->assertDatabaseHas('webhook_outbox', [
            'status' => 'dead', 'attempts' => 3, 'next_attempt_at' => null, 'lease_token' => null,
        ]);
        $this->assertDatabaseHas('six_factor_evaluations', ['id' => $draft->id, 'status' => 'final']);
        $this->travel(1)->day();
        $this->artisan('hermes:webhook-scan')->expectsOutput('Job baru 0, dispatch gagal 0.')->assertSuccessful();
        Http::assertSentCount(3);
    }

    public function test_timeout_and_retry_after_keep_payload_and_identity_stable_until_ack(): void
    {
        $this->freezeSecond();
        $draft = $this->completeDraft();
        $this->configureSender();
        config(['services.hermes_webhook.backoff_cap' => 120]);
        $retryAt = now()->addSeconds(115)->utc()->format(DATE_RFC7231);
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake([self::URL => function (Request $request) use (&$calls, $retryAt) {
            return match (++$calls) {
                1 => Http::failedConnection('private transport details')($request),
                2 => Http::response('', 503, ['Retry-After' => $retryAt]),
                3 => Http::response('', 429, ['Retry-After' => '99999']),
                default => $this->acceptedAck($request),
            };
        }]);
        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk();
        $event = WebhookOutbox::query()->sole();

        $this->runWorker();
        $event->refresh();
        $this->assertSame('network_error', $event->last_error_code);
        $this->assertStringNotContainsString('private transport details', $event->last_error_summary);
        $this->assertNull($event->last_http_status);
        $this->travelTo($event->next_attempt_at);

        $this->artisan('hermes:webhook-scan')->assertSuccessful();
        $this->runWorker();
        $this->assertSame($retryAt, $event->refresh()->next_attempt_at->utc()->format(DATE_RFC7231));
        $this->travelTo($event->next_attempt_at);

        $this->artisan('hermes:webhook-scan')->assertSuccessful();
        $this->runWorker();
        $this->assertTrue($event->refresh()->next_attempt_at->equalTo(now()->addSeconds(120)));
        $this->travelTo($event->next_attempt_at);

        $this->artisan('hermes:webhook-scan')->assertSuccessful();
        $this->runWorker();
        $event->refresh();
        $this->assertSame('delivered', $event->status);
        $this->assertSame(4, $event->attempts);
        $this->assertSame(202, $event->last_http_status);
        $this->assertNull($event->last_error_code);
        $this->assertTrue($event->delivered_at->equalTo(now()));
        Http::assertSentCount(4);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($event->getRawOriginal('payload'), $request->body());
            $this->assertSame([$event->event_id], $request->header('X-Request-ID'));
            foreach (['X-JoyUMKM-Signature', 'webhook-signature', 'svix-signature', 'X-Hub-Signature-256'] as $legacy) {
                $this->assertFalse($request->hasHeader($legacy));
            }
            $timestamp = $request->header('X-Webhook-Timestamp')[0];
            $signature = $request->header('X-Webhook-Signature-V2')[0];
            $this->assertSame(hash_hmac('sha256', $timestamp.'.'.$request->body(), HermesWebhookReceiver::SECRET), $signature);
            $this->assertTrue(HermesWebhookReceiver::accepts(
                $request->body(), $event->event_id, $timestamp, $signature, (int) $timestamp,
            ));
        }
    }

    #[TestWith([302])]
    #[TestWith([400])]
    #[TestWith([401])]
    #[TestWith([403])]
    #[TestWith([404])]
    #[TestWith([422])]
    public function test_permanent_rejections_require_operator_action_and_requeue_same_event(int $status): void
    {
        $draft = $this->completeDraft();
        $this->configureSender();
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake([self::URL => function (Request $request) use (&$calls, $status) {
            return ++$calls === 1
                ? Http::response('', $status, ['Location' => 'https://other.example.test/'])
                : $this->acceptedAck($request);
        }]);
        $final = $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk()->json('data');
        $this->runWorker();
        $event = WebhookOutbox::query()->sole();
        $body = $event->getRawOriginal('payload');
        $this->assertSame('dead', $event->status);
        $this->assertSame($status, $event->last_http_status);
        $this->assertSame('http_rejected', $event->last_error_code);
        $this->assertNull($event->next_attempt_at);
        $this->artisan('hermes:webhook-scan')->expectsOutput('Job baru 0, dispatch gagal 0.')->assertSuccessful();
        $this->artisan('hermes:webhook-requeue', ['event_id' => $event->event_id])->assertSuccessful();
        $this->artisan('hermes:webhook-requeue', ['event_id' => $event->event_id])->assertFailed();
        $this->assertSame(0, $event->refresh()->attempts);
        $this->assertDatabaseCount('jobs', 1);

        $this->runWorker();
        $this->assertSame('delivered', $event->refresh()->status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame($body, $event->getRawOriginal('payload'));
        $this->assertDatabaseCount('webhook_outbox', 1);
        $this->artisan('hermes:webhook-requeue', ['event_id' => $event->event_id])->assertFailed();
        $this->getJson('/api/umkms/'.$draft->umkm_id.'/six-factor-evaluations/latest')->assertExactJson(['data' => $final]);
        Http::assertSentCount(2);
    }

    #[TestWith(['http://receiver.example.test/joyumkm-events', 'receiver.example.test', 'invalid_destination'])]
    #[TestWith(['http://127.0.0.1:8380/joyumkm-events', '127.0.0.1', 'invalid_destination'])]
    #[TestWith(['https://127.0.0.1/joyumkm-events', '127.0.0.1', 'invalid_destination'])]
    #[TestWith(['https://receiver.example.test/joyumkm-events', 'other.example.test', 'invalid_destination'])]
    #[TestWith(['https://private-user@receiver.example.test/joyumkm-events', 'receiver.example.test', 'invalid_destination'])]
    #[TestWith(['https://receiver.example.test/joyumkm-events?secret=private', 'receiver.example.test', 'invalid_destination'])]
    #[TestWith(['', 'receiver.example.test', 'missing_config'])]
    public function test_invalid_destination_or_missing_config_preserves_backlog_with_safe_status(string $url, string $host, string $code): void
    {
        $draft = $this->completeDraft();
        $this->configureSender();
        $this->app->instance('env', 'production');
        config(['services.hermes_webhook.url' => $url, 'services.hermes_webhook.allowed_host' => $host]);
        Http::preventStrayRequests();
        Log::spy();

        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk();
        $event = WebhookOutbox::query()->sole();
        app(HermesWebhookOutbox::class)->deliver($event->id);
        $this->artisan('hermes:webhook-scan')->expectsOutput('Pengiriman webhook ditunda ('.$code.').')->assertFailed();
        $this->artisan('hermes:webhook-status', ['event_id' => $event->event_id])
            ->expectsOutput('Konfigurasi pengiriman: '.$code)->assertSuccessful();
        Log::shouldHaveReceived('warning')->with('Hermes webhook delivery paused.', ['code' => $code, 'outbox_id' => $event->id])->once();
        Log::shouldHaveReceived('warning')->with('Hermes webhook delivery paused.', ['code' => $code])->once();
        $this->assertDatabaseEmpty('jobs');
        $this->assertSame('pending', $event->refresh()->status);
        $this->assertSame(0, $event->attempts);
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_expired_worker_cannot_store_an_outcome(bool $replace): void
    {
        $this->freezeSecond();
        $draft = $this->completeDraft();
        $this->configureSender();
        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk();
        $event = WebhookOutbox::query()->sole();
        Http::preventStrayRequests();
        Http::fake([self::URL => function (Request $request) use ($event, $replace) {
            if ($event->fresh()->attempts === 2) {
                return $this->acceptedAck($request);
            }

            $this->travel(46)->seconds();
            if ($replace) {
                $this->artisan('hermes:webhook-scan')->expectsOutput('Job baru 1, dispatch gagal 0.')->assertSuccessful();
                app(HermesWebhookOutbox::class)->deliver($event->id);
            }

            return Http::response('', 401);
        }]);

        $this->runWorker();
        $this->assertSame($replace ? 'delivered' : 'processing', $event->refresh()->status);
        $this->assertSame($replace ? 2 : 1, $event->attempts);
        $this->assertSame($replace ? 202 : null, $event->last_http_status);
        Http::assertSentCount($replace ? 2 : 1);
    }

    public function test_expired_last_attempt_is_dead_without_another_http_request(): void
    {
        $draft = $this->completeDraft();
        $this->configureSender();
        config(['services.hermes_webhook.max_attempts' => 1]);
        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk();
        $event = WebhookOutbox::query()->sole();
        DB::table('webhook_outbox')->where('id', $event->id)->update([
            'status' => 'processing', 'attempts' => 1, 'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subSecond(), 'next_attempt_at' => null,
        ]);
        Http::preventStrayRequests();

        $this->runWorker();
        $this->assertDatabaseHas('webhook_outbox', [
            'id' => $event->id, 'status' => 'dead', 'attempts' => 1,
            'lease_token' => null, 'last_error_code' => 'attempt_limit',
        ]);
        Http::assertNothingSent();
    }

    public function test_duplicate_ack_marks_the_same_event_delivered_without_creating_another(): void
    {
        $draft = $this->completeDraft();
        $this->configureSender();
        Http::preventStrayRequests();
        Http::fake([self::URL => fn (Request $request) => Http::response([
            'status' => 'duplicate', 'delivery_id' => $request->header('X-Request-ID')[0],
        ], 200)]);
        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk();

        $this->runWorker();
        $event = WebhookOutbox::query()->sole();
        $this->assertSame('delivered', $event->status);
        $this->assertSame(200, $event->last_http_status);
        $this->assertNull($event->last_error_code);
        $this->assertNotNull($event->delivered_at);
        Http::assertSentCount(1);
    }

    #[TestWith([200, ['status' => 'ignored', 'reason' => 'filter'], 'ack_ignored'])]
    #[TestWith([200, ['status' => 'ignored', 'event' => 'evaluation.finalized'], 'ack_ignored'])]
    #[TestWith([202, ['status' => 'coalesced', 'route' => 'joyumkm-events', 'delivery_id' => '{id}'], 'invalid_ack'])]
    #[TestWith([202, ['status' => 'accepted', 'route' => 'other-route', 'event' => 'evaluation.finalized', 'delivery_id' => '{id}'], 'invalid_ack'])]
    #[TestWith([202, ['status' => 'accepted', 'route' => 'joyumkm-events', 'event' => 'umkm.health.updated', 'delivery_id' => '{id}'], 'invalid_ack'])]
    #[TestWith([202, ['status' => 'accepted', 'route' => 'joyumkm-events', 'event' => 'evaluation.finalized', 'delivery_id' => 'other-id'], 'invalid_ack'])]
    #[TestWith([202, ['status' => 'accepted', 'route' => 'joyumkm-events', 'event' => 'evaluation.finalized', 'delivery_id' => 123], 'invalid_ack'])]
    #[TestWith([202, ['status' => 'accepted', 'route' => 'joyumkm-events', 'event' => 'evaluation.finalized'], 'invalid_ack'])]
    #[TestWith([202, ['status' => 'accepted', 'route' => ['joyumkm-events'], 'event' => 'evaluation.finalized', 'delivery_id' => '{id}'], 'invalid_ack'])]
    #[TestWith([200, ['status' => 'accepted', 'route' => 'joyumkm-events', 'event' => 'evaluation.finalized', 'delivery_id' => '{id}'], 'invalid_ack'])]
    #[TestWith([200, ['status' => 'duplicate', 'delivery_id' => 'other-id'], 'invalid_ack'])]
    #[TestWith([200, ['status' => 'duplicate'], 'invalid_ack'])]
    #[TestWith([202, ['status' => 'duplicate', 'delivery_id' => '{id}'], 'invalid_ack'])]
    #[TestWith([200, ['status' => 'unknown', 'delivery_id' => '{id}'], 'invalid_ack'])]
    #[TestWith([200, ['status' => ['duplicate'], 'delivery_id' => '{id}'], 'invalid_ack'])]
    #[TestWith([202, ['accepted'], 'invalid_ack'])]
    #[TestWith([202, '{"status":"accepted"', 'invalid_ack'])]
    #[TestWith([202, '', 'invalid_ack'])]
    #[TestWith([200, 'null', 'invalid_ack'])]
    #[TestWith([204, '', 'invalid_ack'])]
    #[TestWith([201, ['status' => 'accepted'], 'invalid_ack'])]
    public function test_non_conforming_ack_is_never_delivered_and_is_not_retried(int $status, array|string $ack, string $code): void
    {
        $draft = $this->completeDraft();
        $this->configureSender();
        Http::preventStrayRequests();
        Http::fake([self::URL => function (Request $request) use ($status, $ack) {
            $body = is_array($ack) ? json_encode($ack) : $ack;

            return Http::response(str_replace('{id}', $request->header('X-Request-ID')[0], $body), $status);
        }]);
        $this->postJson($this->finalizeUrl($draft), options: JSON_FORCE_OBJECT)->assertOk();

        $this->runWorker();
        $event = WebhookOutbox::query()->sole();
        $this->assertSame('dead', $event->status);
        $this->assertSame($code, $event->last_error_code);
        $this->assertSame($status, $event->last_http_status);
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->delivered_at);
        $this->assertNull($event->next_attempt_at);
        $this->assertStringNotContainsString($event->event_id, (string) $event->last_error_summary);
        $this->artisan('hermes:webhook-scan')->expectsOutput('Job baru 0, dispatch gagal 0.')->assertSuccessful();
        Http::assertSentCount(1);
    }

    #[TestWith(['local'])]
    #[TestWith(['testing'])]
    public function test_loopback_http_receiver_is_allowed_only_in_local_and_testing(string $environment): void
    {
        $this->configureSender();
        $this->app->instance('env', $environment);
        config([
            'services.hermes_webhook.url' => 'http://127.0.0.1:8644/webhooks/joyumkm-events',
            'services.hermes_webhook.allowed_host' => '127.0.0.1',
        ]);

        $this->assertNull(app(HermesWebhookSender::class)->configurationIssue());
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_loopback_http_receiver_is_rejected_outside_local_and_testing(string $environment): void
    {
        $this->configureSender();
        $this->app->instance('env', $environment);
        config([
            'services.hermes_webhook.url' => 'http://127.0.0.1:8644/webhooks/joyumkm-events',
            'services.hermes_webhook.allowed_host' => '127.0.0.1',
        ]);

        $this->assertSame('invalid_destination', app(HermesWebhookSender::class)->configurationIssue());
    }

    #[TestWith(['http://localhost:8644/webhooks/joyumkm-events', 'localhost'])]
    #[TestWith(['http://0.0.0.0:8644/webhooks/joyumkm-events', '0.0.0.0'])]
    #[TestWith(['http://[::1]:8644/webhooks/joyumkm-events', '[::1]'])]
    #[TestWith(['http://172.17.0.2:8644/webhooks/joyumkm-events', '172.17.0.2'])]
    #[TestWith(['http://10.0.0.5/webhooks/joyumkm-events', '10.0.0.5'])]
    #[TestWith(['http://hermes.localhost/webhooks/joyumkm-events', 'hermes.localhost'])]
    #[TestWith(['http://127.0.0.1:8644/webhooks/joyumkm-events', 'localhost'])]
    #[TestWith(['http://127.0.0.1:8644/webhooks/joyumkm-events?x=1', '127.0.0.1'])]
    #[TestWith(['http://user@127.0.0.1:8644/webhooks/joyumkm-events', '127.0.0.1'])]
    #[TestWith(['http://127.0.0.1:8644/webhooks/joyumkm-events#frag', '127.0.0.1'])]
    #[TestWith(['http://127.0.0.1:8644\\webhooks', '127.0.0.1'])]
    #[TestWith(['ftp://127.0.0.1/webhooks/joyumkm-events', '127.0.0.1'])]
    public function test_local_environment_does_not_widen_the_destination_guard(string $url, string $host): void
    {
        $this->configureSender();
        $this->app->instance('env', 'local');
        config(['services.hermes_webhook.url' => $url, 'services.hermes_webhook.allowed_host' => $host]);

        $this->assertSame('invalid_destination', app(HermesWebhookSender::class)->configurationIssue());
    }

    public function test_production_accepts_https_fqdn_destination(): void
    {
        $this->configureSender();
        $this->app->instance('env', 'production');

        $this->assertNull(app(HermesWebhookSender::class)->configurationIssue());
    }

    private function acceptedAck(Request $request): PromiseInterface|Response
    {
        return Http::response([
            'status' => 'accepted',
            'route' => 'joyumkm-events',
            'event' => 'evaluation.finalized',
            'delivery_id' => $request->header('X-Request-ID')[0],
        ], 202);
    }

    private function runWorker(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => DeliverHermesWebhook::QUEUE, '--once' => true])
            ->assertSuccessful();
    }

    private function completeDraft(): SixFactorEvaluation
    {
        $draft = SixFactorEvaluation::factory()->create(array_fill_keys(SixFactorEvaluation::FACTORS, 3));
        $this->actingAs($draft->umkm->user, 'sanctum');

        return $draft;
    }

    private function finalizeUrl(SixFactorEvaluation $draft): string
    {
        return '/api/umkms/'.$draft->umkm_id.'/six-factor-evaluations/'.$draft->id.'/finalize';
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
}
