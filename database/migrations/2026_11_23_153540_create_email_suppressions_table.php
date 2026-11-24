<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alamat email tidak disimpan mentah; hanya HMAC-nya yang disimpan sebagai identitas suppression.
     */
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->char('email_fingerprint', 64)->unique();
            $table->string('reason', 16);
            $table->string('source', 64);
            $table->timestamp('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE email_suppressions
                ADD CONSTRAINT email_suppressions_reason_check
                    CHECK (reason IN ('bounce', 'complaint', 'unsubscribe', 'manual')),
                ADD CONSTRAINT email_suppressions_fingerprint_check
                    CHECK (REGEXP_LIKE(email_fingerprint, '^[0-9a-f]{64}$', 'c'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
