<?php

declare(strict_types=1);

use App\Services\DatabaseEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4-03: manifest artefak yang DIHARAPKAN, disimpan saat callback sebelum biner diterima (aditif, immutable).
 * Juga melonggarkan trigger status artefak agar partial -> complete diizinkan; semua penjaga lain dipertahankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_artifact_manifests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('report_id');
            $table->uuid('event_id');
            $table->json('artifacts');
            $table->char('transport_artifact_set_hash', 64);
            $table->timestamp('created_at');

            $table->unique('report_id', 'intelligence_artifact_manifests_report_unique');
            $table->unique('event_id', 'intelligence_artifact_manifests_event_unique');
            $table->foreign(['report_id', 'event_id'], 'intelligence_artifact_manifests_report_event_foreign')
                ->references(['id', 'event_id'])
                ->on('intelligence_reports')
                ->restrictOnDelete();
        });

        $transportHashCheck = DatabaseEngine::hexDigestCheck(DatabaseEngine::isMariaDb(DB::connection()), 'transport_artifact_set_hash', 64);

        DB::statement(<<<SQL
            ALTER TABLE intelligence_artifact_manifests
                ADD CONSTRAINT intelligence_artifact_manifests_hash_check
                    CHECK ({$transportHashCheck})
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER intelligence_artifact_manifests_before_update_immutable
            BEFORE UPDATE ON intelligence_artifact_manifests
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Manifest artefak bersifat immutable.'
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER intelligence_artifact_manifests_before_delete_immutable
            BEFORE DELETE ON intelligence_artifact_manifests
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Manifest artefak tidak dapat dihapus.'
            SQL);

        $this->replaceReportTrigger(allowPartialToComplete: true);
    }

    public function down(): void
    {
        $this->replaceReportTrigger(allowPartialToComplete: false);
        Schema::dropIfExists('intelligence_artifact_manifests');
    }

    private function replaceReportTrigger(bool $allowPartialToComplete): void
    {
        $transition = $allowPartialToComplete
            ? "OLD.artifact_status <> 'pending' AND NEW.artifact_status <> OLD.artifact_status AND NOT (OLD.artifact_status = 'partial' AND NEW.artifact_status = 'complete')"
            : "OLD.artifact_status <> 'pending' AND NEW.artifact_status <> OLD.artifact_status";

        DB::unprepared('DROP TRIGGER IF EXISTS intelligence_reports_before_update_immutable');
        DB::unprepared(<<<SQL
            CREATE TRIGGER intelligence_reports_before_update_immutable
            BEFORE UPDATE ON intelligence_reports
            FOR EACH ROW
            BEGIN
                IF NOT (
                    NEW.id <=> OLD.id
                    AND NEW.event_id <=> OLD.event_id
                    AND NEW.evaluation_id <=> OLD.evaluation_id
                    AND NEW.umkm_id <=> OLD.umkm_id
                    AND NEW.source_event_version <=> OLD.source_event_version
                    AND NEW.schema_version <=> OLD.schema_version
                    AND NEW.prompt_version <=> OLD.prompt_version
                    AND NEW.knowledge_version <=> OLD.knowledge_version
                    AND NEW.methodology_status <=> OLD.methodology_status
                    AND NEW.provider <=> OLD.provider
                    AND NEW.model <=> OLD.model
                    AND NEW.report <=> OLD.report
                    AND NEW.report_hash <=> OLD.report_hash
                    AND NEW.generated_at <=> OLD.generated_at
                    AND NEW.received_at <=> OLD.received_at
                    AND NEW.created_at <=> OLD.created_at
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Laporan intelligence bersifat immutable.';
                END IF;

                IF {$transition} THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status artefak yang sudah final tidak dapat diubah.';
                END IF;
            END
            SQL);
    }
};
