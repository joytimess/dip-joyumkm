<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\HermesWebhookOutbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverHermesWebhook implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'hermes-webhooks';

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public readonly int $outboxId)
    {
        $this->beforeCommit();
    }

    public function handle(HermesWebhookOutbox $outbox): void
    {
        $outbox->deliver($this->outboxId);
    }
}
