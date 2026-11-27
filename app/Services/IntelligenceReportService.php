<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntelligenceArtifactManifest;
use App\Models\IntelligenceReport;
use App\Models\SixFactorEvaluation;
use App\Models\User;
use App\Models\WebhookOutbox;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class IntelligenceReportService
{
    public const STORED = 'stored';

    public const DUPLICATE = 'duplicate';

    public const CONFLICT = 'conflict';

    public const ARTIFACT_CONFLICT = 'artifact_conflict';

    private const EVENT_TYPE = 'evaluation.finalized';

    /**
     * Simpan laporan callback satu kali. Laporan yang sudah ada tidak pernah diubah.
     *
     * @return array{outcome: string, report: IntelligenceReport, manifest: IntelligenceArtifactManifest|null}
     */
    public function store(IntelligenceCallbackPayload $payload): array
    {
        try {
            return DB::transaction(fn (): array => $this->storeWithinTransaction($payload), attempts: 3);
        } catch (QueryException) {
            // Pengecualian query dapat memuat isi laporan melalui binding SQL; jangan teruskan ke log/respons.
            throw new ServiceUnavailableHttpException(30, 'Laporan gagal disimpan.');
        }
    }

    /**
     * @return array{outcome: string, report: IntelligenceReport, manifest: IntelligenceArtifactManifest|null}
     */
    private function storeWithinTransaction(IntelligenceCallbackPayload $payload): array
    {
        $eventId = $payload->eventId();
        $this->assertMatchesFinalization($payload);

        $hash = $payload->hash();
        $existing = IntelligenceReport::query()->where('event_id', $eventId)->first();
        if ($existing === null) {
            $report = $this->newReport($payload, $hash);
            try {
                $report->save();
                $manifest = $this->bindManifest($report, $payload->manifest());
                $report->refresh();

                return ['outcome' => self::STORED, 'report' => $report, 'manifest' => $manifest];
            } catch (UniqueConstraintViolationException) {
                // Callback paralel menang lebih dulu; baca ulang dengan locking read agar commit-nya terlihat.
                $existing = IntelligenceReport::query()
                    ->where('event_id', $eventId)->orWhere('evaluation_id', $payload->data['evaluation_id'])
                    ->lockForUpdate()->first();
                if ($existing === null) {
                    throw new ServiceUnavailableHttpException(30, 'Laporan gagal disimpan.');
                }
            }
        }

        $manifest = IntelligenceArtifactManifest::query()->where('report_id', $existing->id)->sharedLock()->first();
        if ($existing->event_id !== $eventId || ! hash_equals($existing->report_hash, $hash)) {
            return ['outcome' => self::CONFLICT, 'report' => $existing, 'manifest' => $manifest];
        }

        // Manifest yang sudah terikat immutable; laporan lama tanpa manifest tidak di-upgrade otomatis.
        $incoming = $payload->manifest();
        if ($manifest !== null && $incoming !== null
            && ! hash_equals($manifest->transport_artifact_set_hash, IntelligenceArtifactContract::setHash($incoming))) {
            return ['outcome' => self::ARTIFACT_CONFLICT, 'report' => $existing, 'manifest' => $manifest];
        }

        return ['outcome' => self::DUPLICATE, 'report' => $existing, 'manifest' => $manifest];
    }

    /**
     * @param  list<array{kind: string, mime: string, size_bytes: int, sha256: string}>|null  $entries
     */
    private function bindManifest(IntelligenceReport $report, ?array $entries): ?IntelligenceArtifactManifest
    {
        if ($entries === null) {
            return null;
        }

        $manifest = (new IntelligenceArtifactManifest)->forceFill([
            'report_id' => $report->id,
            'event_id' => $report->event_id,
            'artifacts' => $entries,
            'transport_artifact_set_hash' => IntelligenceArtifactContract::setHash($entries),
            'created_at' => now()->startOfSecond(),
        ]);
        $manifest->save();

        return $manifest;
    }

    /**
     * Pastikan identitas laporan cocok dengan event finalisasi dan snapshot outbox versi 1 atau 2.
     */
    private function assertMatchesFinalization(IntelligenceCallbackPayload $payload): void
    {
        $data = $payload->data;
        $event = WebhookOutbox::query()->where('event_id', $payload->eventId())->first();
        if ($event === null) {
            throw new NotFoundHttpException;
        }

        if ($event->event_type !== self::EVENT_TYPE || ! in_array($event->event_version, [1, 2], true)) {
            throw $this->mismatch('source_event_version');
        }
        if ($event->event_version !== $data['source_event_version']) {
            throw $this->mismatch('source_event_version');
        }
        if ($event->aggregate_id !== $data['evaluation_id']) {
            throw $this->mismatch('evaluation_id');
        }

        $evaluation = SixFactorEvaluation::query()->find($data['evaluation_id']);
        if ($evaluation === null || $evaluation->status !== SixFactorEvaluation::FINAL) {
            throw $this->mismatch('evaluation_id');
        }
        if ($evaluation->umkm_id !== $data['umkm_id']) {
            throw $this->mismatch('umkm_id');
        }

        $snapshot = $this->sixFactorSnapshot($event);
        $expected = [
            'umkm_id' => $evaluation->umkm_id,
            'evaluation_id' => $evaluation->id,
            'status' => SixFactorEvaluation::FINAL,
            'total_score' => $evaluation->total_score,
            'category' => $evaluation->category,
            'scores' => $evaluation->only(SixFactorEvaluation::FACTORS),
        ];
        if ($snapshot !== $expected) {
            throw $this->mismatch('event_id');
        }
    }

    /**
     * Ambil ringkasan Enam Faktor dari payload outbox sesuai versinya.
     * Versi 1 memakai `data.*`; versi 2 memakai `data.six_factor.*` dengan `data.umkm_id`.
     *
     * @return array<string, mixed>
     */
    private function sixFactorSnapshot(WebhookOutbox $event): array
    {
        $data = $event->payload['data'] ?? null;
        $data = is_array($data) ? $data : [];
        $six = $event->event_version === 1 ? $data : ($data['six_factor'] ?? []);
        $six = is_array($six) ? $six : [];

        return [
            'umkm_id' => $data['umkm_id'] ?? null,
            'evaluation_id' => $six['evaluation_id'] ?? null,
            'status' => $six['status'] ?? null,
            'total_score' => $six['total_score'] ?? null,
            'category' => $six['category'] ?? null,
            'scores' => is_array($six['scores'] ?? null) ? $this->orderedScores($six['scores']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $scores
     * @return array<string, mixed>
     */
    private function orderedScores(array $scores): array
    {
        $ordered = [];
        foreach (SixFactorEvaluation::FACTORS as $factor) {
            if (array_key_exists($factor, $scores)) {
                $ordered[$factor] = $scores[$factor];
            }
        }

        return count($scores) === count($ordered) ? $ordered : $scores;
    }

    private function newReport(IntelligenceCallbackPayload $payload, string $hash): IntelligenceReport
    {
        $data = $payload->data;

        return (new IntelligenceReport)->forceFill([
            'event_id' => $payload->eventId(),
            'evaluation_id' => $data['evaluation_id'],
            'umkm_id' => $data['umkm_id'],
            'source_event_version' => $data['source_event_version'],
            'schema_version' => $data['schema_version'],
            'prompt_version' => $data['prompt_version'],
            'knowledge_version' => $data['knowledge_version'],
            'methodology_status' => $data['methodology_status'],
            'provider' => $data['provider'],
            'model' => $data['model'],
            'report' => $payload->reportJson(),
            'report_hash' => $hash,
            'generated_at' => $payload->generatedAt(),
            'received_at' => now(),
        ]);
    }

    private function mismatch(string $field): ValidationException
    {
        return ValidationException::withMessages([$field => ['Laporan tidak sesuai dengan event finalisasi.']]);
    }

    /**
     * Laporan terbaru milik UMKM; null bila belum ada laporan yang diterima.
     */
    public function latest(User $user, int $umkmId): ?IntelligenceReport
    {
        $this->ensureOwned($user, $umkmId);

        return $this->ordered($umkmId)->first();
    }

    public function history(User $user, int $umkmId, int $perPage, int $page): LengthAwarePaginator
    {
        $this->ensureOwned($user, $umkmId);

        return $this->ordered($umkmId, IntelligenceReport::SUMMARY_COLUMNS)
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }

    public function find(User $user, int $umkmId, int $reportId): IntelligenceReport
    {
        $this->ensureOwned($user, $umkmId);

        return IntelligenceReport::query()->where('umkm_id', $umkmId)->whereKey($reportId)->firstOrFail();
    }

    private function ensureOwned(User $user, int $umkmId): void
    {
        if (! $user->accessibleUmkms()->whereKey($umkmId)->exists()) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function ordered(int $umkmId, array $columns = ['*']): Builder
    {
        return IntelligenceReport::query()->select($columns)->where('umkm_id', $umkmId)
            ->orderByDesc('generated_at')->orderByDesc('id');
    }
}
