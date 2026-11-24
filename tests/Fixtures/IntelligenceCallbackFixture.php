<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\SixFactorEvaluation;
use App\Models\WebhookOutbox;
use App\Services\HermesWebhookOutbox;
use Database\Factories\IntelligenceReportFactory;
use Illuminate\Support\Str;

/** Fixture lokal callback Hermes; tidak memanggil model AI maupun layanan eksternal. */
final class IntelligenceCallbackFixture
{
    public const SECRET = 'fixture-callback-secret-0123456789abcdef';

    public const PATH = '/api/internal/intelligence/callback';

    /**
     * Evaluasi final beserta event outbox versi 1 (bentuk baseline GitHub) atau versi 2 (produksi saat ini).
     *
     * @return array{evaluation: SixFactorEvaluation, event: WebhookOutbox}
     */
    public static function finalization(int $version = 2): array
    {
        $evaluation = SixFactorEvaluation::factory()->final()->create();

        if ($version === 2) {
            app(HermesWebhookOutbox::class)->recordFinalization($evaluation);

            return ['evaluation' => $evaluation, 'event' => WebhookOutbox::query()->where('aggregate_id', $evaluation->id)->sole()];
        }

        $metadata = ['event_id' => (string) Str::uuid(), 'event_type' => 'evaluation.finalized', 'event_version' => 1];
        $event = (new WebhookOutbox)->forceFill([
            ...$metadata,
            'aggregate_id' => $evaluation->id,
            'payload' => [
                ...$metadata,
                'source' => 'joyumkm',
                'occurred_at' => $evaluation->finalized_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'data' => [
                    'evaluation_id' => $evaluation->id,
                    'umkm_id' => $evaluation->umkm_id,
                    'status' => $evaluation->status,
                    'scores' => $evaluation->only(SixFactorEvaluation::FACTORS),
                    'total_score' => $evaluation->total_score,
                    'category' => $evaluation->category,
                ],
            ],
            'next_attempt_at' => now(),
        ]);
        $event->save();

        return ['evaluation' => $evaluation, 'event' => $event];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(SixFactorEvaluation $evaluation, WebhookOutbox $event, array $overrides = []): array
    {
        return [
            'event_id' => $event->event_id,
            'evaluation_id' => $evaluation->id,
            'umkm_id' => $evaluation->umkm_id,
            'status' => 'completed',
            'source_event_version' => $event->event_version,
            'schema_version' => 1,
            'prompt_version' => 'r1-prompt-5',
            'knowledge_version' => 'joyumkm-methodology-2026-11-22.1',
            'methodology_status' => 'complete',
            'provider' => 'claude-subscription-directsdk-experimental',
            'model' => 'claude-sonnet-5-5[1m]',
            'generated_at' => '2026-11-23T08:00:00Z',
            'report' => IntelligenceReportFactory::sampleReport(),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>|string  $body  Array di-encode ke JSON; string dipakai apa adanya.
     * @return array{headers: array<string, string>, body: string}
     */
    public static function signed(array|string $body, ?int $timestamp = null, ?string $secret = null): array
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->getTimestamp();

        return [
            'headers' => [
                'X-Intelligence-Timestamp' => (string) $timestamp,
                'X-Intelligence-Signature' => hash_hmac('sha256', $timestamp.'.'.$raw, $secret ?? self::SECRET),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => $raw,
        ];
    }

    /**
     * Bentuk header HTTP_* untuk TestCase::call().
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }
}
