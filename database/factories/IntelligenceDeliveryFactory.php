<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IntelligenceDelivery;
use App\Models\IntelligenceReport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IntelligenceDelivery> */
class IntelligenceDeliveryFactory extends Factory
{
    /**
     * Baris pengiriman kanal artefak untuk laporan sungguhan.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => fn (): string => IntelligenceReport::factory()->create()->event_id,
            'channel' => IntelligenceDelivery::CHANNEL_ARTIFACT,
            'recipient_fingerprint' => '',
            'artifact_set_hash' => fn (): string => hash('sha256', Str::random(32)),
            'idempotency_key' => fn (array $attributes): string => IntelligenceDelivery::idempotencyKeyFor(
                $attributes['event_id'], $attributes['channel'], $attributes['recipient_fingerprint'], $attributes['artifact_set_hash'],
            ),
            'status' => IntelligenceDelivery::QUEUED,
            'attempt_no' => 0,
            'provider_message_id' => null,
            'error_code' => null,
            'sent_at' => null,
        ];
    }

    /**
     * Baris pengiriman email untuk satu penerima tanpa alamat mentah; fingerprint diisi pemanggil.
     */
    public function email(string $recipientFingerprint): static
    {
        return $this->state(fn (): array => [
            'channel' => IntelligenceDelivery::CHANNEL_EMAIL,
            'recipient_fingerprint' => $recipientFingerprint,
            'idempotency_key' => fn (array $attributes): string => IntelligenceDelivery::idempotencyKeyFor(
                $attributes['event_id'], IntelligenceDelivery::CHANNEL_EMAIL, $recipientFingerprint, $attributes['artifact_set_hash'],
            ),
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => IntelligenceDelivery::SENT,
            'attempt_no' => 1,
            'provider_message_id' => 'msg_'.Str::random(12),
            'sent_at' => now()->startOfSecond(),
        ]);
    }
}
