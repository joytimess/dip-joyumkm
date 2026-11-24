<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'six_factor_evaluations';

    private const PERIOD_TIMEZONE = 'Asia/Jakarta';

    /**
     * Mengisi period_month untuk Final yang sudah ada dengan menghitung di PHP, bukan fungsi tanggal MySQL.
     * Zona sesi MySQL tidak diatur Laravel, sehingga fungsi SQL atas TIMESTAMP bisa bergeser.
     */
    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->char('period_month', 7)->nullable()->after('finalized_at');
            $table->index(['umkm_id', 'period_month', 'finalized_at'], 'six_factor_umkm_period_index');
        });

        DB::table(self::TABLE)->where('status', 'final')->whereNull('period_month')
            ->orderBy('id')
            ->chunkById(200, function ($evaluations): void {
                foreach ($evaluations as $evaluation) {
                    $periodMonth = CarbonImmutable::parse((string) $evaluation->finalized_at, 'UTC')
                        ->setTimezone(self::PERIOD_TIMEZONE)
                        ->format('Y-m');

                    DB::table(self::TABLE)->where('id', $evaluation->id)->update(['period_month' => $periodMonth]);
                }
            });

        DB::statement(<<<'SQL'
            ALTER TABLE six_factor_evaluations
                ADD CONSTRAINT six_factor_period_format_check
                    CHECK (period_month IS NULL OR period_month REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                ADD CONSTRAINT six_factor_period_status_check
                    CHECK ((status = 'final') = (period_month IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE six_factor_evaluations DROP CHECK six_factor_period_status_check');
        DB::statement('ALTER TABLE six_factor_evaluations DROP CHECK six_factor_period_format_check');

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex('six_factor_umkm_period_index');
            $table->dropColumn('period_month');
        });
    }
};
