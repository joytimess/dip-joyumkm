<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\User;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SixFactorEvaluationService
{
    public function __construct(
        private readonly UmkmService $umkmService,
        private readonly DatabaseManager $database,
    ) {}

    public function activeDraft(User $user, int $umkmId): ?SixFactorEvaluation
    {
        return $this->umkmService->owned($user, $umkmId)->activeSixFactorDraft()->first();
    }

    /** @param array<string, mixed> $factors */
    public function saveDraft(User $user, int $umkmId, array $factors): SixFactorEvaluation
    {
        $this->validateFactors($factors);
        $this->umkmService->owned($user, $umkmId);

        // Lock pencarian Draft kosong dapat berbenturan saat dua UMKM berbeda mulai bersamaan.
        $save = fn (): SixFactorEvaluation => $this->database->transaction(function () use ($user, $umkmId, $factors): SixFactorEvaluation {
            $umkm = $this->lockOwnedUmkm($user, $umkmId);
            $draft = $umkm->activeSixFactorDraft()->lockForUpdate()->first()
                ?? $umkm->sixFactorEvaluations()->make();

            return $this->persist($draft->fill($factors));
        }, attempts: 2);

        try {
            return $save();
        } catch (UniqueConstraintViolationException $exception) {
            if (! str($exception->errorInfo[2] ?? '')->endsWith([
                "for key 'six_factor_active_draft_unique'",
                ".six_factor_active_draft_unique'",
            ])) {
                throw $exception;
            }

            return $save();
        }
    }

    public function finalize(User $user, int $umkmId, ?int $evaluationId = null): SixFactorEvaluation
    {
        return $this->transition($user, $umkmId, $evaluationId, static function (SixFactorEvaluation $draft) use ($evaluationId): array {
            $scores = $draft->only(SixFactorEvaluation::FACTORS);
            $missing = array_keys($scores, null, true);
            if ($missing !== []) {
                throw ValidationException::withMessages($evaluationId === null
                    ? ['factors' => ['Keenam faktor wajib diisi sebelum finalisasi.']]
                    : ['scores' => ['Faktor yang belum diisi: '.implode(', ', $missing).'.']]);
            }

            $total = array_sum($scores);

            return [
                'status' => SixFactorEvaluation::FINAL,
                'total_score' => $total,
                'category' => match (true) {
                    $total <= 12 => 'Kurang Sehat',
                    $total <= 18 => 'Cukup Sehat',
                    $total <= 24 => 'Sehat',
                    default => 'Sangat Sehat',
                },
                'finalized_at' => now(),
            ];
        });
    }

    public function cancelDraft(User $user, int $umkmId, ?int $evaluationId = null): SixFactorEvaluation
    {
        return $this->transition($user, $umkmId, $evaluationId, static fn (): array => [
            'status' => SixFactorEvaluation::CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /** @return Collection<int, SixFactorEvaluation> */
    public function finalHistory(User $user, int $umkmId): Collection
    {
        return $this->umkmService->owned($user, $umkmId)->sixFactorEvaluations()
            ->where('status', SixFactorEvaluation::FINAL)
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->get();
    }

    public function latestFinal(User $user, int $umkmId): ?SixFactorEvaluation
    {
        return $this->umkmService->owned($user, $umkmId)->latestSixFactorFinal()->first();
    }

    /** @param array<string, mixed> $factors */
    private function validateFactors(array $factors): void
    {
        $errors = [];
        foreach ($factors as $field => $score) {
            if (! in_array($field, SixFactorEvaluation::FACTORS, true)) {
                $errors[$field] = ['Kolom ini tidak boleh dikirim.'];
            } elseif ($score !== null && (! is_int($score) || $score < 1 || $score > 5)) {
                $errors[$field] = ['Nilai faktor harus berupa bilangan bulat 1 sampai 5 atau null.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function lockOwnedUmkm(User $user, int $umkmId): Umkm
    {
        return $user->umkms()->whereKey($umkmId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  Closure(SixFactorEvaluation): array{status: string, total_score?: int, category?: string, finalized_at?: \DateTimeInterface, cancelled_at?: \DateTimeInterface}  $attributes
     */
    private function transition(User $user, int $umkmId, ?int $evaluationId, Closure $attributes): SixFactorEvaluation
    {
        $this->umkmService->owned($user, $umkmId);

        return $this->database->transaction(function () use ($user, $umkmId, $evaluationId, $attributes): SixFactorEvaluation {
            $umkm = $this->lockOwnedUmkm($user, $umkmId);
            $evaluation = $evaluationId === null
                ? $umkm->activeSixFactorDraft()->lockForUpdate()->firstOrFail()
                : $umkm->sixFactorEvaluations()->lockForUpdate()->findOrFail($evaluationId);

            if ($evaluation->status !== SixFactorEvaluation::DRAFT) {
                throw new ConflictHttpException($evaluation->status === SixFactorEvaluation::FINAL
                    ? 'Evaluasi sudah Final dan tidak dapat diubah.'
                    : 'Evaluasi sudah dibatalkan dan tidak dapat diubah.');
            }

            return $this->persist($evaluation->forceFill($attributes($evaluation)));
        });
    }

    private function persist(SixFactorEvaluation $draft): SixFactorEvaluation
    {
        if (! $draft->save()) {
            throw new RuntimeException('Evaluasi enam faktor gagal disimpan.');
        }

        return $draft->refreshForUpdate();
    }
}
