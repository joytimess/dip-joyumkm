<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntelligenceReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class IntelligenceReport extends Model
{
    /** @use HasFactory<IntelligenceReportFactory> */
    use HasFactory;

    public const COMPLETE = 'complete';

    public const PARTIAL = 'partial';

    public const ARTIFACT_PENDING = 'pending';

    public const ARTIFACT_COMPLETE = 'complete';

    public const ARTIFACT_PARTIAL = 'partial';

    /** Satu-satunya kolom yang boleh berubah setelah laporan tersimpan. */
    private const MUTABLE_COLUMNS = ['artifact_status'];

    /** Kolom ringan untuk daftar histori; `report` sengaja tidak dimuat. */
    public const SUMMARY_COLUMNS = [
        'id', 'event_id', 'evaluation_id', 'umkm_id', 'source_event_version', 'schema_version',
        'prompt_version', 'knowledge_version', 'methodology_status', 'artifact_status', 'provider', 'model',
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

    public function artifacts(): HasMany
    {
        return $this->hasMany(IntelligenceArtifact::class, 'report_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(IntelligenceDelivery::class, 'event_id', 'event_id');
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
        if (array_diff(array_keys($this->getDirty()), [...self::MUTABLE_COLUMNS, $this->getUpdatedAtColumn()]) !== []) {
            throw new LogicException('Laporan intelligence bersifat immutable.');
        }

        return parent::performUpdate($query);
    }
}
