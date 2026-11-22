<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_outbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 64);
            $table->unsignedSmallInteger('event_version');
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedBigInteger('queued_job_id')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error_code', 32)->nullable();
            $table->string('last_error_summary', 120)->nullable();
            $table->timestamps();
            $table->timestamp('delivered_at')->nullable();

            $table->unique(['event_type', 'aggregate_id']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_outbox');
    }
};
