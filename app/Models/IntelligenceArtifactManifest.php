<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IntelligenceArtifactContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Manifest artefak yang diharapkan; immutable, terikat satu laporan. */
class IntelligenceArtifactManifest extends Model
{
    public const UPDATED_AT = null;

    public function report(): BelongsTo
    {
        return $this->belongsTo(IntelligenceReport::class, 'report_id');
    }

    /**
     * Entri dalam urutan kanonis kontrak (kolom JSON MySQL tidak menjaga urutan key).
     *
     * @return list<array{kind: string, mime: string, size_bytes: int, sha256: string}>
     */
    public function entries(): array
    {
        return IntelligenceArtifactContract::normalizeManifest($this->artifacts);
    }

    /**
     * @return array{kind: string, mime: string, size_bytes: int, sha256: string}|null
     */
    public function entryFor(string $kind): ?array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['kind'] === $kind) {
                return $entry;
            }
        }

        return null;
    }

    public function delete(): never
    {
        throw new LogicException('Manifest artefak tidak dapat dihapus.');
    }

    protected function casts(): array
    {
        return ['report_id' => 'integer', 'artifacts' => 'array', 'created_at' => 'datetime'];
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Manifest artefak bersifat immutable.');
    }
}
