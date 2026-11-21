<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SixFactorEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(self::FACTORS)]
class SixFactorEvaluation extends Model
{
    /** @use HasFactory<SixFactorEvaluationFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const FINAL = 'final';

    public const CANCELLED = 'cancelled';

    public const FACTORS = [
        'sumber_daya',
        'kepemimpinan',
        'lingkungan_kerja',
        'nilai_organisasi',
        'stabilitas',
        'kinerja',
    ];

    public function umkm(): BelongsTo
    {
        return $this->belongsTo(Umkm::class);
    }

    public function delete(): never
    {
        throw new LogicException('Evaluasi enam faktor tidak dapat dihapus.');
    }

    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::FACTORS, 'integer'),
            'umkm_id' => 'integer',
            'active_draft_umkm_id' => 'integer',
            'total_score' => 'integer',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): bool
    {
        return $this->getConnection()->transaction(function () use ($query): bool {
            // Instance lama bisa masih membawa status Draft setelah transaksi lain menyelesaikannya.
            $currentStatus = $this->newModelQuery()->whereKey($this->getKeyForSaveQuery())->lockForUpdate()->value('status');
            if ($this->getRawOriginal('status') !== self::DRAFT || $currentStatus !== self::DRAFT) {
                throw new LogicException('Evaluasi yang sudah selesai tidak dapat diubah.');
            }

            return parent::performUpdate($query);
        });
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw new LogicException('Gunakan layanan evaluasi enam faktor untuk mengubah nilai.');
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw new LogicException('Gunakan layanan evaluasi enam faktor untuk mengubah nilai.');
    }
}
