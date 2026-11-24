<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id');
            $table->string('channel', 16);
            // Sentinel '' untuk kanal artifact. Kolom NOT NULL agar unique index tidak meloloskan duplikat NULL di MySQL.
            $table->string('recipient_fingerprint', 64)->default('');
            $table->char('artifact_set_hash', 64);
            $table->char('idempotency_key', 64);
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('attempt_no')->default(0);
            $table->string('provider_message_id', 191)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'channel', 'recipient_fingerprint'], 'intelligence_deliveries_recipient_unique');
            $table->unique('idempotency_key', 'intelligence_deliveries_idempotency_unique');
            $table->index(['status', 'updated_at'], 'intelligence_deliveries_status_index');
            $table->foreign('event_id')->references('event_id')->on('intelligence_reports')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE intelligence_deliveries
                ADD CONSTRAINT intelligence_deliveries_channel_check
                    CHECK (channel IN ('artifact', 'email')),
                ADD CONSTRAINT intelligence_deliveries_status_check
                    CHECK (status IN ('queued', 'sent', 'failed_retryable', 'failed_permanent', 'skipped_ineligible')),
                ADD CONSTRAINT intelligence_deliveries_recipient_check
                    CHECK (
                        (channel = 'artifact' AND recipient_fingerprint = '')
                        OR (channel = 'email' AND REGEXP_LIKE(recipient_fingerprint, '^[0-9a-f]{64}$', 'c'))
                        OR (channel = 'email' AND recipient_fingerprint = '' AND status = 'skipped_ineligible')
                    ),
                ADD CONSTRAINT intelligence_deliveries_channel_status_check
                    CHECK (channel <> 'artifact' OR status <> 'skipped_ineligible'),
                ADD CONSTRAINT intelligence_deliveries_hash_check
                    CHECK (
                        REGEXP_LIKE(artifact_set_hash, '^[0-9a-f]{64}$', 'c')
                        AND REGEXP_LIKE(idempotency_key, '^[0-9a-f]{64}$', 'c')
                    ),
                ADD CONSTRAINT intelligence_deliveries_sent_check
                    CHECK (status <> 'sent' OR sent_at IS NOT NULL),
                ADD CONSTRAINT intelligence_deliveries_error_check
                    CHECK (status NOT IN ('failed_retryable', 'failed_permanent', 'skipped_ineligible') OR error_code IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_deliveries');
    }
};
