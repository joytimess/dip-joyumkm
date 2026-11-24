<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntelligenceArtifactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IntelligenceArtifact extends Model
{
    /** @use HasFactory<IntelligenceArtifactFactory> */
    use HasFactory;

    public const PDF = 'pdf';

    public const XLSX = 'xlsx';

    /** Artefak hanya punya created_at; tidak pernah diperbarui. */
    public const UPDATED_AT = null;

    public function report(): BelongsTo
    {
        return $this->belongsTo(IntelligenceReport::class, 'report_id');
    }

    public function delete(): never
    {
        throw new LogicException('Artefak intelligence tidak dapat dihapus.');
    }

    protected function casts(): array
    {
        return [
            'report_id' => 'integer',
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Artefak intelligence bersifat immutable.');
    }
}
