<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SixFactorEvaluationFactory;
use DateTimeInterface;
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

    public const PERIOD_TIMEZONE = 'Asia/Jakarta';

    /**
     * Bulan periode (YYYY-MM) menurut WIB untuk waktu finalisasi. Waktu asli tetap UTC.
     */
    public static function periodMonthFor(DateTimeInterface $finalizedAt): string
    {
        return CarbonImmutable::instance($finalizedAt)->setTimezone(self::PERIOD_TIMEZONE)->format('Y-m');
    }

    protected static function booted(): void
    {
        // period_month selalu diturunkan dari finalized_at saat menyimpan, sehingga tidak bisa menyimpang.
        static::saving(function (SixFactorEvaluation $evaluation): void {
            if ($evaluation->status === self::FINAL && $evaluation->finalized_at !== null) {
                $evaluation->period_month = self::periodMonthFor($evaluation->finalized_at);
            } elseif ($evaluation->period_month !== null) {
                $evaluation->period_month = null;
            }
        });
    }

    public function umkm(): BelongsTo
    {
        return $this->belongsTo(Umkm::class);
    }

    /**
     * Kategori kesehatan untuk total 6-30 sesuai rentang yang ditegakkan CHECK pada tabel.
     */
    public static function categoryForTotal(int $total): string
    {
        return match (true) {
            $total <= 12 => 'Kurang Sehat',
            $total <= 18 => 'Cukup Sehat',
            $total <= 24 => 'Sehat',
            default => 'Sangat Sehat',
        };
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
