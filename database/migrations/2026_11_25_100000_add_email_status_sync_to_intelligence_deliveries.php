<?php

declare(strict_types=1);

use App\Services\DatabaseEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mariaDb = DatabaseEngine::isMariaDb(DB::connection());
        $recipientFingerprintCheck = DatabaseEngine::hexDigestCheck($mariaDb, 'recipient_fingerprint', 64);
        // MariaDB menolak CASE atas event_id CHAR(36) tanpa normalisasi (error 1901). RTRIM tidak mengubah UUID.
        $syncedEventSource = $mariaDb ? 'RTRIM(event_id)' : 'event_id';

        DB::statement("ALTER TABLE intelligence_deliveries
            ADD COLUMN email_snapshot JSON NULL,
            ADD COLUMN email_snapshot_sha256 CHAR(64) NULL,
            ADD COLUMN synced_email_event CHAR(36) GENERATED ALWAYS AS
                (CASE WHEN email_snapshot IS NOT NULL THEN {$syncedEventSource} ELSE NULL END) STORED,
            ADD UNIQUE INDEX intelligence_delivery_synced_email_unique (synced_email_event),
            DROP CONSTRAINT intelligence_deliveries_status_check,
            DROP CONSTRAINT intelligence_deliveries_recipient_check,
            ADD CONSTRAINT intelligence_deliveries_status_check CHECK (
                status IN ('queued','sent','failed_retryable','failed_permanent','skipped_ineligible')
                OR (channel = 'email' AND email_snapshot IS NOT NULL AND status IN ('blocked','delivery_unknown'))),
            ADD CONSTRAINT intelligence_deliveries_recipient_check CHECK (
                (channel = 'artifact' AND recipient_fingerprint = '' AND email_snapshot IS NULL)
                OR (channel = 'email' AND {$recipientFingerprintCheck})
                OR (channel = 'email' AND recipient_fingerprint = '' AND
                    (status = 'skipped_ineligible' OR (email_snapshot IS NOT NULL AND status NOT IN ('sent','delivery_unknown')))))");
    }

    public function down(): void
    {
        if (DB::table('intelligence_deliveries')->whereNotNull('email_snapshot')->exists()) {
            throw new RuntimeException('Email status snapshots must be preserved before rollback.');
        }

        $recipientFingerprintCheck = DatabaseEngine::hexDigestCheck(
            DatabaseEngine::isMariaDb(DB::connection()),
            'recipient_fingerprint',
            64,
        );

        DB::statement("ALTER TABLE intelligence_deliveries
            DROP CONSTRAINT intelligence_deliveries_status_check,
            DROP CONSTRAINT intelligence_deliveries_recipient_check,
            DROP INDEX intelligence_delivery_synced_email_unique,
            DROP COLUMN synced_email_event,
            DROP COLUMN email_snapshot,
            DROP COLUMN email_snapshot_sha256,
            ADD CONSTRAINT intelligence_deliveries_status_check CHECK (
                status IN ('queued','sent','failed_retryable','failed_permanent','skipped_ineligible')),
            ADD CONSTRAINT intelligence_deliveries_recipient_check CHECK (
                (channel = 'artifact' AND recipient_fingerprint = '')
                OR (channel = 'email' AND {$recipientFingerprintCheck})
                OR (channel = 'email' AND recipient_fingerprint = '' AND status = 'skipped_ineligible'))");
    }
};
