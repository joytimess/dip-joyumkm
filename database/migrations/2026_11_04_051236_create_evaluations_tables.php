<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['draft', 'final']);
            $table->decimal('ins_score', 7, 4)->nullable();
            $table->decimal('ect_score', 7, 4)->nullable();
            $table->decimal('ops_score', 7, 4)->nullable();
            $table->decimal('average_score', 4, 1)->nullable();
            $table->string('category', 30)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['umkm_id', 'id']);
        });
        Schema::create('evaluation_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->integer('value');
            $table->timestamps();
            $table->unique(['evaluation_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_answers');
        Schema::dropIfExists('evaluations');
    }
};
