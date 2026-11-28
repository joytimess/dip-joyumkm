<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\OwnershipTransferConflict;
use App\Models\Umkm;
use App\Models\UmkmOwnershipTransfer;
use App\Models\User;
use App\Models\WebhookOutbox;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

/**
 * Transfer kepemilikan satu UMKM antar Analyst.
 *
 * Urutan lock (konsisten di seluruh jalur tulis UMKM): baris umkms lebih dulu, lalu baris users berurutan menurut id.
 * Jalur draft/finalisasi 3D dan 6F, update/hapus UMKM, dan perubahan consent sudah/kini mengunci baris umkms;
 * perubahan status akun hanya mengunci baris users sehingga tidak membentuk siklus.
 * Semua pemeriksaan diulang setelah lock didapat; ketidakpastian dianggap konflik.
 */
class OwnershipTransferService
{
    /** Event Hermes yang dikirim tetapi laporannya belum kembali dianggap masih berjalan selama jendela ini. */
    private const REPORT_WAIT_HOURS = 24;

    public function __construct(private readonly DatabaseManager $database) {}

    public function transfer(User $actor, int $umkmId, int $expectedOwnerId, int $toUserId, string $reason): UmkmOwnershipTransfer
    {
        try {
            return $this->database->transaction(
                fn (): UmkmOwnershipTransfer => $this->perform($actor, $umkmId, $expectedOwnerId, $toUserId, $reason),
                attempts: 2,
            );
        } catch (UniqueConstraintViolationException) {
            throw new OwnershipTransferConflict('name_collision', 'Penerima sudah memiliki UMKM dengan nama yang sama.');
        }
    }

    private function perform(User $actor, int $umkmId, int $expectedOwnerId, int $toUserId, string $reason): UmkmOwnershipTransfer
    {
        $umkm = Umkm::query()->whereKey($umkmId)->lockForUpdate()->firstOrFail();
        $fromUserId = (int) $umkm->user_id;

        $users = User::query()->whereIn('id', array_unique([$actor->id, $fromUserId, $toUserId]))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lockedActor = $users->get($actor->id);
        abort_unless($lockedActor !== null && Gate::forUser($lockedActor)->allows('transfer', $umkm), 403);

        if ($fromUserId !== $expectedOwnerId) {
            throw new OwnershipTransferConflict('stale_owner', 'Pemilik UMKM telah berubah. Muat ulang data sebelum memindahkan.');
        }
        if ($fromUserId === $toUserId) {
            throw new OwnershipTransferConflict('same_owner', 'UMKM sudah dimiliki Analyst tujuan.');
        }
        $source = $users->get($fromUserId);
        $target = $users->get($toUserId);
        if ($source === null || $source->userRole() !== UserRole::Analyst) {
            throw new OwnershipTransferConflict('invalid_source', 'Hanya UMKM milik Analyst yang dapat dipindahkan.');
        }
        if ($target === null || $target->userRole() !== UserRole::Analyst) {
            throw new OwnershipTransferConflict('invalid_target', 'Tujuan harus akun Analyst.');
        }
        if (! $target->isActive()) {
            throw new OwnershipTransferConflict('target_inactive', 'Analyst tujuan sedang nonaktif.');
        }
        if (Umkm::query()->where('user_id', $toUserId)->where('name', $umkm->name)->exists()) {
            throw new OwnershipTransferConflict('name_collision', 'Penerima sudah memiliki UMKM dengan nama yang sama.');
        }
        $this->assertNoActiveWork($umkm);

        $umkm->forceFill(['user_id' => $toUserId])->save();
        $audit = (new UmkmOwnershipTransfer)->forceFill([
            'umkm_id' => $umkm->id,
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'performed_by_user_id' => $lockedActor->id,
            'reason' => $reason,
            'transferred_at' => now(),
        ]);
        $audit->save();

        return $audit;
    }

    private function assertNoActiveWork(Umkm $umkm): void
    {
        $db = $umkm->getConnection();
        if ($umkm->hasNotificationConsent()) {
            throw new OwnershipTransferConflict('consent_active', 'Persetujuan notifikasi masih aktif. Cabut persetujuan lebih dulu oleh pemilik saat ini.');
        }
        if ($db->table('six_factor_evaluations')->where('umkm_id', $umkm->id)->where('status', 'draft')->exists()) {
            throw new OwnershipTransferConflict('six_factor_draft', 'Masih ada draf penilaian Enam Faktor. Finalkan atau batalkan lebih dulu.');
        }
        $latestThreeDimension = $db->table('evaluations')->where('umkm_id', $umkm->id)->orderByDesc('id')->value('status');
        if ($latestThreeDimension === 'draft') {
            throw new OwnershipTransferConflict('three_dimension_draft', 'Penilaian Tiga Dimensi terakhir masih draf. Selesaikan lebih dulu.');
        }

        $evaluationIds = $db->table('six_factor_evaluations')->where('umkm_id', $umkm->id)->pluck('id');
        if ($evaluationIds->isNotEmpty()) {
            $inFlight = $db->table('webhook_outbox')
                ->where('event_type', 'evaluation.finalized')
                ->whereIn('aggregate_id', $evaluationIds)
                ->whereIn('status', [WebhookOutbox::PENDING, WebhookOutbox::PROCESSING, WebhookOutbox::RETRY_WAIT])
                ->exists();
            $awaitingReport = $db->table('webhook_outbox as event')
                ->where('event.event_type', 'evaluation.finalized')
                ->whereIn('event.aggregate_id', $evaluationIds)
                ->where('event.status', WebhookOutbox::DELIVERED)
                ->where('event.delivered_at', '>=', now()->subHours(self::REPORT_WAIT_HOURS))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('intelligence_reports as report')
                    ->whereColumn('report.event_id', 'event.event_id'))
                ->exists();
            if ($inFlight || $awaitingReport) {
                throw new OwnershipTransferConflict('report_in_flight', 'Laporan intelligence untuk UMKM ini masih diproses. Coba lagi setelah selesai.');
            }
        }

        $reportEventIds = $db->table('intelligence_reports')->where('umkm_id', $umkm->id)->pluck('event_id');
        if ($reportEventIds->isNotEmpty()) {
            $pendingArtifacts = $db->table('intelligence_reports')->where('umkm_id', $umkm->id)->where('artifact_status', 'pending')->exists();
            $pendingDelivery = $db->table('intelligence_deliveries')->whereIn('event_id', $reportEventIds)
                ->whereIn('status', ['queued', 'failed_retryable'])->exists();
            if ($pendingArtifacts || $pendingDelivery) {
                throw new OwnershipTransferConflict('delivery_in_flight', 'Artefak atau pengiriman laporan masih berjalan. Coba lagi setelah selesai.');
            }
        }
    }
}
