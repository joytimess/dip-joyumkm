<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverHermesWebhook;
use App\Services\HermesWebhookOutbox;
use App\Services\HermesWebhookSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Satu siklus terbatas untuk cPanel Cron: scanner outbox, lalu worker khusus antrean hermes-webhooks.
 *
 * Siklus tidak membuka rollout. Saat pengiriman nonaktif, siklus berhenti tanpa menyentuh outbox atau antrean.
 * Kunci file mencegah dua siklus berjalan bersamaan; siklus yang melewati batas waktu tidak mempercepat antrean.
 */
#[Signature('hermes:cron {--budget=240 : Batas total detik satu siklus, 30 sampai 330}')]
#[Description('Jalankan scanner dan worker Hermes secara terbatas untuk cron shared hosting')]
class HermesWebhookCron extends Command
{
    private const LOCK_PATH = 'app/hermes-webhook-cron.lock';

    private const MIN_BUDGET_SECONDS = 30;

    /** Di bawah interval cron 6 menit (360 detik) agar siklus tidak menumpuk. */
    private const MAX_BUDGET_SECONDS = 330;

    private const WORKER_JOB_TIMEOUT_SECONDS = 20;

    private const WORKER_MEMORY_MB = 96;

    public function handle(HermesWebhookSender $sender, HermesWebhookOutbox $outbox): int
    {
        $lock = $this->acquireLock();
        if ($lock === null) {
            $this->info('Siklus Hermes sebelumnya masih berjalan. Siklus ini dilewati.');

            return self::SUCCESS;
        }

        try {
            return $this->runCycle($sender, $outbox);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource|null */
    private function acquireLock()
    {
        $handle = fopen(storage_path(self::LOCK_PATH), 'c');
        if ($handle === false) {
            throw new RuntimeException('Kunci siklus Hermes tidak dapat dibuka.');
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function runCycle(HermesWebhookSender $sender, HermesWebhookOutbox $outbox): int
    {
        $startedAt = hrtime(true);
        $issue = $sender->configurationIssue();
        if ($issue !== null) {
            $this->info('Pengiriman webhook ditunda ('.$issue.').');
            if ($issue === 'disabled') {
                return self::SUCCESS;
            }

            Log::warning('Hermes webhook cron paused.', ['code' => $issue]);

            return self::FAILURE;
        }

        $counts = $outbox->scan();
        $this->info(sprintf('Job baru %d, dispatch gagal %d.', $counts['queued'], $counts['failed']));

        $remainingSeconds = $this->budgetSeconds() - (int) ceil((hrtime(true) - $startedAt) / 1_000_000_000);
        if ($remainingSeconds < 1) {
            $this->warn('Batas waktu siklus habis sebelum worker dijalankan. Sisa antrean menunggu siklus berikutnya.');

            return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $workerExit = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => DeliverHermesWebhook::QUEUE,
            '--stop-when-empty' => true,
            '--max-time' => $remainingSeconds,
            '--tries' => 1,
            '--timeout' => self::WORKER_JOB_TIMEOUT_SECONDS,
            '--memory' => self::WORKER_MEMORY_MB,
        ]);

        if ($workerExit !== self::SUCCESS) {
            Log::warning('Hermes webhook cron worker exited with failure.', ['exit_code' => $workerExit]);
        }

        return $counts['failed'] === 0 && $workerExit === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function budgetSeconds(): int
    {
        return max(self::MIN_BUDGET_SECONDS, min(self::MAX_BUDGET_SECONDS, (int) $this->option('budget')));
    }
}
