<?php

use App\Models\WebhookOutbox;
use App\Services\HermesWebhookOutbox;
use App\Services\HermesWebhookSender;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('hermes:webhook-scan', function (HermesWebhookSender $sender, HermesWebhookOutbox $outbox): int {
    $issue = $sender->configurationIssue();
    if ($issue !== null) {
        $this->info('Pengiriman webhook ditunda ('.$issue.').');
        if ($issue !== 'disabled') {
            Log::warning('Hermes webhook delivery paused.', ['code' => $issue]);
        }

        return $issue === 'disabled' ? 0 : 1;
    }

    $counts = $outbox->scan();
    $this->info(sprintf('Job baru %d, dispatch gagal %d.', $counts['queued'], $counts['failed']));

    return $counts['failed'] === 0 ? 0 : 1;
})->purpose('Antrekan event Hermes yang siap dikirim atau dipulihkan');

Artisan::command('hermes:webhook-status {event_id?}', function (HermesWebhookSender $sender): int {
    $this->info('Konfigurasi pengiriman: '.($sender->configurationIssue() ?? 'ready'));
    $eventId = $this->argument('event_id');
    if ($eventId === null) {
        $counts = WebhookOutbox::query()->selectRaw('status, COUNT(*) as total, MIN(created_at) as oldest')
            ->groupBy('status')->orderBy('status')->get();
        $this->table(['Status', 'Jumlah', 'Event tertua'], $counts->toArray());

        return 0;
    }

    $fields = ['event_id', 'status', 'attempts', 'next_attempt_at', 'lease_expires_at',
        'last_http_status', 'last_error_code', 'last_error_summary', 'created_at', 'updated_at', 'delivered_at'];
    $event = Str::isUuid($eventId) ? WebhookOutbox::query()->where('event_id', $eventId)->first($fields) : null;
    if ($event === null) {
        $this->error('Event tidak ditemukan.');

        return 1;
    }

    $this->table($fields, [array_values($event->toArray())]);

    return 0;
})->purpose('Lihat jumlah dan status event Hermes');

Artisan::command('hermes:webhook-requeue {event_id}', function (HermesWebhookOutbox $outbox): int {
    $eventId = $this->argument('event_id');
    if (! Str::isUuid($eventId) || ! $outbox->requeue($eventId)) {
        $this->error('Hanya event berstatus dead yang dapat diantrekan ulang.');

        return 1;
    }

    $this->info('Event dikembalikan ke pending untuk pengiriman ulang.');

    return 0;
})->purpose('Pulihkan satu event Hermes dead menggunakan UUID yang sama');

Schedule::command('hermes:webhook-scan')->everyMinute();
