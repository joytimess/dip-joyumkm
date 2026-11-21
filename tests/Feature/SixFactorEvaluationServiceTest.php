<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\User;
use App\Services\SixFactorEvaluationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SixFactorEvaluationServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FACTORS = [
        'sumber_daya', 'kepemimpinan', 'lingkungan_kerja', 'nilai_organisasi', 'stabilitas', 'kinerja',
    ];

    public function test_saves_empty_draft_and_merges_only_sent_factors_in_the_same_row(): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);

        $this->assertNull($service->activeDraft($umkm->user, $umkm->id));
        $draft = $service->saveDraft($umkm->user, $umkm->id, []);
        $this->assertSame('draft', $draft->status);
        $this->assertSame($umkm->id, $draft->active_draft_umkm_id);
        $this->assertSame(array_fill_keys(self::FACTORS, null), $draft->only(self::FACTORS));
        $this->assertSame([null, null, null, null], [
            $draft->total_score, $draft->category, $draft->finalized_at, $draft->cancelled_at,
        ]);

        $service->saveDraft($umkm->user, $umkm->id, ['sumber_daya' => 3, 'stabilitas' => 5]);
        $updated = $service->saveDraft($umkm->user, $umkm->id, ['sumber_daya' => null, 'kinerja' => 1]);

        $this->assertSame($draft->id, $updated->id);
        $this->assertNull($updated->sumber_daya);
        $this->assertSame(5, $updated->stabilitas);
        $this->assertSame(1, $updated->kinerja);
        $this->assertSame($draft->id, $service->activeDraft($umkm->user, $umkm->id)?->id);
        $this->assertSame($draft->id, $umkm->activeSixFactorDraft?->id);
        $this->assertDatabaseCount('six_factor_evaluations', 1);
    }

    public function test_rejects_invalid_types_unknown_keys_and_server_columns_before_writing(): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);

        foreach ([0, 6, -1, '3', 3.0, true, [], new \stdClass] as $invalid) {
            try {
                $service->saveDraft($umkm->user, $umkm->id, ['sumber_daya' => $invalid]);
                $this->fail('Nilai faktor yang tidak valid diterima.');
            } catch (ValidationException $exception) {
                $this->assertSame(['sumber_daya' => ['Nilai faktor harus berupa bilangan bulat 1 sampai 5 atau null.']], $exception->errors());
            }
        }

        $forbidden = ['lain', 'id', 'umkm_id', 'user_id', 'status', 'total_score', 'category', 'finalized_at',
            'cancelled_at', 'created_at', 'updated_at', 'active_draft_umkm_id'];

        try {
            $service->saveDraft($umkm->user, $umkm->id, array_fill_keys($forbidden, null));
            $this->fail('Kolom di luar faktor diterima.');
        } catch (ValidationException $exception) {
            $this->assertSame(array_fill_keys($forbidden, ['Kolom ini tidak boleh dikirim.']), $exception->errors());
        }

        $this->assertDatabaseCount('six_factor_evaluations', 0);
        $this->assertSame(self::FACTORS, (new SixFactorEvaluation)->getFillable());
    }

    #[DataProvider('scoreBoundaries')]
    public function test_finalizes_the_same_row_with_the_server_score(array $scores, int $total, string $category): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);
        $factors = array_combine(self::FACTORS, $scores);
        $this->travelTo(now()->startOfSecond());
        $draft = $service->saveDraft($umkm->user, $umkm->id, $factors);

        $final = $service->finalize($umkm->user, $umkm->id);

        $this->assertSame($draft->id, $final->id);
        $this->assertSame('final', $final->status);
        $this->assertSame($factors, $final->only(self::FACTORS));
        $this->assertSame($total, $final->total_score);
        $this->assertSame($category, $final->category);
        $this->assertTrue(now()->equalTo($final->finalized_at));
        $this->assertNull($final->cancelled_at);
        $this->assertNull($final->active_draft_umkm_id);
        $this->assertNull($service->activeDraft($umkm->user, $umkm->id));
        $this->assertSame($final->id, $service->latestFinal($umkm->user, $umkm->id)?->id);
        $this->assertDatabaseCount('six_factor_evaluations', 1);
    }

    public static function scoreBoundaries(): array
    {
        return [
            '6' => [[1, 1, 1, 1, 1, 1], 6, 'Kurang Sehat'],
            '12' => [[1, 1, 1, 1, 3, 5], 12, 'Kurang Sehat'],
            '13' => [[3, 1, 1, 2, 1, 5], 13, 'Cukup Sehat'],
            '18' => [[3, 3, 3, 3, 3, 3], 18, 'Cukup Sehat'],
            '19' => [[4, 4, 4, 1, 4, 2], 19, 'Sehat'],
            '24' => [[4, 4, 4, 4, 4, 4], 24, 'Sehat'],
            '25' => [[3, 4, 4, 4, 5, 5], 25, 'Sangat Sehat'],
            '30' => [[5, 5, 5, 5, 5, 5], 30, 'Sangat Sehat'],
        ];
    }

    public function test_incomplete_finalization_preserves_the_row_and_it_can_be_completed(): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);
        $draft = $service->saveDraft($umkm->user, $umkm->id, ['sumber_daya' => 1]);

        try {
            $service->finalize($umkm->user, $umkm->id);
            $this->fail('Draft yang belum lengkap difinalisasi.');
        } catch (ValidationException $exception) {
            $this->assertSame(['factors' => ['Keenam faktor wajib diisi sebelum finalisasi.']], $exception->errors());
        }

        $this->assertSame($draft->getRawOriginal(), $draft->fresh()->getRawOriginal());
        $service->saveDraft($umkm->user, $umkm->id, array_fill_keys(self::FACTORS, 2));
        $this->assertSame($draft->id, $service->finalize($umkm->user, $umkm->id)->id);
    }

    public function test_cancel_keeps_scores_and_final_or_cancelled_rows_release_the_draft_slot(): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);
        $this->travelTo(now()->startOfSecond());
        $draft = $service->saveDraft($umkm->user, $umkm->id, ['nilai_organisasi' => 2]);

        $cancelled = $service->cancelDraft($umkm->user, $umkm->id);

        $this->assertSame($draft->id, $cancelled->id);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($draft->only(self::FACTORS), $cancelled->only(self::FACTORS));
        $this->assertTrue(now()->equalTo($cancelled->cancelled_at));
        $this->assertSame([null, null, null, null], [
            $cancelled->total_score, $cancelled->category, $cancelled->finalized_at, $cancelled->active_draft_umkm_id,
        ]);
        $new = $service->saveDraft($umkm->user, $umkm->id, array_fill_keys(self::FACTORS, 2));
        $this->assertNotSame($cancelled->id, $new->id);
        $final = $service->finalize($umkm->user, $umkm->id);
        $next = $service->saveDraft($umkm->user, $umkm->id, []);
        $this->assertNotSame($final->id, $next->id);
        $this->assertSame($cancelled->getRawOriginal(), $cancelled->fresh()->getRawOriginal());
        $this->assertSame($final->getRawOriginal(), $final->fresh()->getRawOriginal());
        $this->assertSame(3, $umkm->sixFactorEvaluations()->count());
    }

    public function test_history_and_latest_share_finalization_order_and_exclude_other_rows(): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);
        $this->assertNull($service->latestFinal($umkm->user, $umkm->id));
        $this->assertTrue($service->finalHistory($umkm->user, $umkm->id)->isEmpty());

        $newTime = now();
        $newer = SixFactorEvaluation::factory()->for($umkm)->final()->create(['finalized_at' => $newTime]);
        $tie = SixFactorEvaluation::factory()->for($umkm)->final()->create(['finalized_at' => $newTime]);
        $older = SixFactorEvaluation::factory()->for($umkm)->final()->create(['finalized_at' => $newTime->copy()->subDay()]);
        SixFactorEvaluation::factory()->for($umkm)->cancelled()->create();
        SixFactorEvaluation::factory()->for($umkm)->draft()->create();
        SixFactorEvaluation::factory()->for(Umkm::factory()->for($umkm->user))->final()->create();

        $history = $service->finalHistory($umkm->user, $umkm->id);

        $this->assertSame([$tie->id, $newer->id, $older->id], $history->modelKeys());
        $this->assertTrue($history->first()->is($service->latestFinal($umkm->user, $umkm->id)));
        $this->assertSame($tie->id, $umkm->latestSixFactorFinal?->id);
        $related = Umkm::with(['latestSixFactorFinal', 'activeSixFactorDraft'])->findOrFail($umkm->id);
        $this->assertSame($tie->id, $related->latestSixFactorFinal?->id);
        $this->assertSame('draft', $related->activeSixFactorDraft?->status);
    }

    #[DataProvider('terminalOperations')]
    public function test_terminal_action_requires_an_active_draft(string $operation): void
    {
        $umkm = Umkm::factory()->create();
        SixFactorEvaluation::factory()->for($umkm)->final()->create();

        $this->expectException(ModelNotFoundException::class);
        app(SixFactorEvaluationService::class)->$operation($umkm->user, $umkm->id);
    }

    public static function terminalOperations(): array
    {
        return [['finalize'], ['cancelDraft']];
    }

    public function test_all_operations_reject_a_different_owner(): void
    {
        $umkm = Umkm::factory()->create();
        $other = User::factory()->create();
        $service = app(SixFactorEvaluationService::class);
        $draft = $service->saveDraft($umkm->user, $umkm->id, array_fill_keys(self::FACTORS, 2));

        foreach (['activeDraft', 'saveDraft', 'finalize', 'cancelDraft', 'finalHistory', 'latestFinal'] as $operation) {
            try {
                $arguments = $operation === 'saveDraft' ? [['sumber_daya' => 5]] : [];
                $service->$operation($other, $umkm->id, ...$arguments);
                $this->fail("Operasi {$operation} menerima pemilik lain.");
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(Umkm::class, $exception->getModel());
            }
        }

        $this->assertSame($draft->getRawOriginal(), $draft->fresh()->getRawOriginal());
    }

    public function test_terminal_records_reject_normal_quiet_and_stale_instance_updates(): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);
        $draft = $service->saveDraft($umkm->user, $umkm->id, array_fill_keys(self::FACTORS, 2));
        $final = $service->finalize($umkm->user, $umkm->id);
        $staleDraft = $service->saveDraft($umkm->user, $umkm->id, ['sumber_daya' => 1]);
        $cancelled = $service->cancelDraft($umkm->user, $umkm->id);

        foreach ([$final, $draft, $cancelled, $staleDraft] as $evaluation) {
            $original = $evaluation->fresh()->getRawOriginal();
            foreach (['update', 'updateQuietly'] as $operation) {
                try {
                    $evaluation->$operation(['sumber_daya' => 3]);
                    $this->fail('Perubahan evaluasi terminal diterima.');
                } catch (LogicException $exception) {
                    $this->assertSame('Evaluasi yang sudah selesai tidak dapat diubah.', $exception->getMessage());
                }
                $this->assertSame($original, $evaluation->fresh()->getRawOriginal());
            }
        }
    }

    public function test_model_counters_cannot_modify_terminal_factors(): void
    {
        $evaluation = SixFactorEvaluation::factory()->cancelled()->create();
        $original = $evaluation->fresh()->getRawOriginal();
        $operations = [
            fn () => $evaluation->increment('sumber_daya'),
            fn () => $evaluation->decrementQuietly('sumber_daya'),
            fn () => $evaluation->incrementEach(['sumber_daya' => 1]),
            fn () => $evaluation->decrementEachQuietly(['sumber_daya' => 1]),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Operasi penghitung mengubah evaluasi terminal.');
            } catch (LogicException $exception) {
                $this->assertSame('Gunakan layanan evaluasi enam faktor untuk mengubah nilai.', $exception->getMessage());
            }
            $this->assertSame($original, $evaluation->fresh()->getRawOriginal());
        }
    }

    #[DataProvider('deletionStates')]
    public function test_model_rejects_deletion_in_every_status(string $state, string $operation): void
    {
        $evaluation = SixFactorEvaluation::factory()->$state()->create();

        try {
            $evaluation->$operation();
            $this->fail('Evaluasi berhasil dihapus.');
        } catch (LogicException $exception) {
            $this->assertSame('Evaluasi enam faktor tidak dapat dihapus.', $exception->getMessage());
        }

        $this->assertModelExists($evaluation);
    }

    public static function deletionStates(): array
    {
        return [['draft', 'delete'], ['final', 'deleteQuietly'], ['cancelled', 'delete']];
    }

    public function test_exception_after_update_rolls_back_the_finalization(): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);
        $draft = $service->saveDraft($umkm->user, $umkm->id, array_fill_keys(self::FACTORS, 2));
        $failure = new RuntimeException('Simulasi gangguan sesudah UPDATE.');
        SixFactorEvaluation::updated(static function () use ($failure): never {
            throw $failure;
        });

        try {
            $service->finalize($umkm->user, $umkm->id);
            $this->fail('Kegagalan tidak dipropagasikan.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($draft->getRawOriginal(), $draft->fresh()->getRawOriginal());
        $this->assertNull($service->latestFinal($umkm->user, $umkm->id));
    }

    public function test_retries_draft_unique_conflict_once_and_reads_the_draft_again(): void
    {
        $umkm = Umkm::factory()->create();
        $service = app(SixFactorEvaluationService::class);
        $failure = $this->uniqueFailure('six_factor_active_draft_unique');
        $attempts = 0;
        $competingId = null;
        SixFactorEvaluation::creating(static function () use (&$attempts, $failure): void {
            $attempts++;
            throw $failure;
        });
        Event::listen(TransactionRolledBack::class, static function () use (&$competingId, &$attempts, $umkm): void {
            if ($attempts === 1 && $competingId === null) {
                $competingId = DB::table('six_factor_evaluations')->insertGetId([
                    'umkm_id' => $umkm->id,
                    'kinerja' => 4,
                ]);
            }
        });

        $saved = $service->saveDraft($umkm->user, $umkm->id, ['sumber_daya' => 2]);

        $this->assertSame(1, $attempts);
        $this->assertSame($competingId, $saved->id);
        $this->assertSame([2, 4], [$saved->sumber_daya, $saved->kinerja]);
        $this->assertDatabaseCount('six_factor_evaluations', 1);
    }

    #[DataProvider('uniqueFailureAttempts')]
    public function test_propagates_persistent_or_unrelated_unique_failures(string $key, int $expectedAttempts): void
    {
        $umkm = Umkm::factory()->create();
        $failure = $this->uniqueFailure($key);
        $attempts = 0;
        SixFactorEvaluation::creating(static function () use (&$attempts, $failure): never {
            $attempts++;
            throw $failure;
        });

        try {
            app(SixFactorEvaluationService::class)->saveDraft($umkm->user, $umkm->id, []);
            $this->fail('Kegagalan tidak dipropagasikan.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($expectedAttempts, $attempts);
        $this->assertDatabaseCount('six_factor_evaluations', 0);
    }

    public static function uniqueFailureAttempts(): array
    {
        return [['six_factor_active_draft_unique', 2], ['PRIMARY', 1]];
    }

    private function uniqueFailure(string $key): UniqueConstraintViolationException
    {
        $message = "Duplicate entry '1' for key 'six_factor_evaluations.{$key}'";
        $previous = new PDOException($message);
        $previous->errorInfo = ['23000', 1062, $message];

        return new UniqueConstraintViolationException('mysql', 'insert into six_factor_evaluations', [], $previous);
    }
}
