<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak audit pemindahan kepemilikan UMKM (append-only). Hanya struktur: tidak ada transfer pada P2.
     * Trigger menolak UPDATE/DELETE. FK restrict agar riwayat tidak hilang diam-diam.
     */
    public function up(): void
    {
        Schema::create('umkm_ownership_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_id')->constrained('umkms')->restrictOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('transferred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['umkm_id', 'transferred_at'], 'umkm_ownership_transfers_umkm_time_index');
        });

        DB::statement('ALTER TABLE umkm_ownership_transfers ADD CONSTRAINT umkm_ownership_transfers_distinct_check CHECK (from_user_id <> to_user_id)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER umkm_ownership_transfers_before_update_immutable
            BEFORE UPDATE ON umkm_ownership_transfers
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Riwayat transfer kepemilikan bersifat append-only.'
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER umkm_ownership_transfers_before_delete_immutable
            BEFORE DELETE ON umkm_ownership_transfers
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Riwayat transfer kepemilikan bersifat append-only.'
            SQL);
    }

    /**
     * Tabel baru dan kosong pada P2; aman dihapus hanya selama belum ada riwayat (setelah P4 jangan di-rollback).
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS umkm_ownership_transfers_before_delete_immutable');
        DB::unprepared('DROP TRIGGER IF EXISTS umkm_ownership_transfers_before_update_immutable');
        Schema::dropIfExists('umkm_ownership_transfers');
    }
};
