<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keanggotaan cohort sintetis yang tidak bergantung pada pemilik (umkms.user_id).
     * Satu UMKM paling banyak satu cohort (umkm_id unik). synthetic_key adalah identitas stabil dari generator
     * (nama UMKM, unik per pemilik) sehingga identitas tetap sama setelah kepemilikan berpindah.
     * Baris hanya ditulis oleh perintah backfill; data cohort lama tidak diubah.
     */
    public function up(): void
    {
        Schema::create('analytics_dataset_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analytics_dataset_cohort_id')->constrained('analytics_dataset_cohorts')->restrictOnDelete();
            $table->foreignId('umkm_id')->unique()->constrained('umkms')->cascadeOnDelete();
            $table->string('synthetic_key', 120);
            $table->timestamps();
            $table->unique(['analytics_dataset_cohort_id', 'synthetic_key'], 'analytics_dataset_members_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_dataset_members');
    }
};
