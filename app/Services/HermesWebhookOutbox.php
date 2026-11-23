<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverHermesWebhook;
use App\Models\Evaluation;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\WebhookOutbox;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\QueryException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HermesWebhookOutbox
{
    private const LEASE_SECONDS = 45;

    private const EVENT_VERSION = 2;

    public function __construct(
        private readonly HermesWebhookSender $sender,
        private readonly QueueFactory $queue,
    ) {}

    public function recordFinalization(SixFactorEvaluation $evaluation): void
    {
        $metadata = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'evaluation.finalized',
            'event_version' => self::EVENT_VERSION,
        ];
        $occurredAt = $evaluation->finalized_at->copy()->utc();
        $event = (new WebhookOutbox)->setConnection($evaluation->getConnectionName())->forceFill([
            ...$metadata,
            'aggregate_id' => $evaluation->id,
            'payload' => [
                ...$metadata,
                'source' => 'joyumkm',
                'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s\Z'),
                'data' => [
                    'umkm_id' => $evaluation->umkm_id,
                    'business_context' => $this->businessContextSnapshot($evaluation),
                    'six_factor' => [
                        'evaluation_id' => $evaluation->id,
                        'status' => $evaluation->status,
                        'total_score' => $evaluation->total_score,
                        'category' => $evaluation->category,
                        'scores' => $evaluation->only(SixFactorEvaluation::FACTORS),
                    ],
                    'three_dimension' => $this->threeDimensionSnapshot($evaluation),
                ],
            ],
            'next_attempt_at' => now(),
        ]);

        try {
            $saved = $event->save();
        } catch (QueryException) {
            // Query exceptions can expose the raw payload through SQL bindings.
            throw new RuntimeException('Event finalisasi gagal disimpan.');
        }

        if (! $saved) {
            throw new RuntimeException('Event finalisasi gagal disimpan.');
        }

        $event->getConnection()->afterCommit(fn () => $this->dispatch($event->id));
    }

    /**
     * Konteks usaha saat finalisasi. Kontak pemilik, alamat, koordinat, dan NIB sengaja tidak dikirim.
     * Omzet periode yang tidak diketahui dikirim sebagai null; periode tahunan dinyatakan sebagai "annual".
     *
     * @return array{name: string, sector: ?string, location: ?string, started_year: ?int, employee_count: ?int, business_capital: ?int, revenue: array{amount: int, period: ?string}}|null
     */
    private function businessContextSnapshot(SixFactorEvaluation $evaluation): ?array
    {
        $umkm = Umkm::on($evaluation->getConnectionName())
            ->with('sector:id,name')
            ->whereKey($evaluation->umkm_id)
            ->first(['id', 'name', 'sector_id', 'city', 'year_founded', 'employee_count', 'business_capital', 'omzet', 'omzet_period']);
        if ($umkm === null) {
            return null;
        }

        return [
            'name' => $umkm->name,
            'sector' => $umkm->sector?->name,
            'location' => $umkm->city,
            'started_year' => $umkm->year_founded,
            'employee_count' => $umkm->employee_count,
            'business_capital' => $umkm->business_capital,
            'revenue' => [
                'amount' => $umkm->omzet,
                'period' => match ($umkm->omzet_period) {
                    'monthly' => 'monthly',
                    'yearly' => 'annual',
                    default => null,
                },
            ],
        ];
    }

    /**
     * Snapshot V1 final terbaru milik UMKM yang sama pada atau sebelum waktu finalisasi V2.
     * Waktu penilaian V1 adalah submitted_at (cadangan created_at), lalu ID sebagai pemutus seri.
     * Skor 0-100 apa adanya; null berarti dimensi tidak punya pertanyaan aktif.
     *
     * @return array{evaluation_id: int, status: string, assessed_at: string, average_score: ?float, category: ?string, is_complete: bool, scores: array{ins: ?float, ect: ?float, ops: ?float}}|null
     */
    private function threeDimensionSnapshot(SixFactorEvaluation $evaluation): ?array
    {
        $assessedAt = 'COALESCE(submitted_at, created_at)';
        $source = Evaluation::on($evaluation->getConnectionName())
            ->where('umkm_id', $evaluation->umkm_id)
            ->where('status', 'final')
            ->whereRaw($assessedAt.' <= ?', [$evaluation->finalized_at])
            ->orderByRaw($assessedAt.' DESC')
            ->orderByDesc('id')
            ->first(['id', 'ins_score', 'ect_score', 'ops_score', 'average_score', 'category', 'submitted_at', 'created_at']);
        if ($source === null) {
            return null;
        }

        $scores = ['ins' => $source->ins_score, 'ect' => $source->ect_score, 'ops' => $source->ops_score];

        return [
            'evaluation_id' => $source->id,
            'status' => 'final',
            'assessed_at' => ($source->submitted_at ?? $source->created_at)->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'average_score' => $source->average_score,
            'category' => $source->category,
            'is_complete' => ! in_array(null, $scores, true),
            'scores' => $scores,
        ];
    }

    /** @return array{queued: int, failed: int} */
    public function scan(): array
    {
        $counts = ['queued' => 0, 'failed' => 0];
        foreach (WebhookOutbox::query()->due()->select('id')->lazyById(200) as $event) {
            $queued = $this->enqueueSafely($event->id);
            if ($queued !== false) {
                $counts[$queued === true ? 'queued' : 'failed']++;
            }
        }

        return $counts;
    }

    public function requeue(string $eventId): bool
    {
        $event = WebhookOutbox::query()->where('event_id', $eventId)->first(['id']);
        if ($event === null || ! WebhookOutbox::query()->whereKey($event->id)
            ->where('status', WebhookOutbox::DEAD)->update([
                'status' => WebhookOutbox::PENDING,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ])) {
            return false;
        }

        $this->dispatch($event->id);

        return true;
    }

    public function deliver(int $id): void
    {
        if ($this->sender->configurationIssue() !== null) {
            return;
        }

        $event = $this->claim($id);
        if ($event === null) {
            return;
        }

        $result = $this->sender->send($event);
        $query = WebhookOutbox::query();
        $query->getModel()->getConnection()->transaction(function () use ($query, $event, $result): void {
            $claimed = $query->whereKey($event->id)->where('lease_token', $event->lease_token)->lockForUpdate()->first();
            if ($claimed === null || ! $claimed->lease_expires_at->isFuture()) {
                return;
            }

            $delivered = $result['error'] === null;
            $willRetry = $result['retryable'] && $event->attempts < $this->maxAttempts();
            $saved = $claimed->forceFill([
                'status' => match (true) {
                    $delivered => WebhookOutbox::DELIVERED,
                    $willRetry => WebhookOutbox::RETRY_WAIT,
                    default => WebhookOutbox::DEAD,
                },
                'next_attempt_at' => $willRetry ? now()->addSeconds($this->retryDelay($event->attempts, $result['retry_after'])) : null,
                'delivered_at' => $delivered ? now() : null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_http_status' => $result['http_status'],
                'last_error_code' => $result['error'],
                'last_error_summary' => $result['summary'],
            ])->save();
            if (! $saved) {
                throw new RuntimeException('Hasil pengiriman webhook gagal disimpan.');
            }
        });
    }

    private function dispatch(int $id): void
    {
        $issue = $this->sender->configurationIssue();
        if ($issue === null) {
            $this->enqueueSafely($id);
        } elseif ($issue !== 'disabled') {
            Log::warning('Hermes webhook delivery paused.', ['code' => $issue, 'outbox_id' => $id]);
        }
    }

    private function enqueueSafely(int $id): ?bool
    {
        try {
            $query = WebhookOutbox::query();
            $database = $query->getModel()->getConnection();
            $queue = $this->queue->connection('database');
            if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== $database) {
                throw new RuntimeException('Antrean webhook harus memakai koneksi database outbox.');
            }

            return $database->transaction(function () use ($id, $query, $database, $queue): bool {
                $event = $query->whereKey($id)->due()->lockForUpdate()->first();
                if ($event === null) {
                    return false;
                }

                // Persist the queue row and its reference together to deduplicate concurrent scans.
                if ($event->queued_job_id !== null && $database->table(config('queue.connections.database.table'))
                    ->where('id', $event->queued_job_id)->where('queue', DeliverHermesWebhook::QUEUE)
                    ->where(fn ($job) => $job->whereNull('reserved_at')->orWhere(
                        'reserved_at', '>', now()->getTimestamp() - (int) config('queue.connections.database.retry_after'),
                    ))->exists()) {
                    return false;
                }

                $jobId = $queue->push(new DeliverHermesWebhook($id), queue: DeliverHermesWebhook::QUEUE);
                if (! $event->forceFill(['queued_job_id' => $jobId])->save()) {
                    throw new RuntimeException('Referensi antrean webhook gagal disimpan.');
                }

                return true;
            });
        } catch (Throwable) {
            Log::warning('Hermes webhook queue dispatch failed.', ['outbox_id' => $id]);

            return null;
        }
    }

    private function claim(int $id): ?WebhookOutbox
    {
        $query = WebhookOutbox::query();

        return $query->getModel()->getConnection()->transaction(function () use ($query, $id): ?WebhookOutbox {
            $event = $query->whereKey($id)->due()->lockForUpdate()->first();
            if ($event === null) {
                return null;
            }

            $attributes = $event->attempts >= $this->maxAttempts()
                ? [
                    'status' => WebhookOutbox::DEAD,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_http_status' => null,
                    'last_error_code' => 'attempt_limit',
                    'last_error_summary' => 'Batas percobaan tercapai sebelum pengiriman dapat dipastikan.',
                ]
                : [
                    'status' => WebhookOutbox::PROCESSING,
                    'attempts' => $event->attempts + 1,
                    'lease_token' => (string) Str::uuid(),
                    'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                ];

            if (! $event->forceFill($attributes + ['next_attempt_at' => null, 'queued_job_id' => null])->save()) {
                throw new RuntimeException('Status percobaan webhook gagal disimpan.');
            }

            return $event->status === WebhookOutbox::PROCESSING ? $event : null;
        });
    }

    private function maxAttempts(): int
    {
        return max(1, min(10, (int) config('services.hermes_webhook.max_attempts', 5)));
    }

    private function retryDelay(int $attempt, int $retryAfter): int
    {
        $cap = max(1, min(3600, (int) config('services.hermes_webhook.backoff_cap', 900)));
        $base = max(1, min($cap, (int) config('services.hermes_webhook.backoff_base', 30)));
        $delay = min($cap, $base * (2 ** ($attempt - 1)));

        return min($cap, max($retryAfter, $delay + random_int(0, max(1, intdiv($delay, 5)))));
    }
}
