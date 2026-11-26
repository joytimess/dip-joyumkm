<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identitas cohort dataset analitik sintetis: versi, periode referensi, dan pemilik akun.
     * Baris ini hanya ditulis oleh AnalyticsDatasetSeeder dalam transaksi yang sama dengan datanya.
     */
    public function up(): void
    {
        Schema::create('analytics_dataset_cohorts', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset_key', 64)->unique();
            $table->string('dataset_version', 16);
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->char('reference_start_month', 7);
            $table->char('reference_end_month', 7);
            $table->unsignedSmallInteger('umkm_count');
            $table->unsignedSmallInteger('three_dimension_count');
            $table->unsignedSmallInteger('six_factor_count');
            $table->unsignedInteger('answer_count');
            $table->char('content_fingerprint', 64);
            $table->timestamp('completed_at');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE analytics_dataset_cohorts
                ADD CONSTRAINT analytics_dataset_cohorts_period_check
                    CHECK (reference_start_month REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'
                        AND reference_end_month REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'
                        AND reference_start_month <= reference_end_month)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_dataset_cohorts');
    }
};
