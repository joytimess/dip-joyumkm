<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->timestamps();
        });
        Schema::create('factors', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name', 120);
            $table->text('description');
            $table->string('color', 7);
            $table->unsignedTinyInteger('scale_min');
            $table->unsignedTinyInteger('scale_max');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });
        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('factor_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->text('text');
            $table->enum('input_type', ['labeled_radio', 'likert']);
            $table->json('options');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['factor_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
        Schema::dropIfExists('factors');
        Schema::dropIfExists('sectors');
    }
};
