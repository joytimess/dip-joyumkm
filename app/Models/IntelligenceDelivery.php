<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntelligenceDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id', 'channel', 'recipient_fingerprint', 'artifact_set_hash', 'idempotency_key',
    'status', 'attempt_no', 'provider_message_id', 'error_code', 'sent_at',
])]
class IntelligenceDelivery extends Model
{
    /** @use HasFactory<IntelligenceDeliveryFactory> */
    use HasFactory;

    public const CHANNEL_ARTIFACT = 'artifact';

    public const CHANNEL_EMAIL = 'email';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED_RETRYABLE = 'failed_retryable';

    public const FAILED_PERMANENT = 'failed_permanent';

    public const SKIPPED_INELIGIBLE = 'skipped_ineligible';

    /**
     * Kunci idempotensi berupa SHA-256 atas identitas pengiriman. Tidak memuat alamat email mentah.
     */
    public static function idempotencyKeyFor(string $eventId, string $channel, string $recipientFingerprint, string $artifactSetHash): string
    {
        return hash('sha256', implode('|', [
            'intelligence', strtolower($eventId), $channel, $recipientFingerprint, $artifactSetHash,
        ]));
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(IntelligenceReport::class, 'event_id', 'event_id');
    }

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'sent_at' => 'datetime',
            'email_snapshot' => 'array',
        ];
    }
}
