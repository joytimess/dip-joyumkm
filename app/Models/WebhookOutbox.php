<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WebhookOutbox extends Model
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const RETRY_WAIT = 'retry_wait';

    public const DELIVERED = 'delivered';

    public const DEAD = 'dead';

    protected $table = 'webhook_outbox';

    protected $hidden = ['payload', 'lease_token'];

    protected function casts(): array
    {
        return [
            'aggregate_id' => 'integer',
            'event_version' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'queued_job_id' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'last_http_status' => 'integer',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    #[Scope]
    protected function due(Builder $query): Builder
    {
        $now = now();

        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $due) => $due->whereIn('status', [self::PENDING, self::RETRY_WAIT])
                ->where('next_attempt_at', '<=', $now))
            ->orWhere(fn (Builder $stale) => $stale->where('status', self::PROCESSING)
                ->where('lease_expires_at', '<=', $now)));
    }
}
