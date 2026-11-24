<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy UMKM tidak punya persetujuan; kolom sengaja NULL sehingga tidak ada email tanpa consent.
     */
    public function up(): void
    {
        Schema::table('umkms', function (Blueprint $table): void {
            $table->timestamp('notification_consent_at')->nullable();
            $table->string('notification_consent_version', 32)->nullable();
            $table->foreignId('notification_consent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE umkms
                ADD CONSTRAINT umkms_notification_consent_pair_check
                    CHECK ((notification_consent_at IS NULL) = (notification_consent_version IS NULL))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE umkms DROP CHECK umkms_notification_consent_pair_check');

        Schema::table('umkms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('notification_consent_by_user_id');
            $table->dropColumn(['notification_consent_at', 'notification_consent_version']);
        });
    }
};
