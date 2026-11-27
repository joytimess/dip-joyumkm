<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peran akun. Baris lama otomatis menjadi 'user' (default kolom); tidak ada data pengguna yang diubah.
     * Pemetaan Analyst/Superadmin dilakukan terpisah oleh perintah artisan yang idempoten.
     * Tanpa indeks: tabel users kecil dan peran tidak dipakai sebagai filter pencarian.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role', 20)->default('user')->after('email');
            });
        }

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('superadmin', 'analyst', 'user'))");
    }

    /**
     * Pemulihan aman: hanya melepas constraint lalu kolom peran. Tidak menyentuh data pengguna lain.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
