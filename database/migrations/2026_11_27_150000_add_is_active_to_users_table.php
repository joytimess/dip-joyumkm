<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status akun aditif. Semua akun lama otomatis aktif (default kolom); kata sandi dan timestamp tidak disentuh.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('role');
            });
        }
    }

    /**
     * Pemulihan aman: hanya melepas kolom status. Akun yang sempat dinonaktifkan menjadi aktif kembali.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
