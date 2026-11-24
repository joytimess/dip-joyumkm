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
        Schema::create('intelligence_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('report_id');
            $table->uuid('event_id');
            $table->string('kind', 8);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 255);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('created_at');

            $table->unique(['report_id', 'kind'], 'intelligence_artifacts_report_kind_unique');
            $table->unique(['storage_disk', 'storage_path'], 'intelligence_artifacts_storage_unique');
            $table->index(['report_id', 'event_id'], 'intelligence_artifacts_report_event_index');
            $table->foreign(['report_id', 'event_id'], 'intelligence_artifacts_report_event_foreign')
                ->references(['id', 'event_id'])
                ->on('intelligence_reports')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE intelligence_artifacts
                ADD CONSTRAINT intelligence_artifacts_kind_check
                    CHECK (kind IN ('pdf', 'xlsx')),
                ADD CONSTRAINT intelligence_artifacts_mime_check
                    CHECK (
                        (kind = 'pdf' AND mime = 'application/pdf')
                        OR (kind = 'xlsx' AND mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                    ),
                ADD CONSTRAINT intelligence_artifacts_size_check
                    CHECK (size_bytes BETWEEN 1 AND 10485760),
                ADD CONSTRAINT intelligence_artifacts_sha256_check
                    CHECK (REGEXP_LIKE(sha256, '^[0-9a-f]{64}$', 'c')),
                ADD CONSTRAINT intelligence_artifacts_path_check
                    CHECK (storage_path <> '')
            SQL);

        // Artefak bersifat append-only: upload ulang dengan SHA-256 berbeda harus ditolak sebagai konflik.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER intelligence_artifacts_before_update_immutable
            BEFORE UPDATE ON intelligence_artifacts
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Artefak intelligence bersifat immutable.'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER intelligence_artifacts_before_delete_immutable
            BEFORE DELETE ON intelligence_artifacts
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Artefak intelligence tidak dapat dihapus.'
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_artifacts');
    }
};
