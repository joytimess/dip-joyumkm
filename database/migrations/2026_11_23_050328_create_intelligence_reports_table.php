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
        Schema::create('intelligence_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('evaluation_id')->unique();
            $table->unsignedBigInteger('umkm_id');
            $table->unsignedSmallInteger('source_event_version');
            $table->unsignedSmallInteger('schema_version');
            $table->string('prompt_version', 64);
            $table->string('knowledge_version', 96);
            $table->string('methodology_status', 16);
            $table->string('provider', 96);
            $table->string('model', 96);
            $table->json('report');
            $table->char('report_hash', 64);
            $table->timestamp('generated_at');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['umkm_id', 'generated_at', 'id'], 'intelligence_reports_umkm_latest_index');
            $table->foreign('event_id')->references('event_id')->on('webhook_outbox')->restrictOnDelete();
            $table->foreign('evaluation_id')->references('id')->on('six_factor_evaluations')->restrictOnDelete();
            $table->foreign('umkm_id')->references('id')->on('umkms')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE intelligence_reports
                ADD CONSTRAINT intelligence_reports_methodology_check
                    CHECK (methodology_status IN ('complete', 'partial'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_reports');
    }
};
