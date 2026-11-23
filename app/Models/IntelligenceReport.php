<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntelligenceReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IntelligenceReport extends Model
{
    /** @use HasFactory<IntelligenceReportFactory> */
    use HasFactory;

    public const COMPLETE = 'complete';

    public const PARTIAL = 'partial';

    /** Kolom ringan untuk daftar histori; `report` sengaja tidak dimuat. */
    public const SUMMARY_COLUMNS = [
        'id', 'event_id', 'evaluation_id', 'umkm_id', 'source_event_version', 'schema_version',
        'prompt_version', 'knowledge_version', 'methodology_status', 'provider', 'model',
        'generated_at', 'received_at',
    ];

    public function umkm(): BelongsTo
    {
        return $this->belongsTo(Umkm::class);
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(SixFactorEvaluation::class, 'evaluation_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookOutbox::class, 'event_id', 'event_id');
    }

    /**
     * Dokumen laporan apa adanya; objek JSON kosong tetap objek.
     */
    public function document(): mixed
    {
        $json = $this->getRawOriginal('report') ?? $this->attributes['report'] ?? null;

        return is_string($json) ? json_decode($json, false, 64, JSON_THROW_ON_ERROR) : null;
    }

    public function delete(): never
    {
        throw new LogicException('Laporan intelligence tidak dapat dihapus.');
    }

    protected function casts(): array
    {
        return [
            'evaluation_id' => 'integer',
            'umkm_id' => 'integer',
            'source_event_version' => 'integer',
            'schema_version' => 'integer',
            'generated_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Laporan intelligence bersifat immutable.');
    }
}
