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
        Schema::table('intelligence_reports', function (Blueprint $table): void {
            $table->string('artifact_status', 16)->default('pending')->after('methodology_status');
            // Target composite FK artefak: (report_id, event_id) harus konsisten dengan laporan induknya.
            $table->unique(['id', 'event_id'], 'intelligence_reports_id_event_id_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE intelligence_reports
                ADD CONSTRAINT intelligence_reports_artifact_status_check
                    CHECK (artifact_status IN ('pending', 'complete', 'partial'))
            SQL);

        // Isi report JSON dan identitas laporan tidak boleh berubah, bahkan lewat SQL mentah.
        // Hanya artifact_status yang boleh berpindah dari pending ke complete atau partial.
        DB::unprepared(<<<'SQL'
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

                IF OLD.artifact_status <> 'pending' AND NEW.artifact_status <> OLD.artifact_status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status artefak yang sudah final tidak dapat diubah.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER intelligence_reports_before_delete_immutable
            BEFORE DELETE ON intelligence_reports
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Laporan intelligence tidak dapat dihapus.'
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS intelligence_reports_before_delete_immutable');
        DB::unprepared('DROP TRIGGER IF EXISTS intelligence_reports_before_update_immutable');

        DB::statement('ALTER TABLE intelligence_reports DROP CONSTRAINT intelligence_reports_artifact_status_check');

        Schema::table('intelligence_reports', function (Blueprint $table): void {
            $table->dropUnique('intelligence_reports_id_event_id_unique');
            $table->dropColumn('artifact_status');
        });
    }
};
