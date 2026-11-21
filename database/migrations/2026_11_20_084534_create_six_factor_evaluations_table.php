<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE six_factor_evaluations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                umkm_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(9) NOT NULL DEFAULT 'draft',
                sumber_daya TINYINT UNSIGNED NULL,
                kepemimpinan TINYINT UNSIGNED NULL,
                lingkungan_kerja TINYINT UNSIGNED NULL,
                nilai_organisasi TINYINT UNSIGNED NULL,
                stabilitas TINYINT UNSIGNED NULL,
                kinerja TINYINT UNSIGNED NULL,
                total_score TINYINT UNSIGNED NULL,
                category VARCHAR(20) NULL,
                finalized_at TIMESTAMP NULL,
                cancelled_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                active_draft_umkm_id BIGINT UNSIGNED
                    GENERATED ALWAYS AS (CASE WHEN status = 'draft' THEN umkm_id ELSE NULL END) STORED,
                UNIQUE KEY six_factor_active_draft_unique (active_draft_umkm_id),
                KEY six_factor_umkm_status_id_index (umkm_id, status, id),
                CONSTRAINT six_factor_umkm_foreign FOREIGN KEY (umkm_id) REFERENCES umkms (id) ON DELETE RESTRICT,
                CONSTRAINT six_factor_scores_check CHECK (
                    (sumber_daya IS NULL OR sumber_daya BETWEEN 1 AND 5)
                    AND (kepemimpinan IS NULL OR kepemimpinan BETWEEN 1 AND 5)
                    AND (lingkungan_kerja IS NULL OR lingkungan_kerja BETWEEN 1 AND 5)
                    AND (nilai_organisasi IS NULL OR nilai_organisasi BETWEEN 1 AND 5)
                    AND (stabilitas IS NULL OR stabilitas BETWEEN 1 AND 5)
                    AND (kinerja IS NULL OR kinerja BETWEEN 1 AND 5)
                ),
                CONSTRAINT six_factor_status_check CHECK (
                    (status = 'draft' AND total_score IS NULL AND category IS NULL
                        AND finalized_at IS NULL AND cancelled_at IS NULL)
                    OR (status = 'final'
                        AND sumber_daya IS NOT NULL AND kepemimpinan IS NOT NULL AND lingkungan_kerja IS NOT NULL
                        AND nilai_organisasi IS NOT NULL AND stabilitas IS NOT NULL AND kinerja IS NOT NULL
                        AND total_score IS NOT NULL AND category IS NOT NULL
                        AND finalized_at IS NOT NULL AND cancelled_at IS NULL
                        AND total_score = sumber_daya + kepemimpinan + lingkungan_kerja + nilai_organisasi + stabilitas + kinerja
                        AND category = CASE
                            WHEN total_score BETWEEN 6 AND 12 THEN 'Kurang Sehat'
                            WHEN total_score BETWEEN 13 AND 18 THEN 'Cukup Sehat'
                            WHEN total_score BETWEEN 19 AND 24 THEN 'Sehat'
                            WHEN total_score BETWEEN 25 AND 30 THEN 'Sangat Sehat'
                        END)
                    OR (status = 'cancelled' AND total_score IS NULL AND category IS NULL
                        AND finalized_at IS NULL AND cancelled_at IS NOT NULL)
                )
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_bin
            SQL);
    }

    public function down(): void
    {
        // Tahan penulis sampai pemeriksaan tabel kosong dan DROP selesai.
        DB::statement('LOCK TABLES six_factor_evaluations WRITE');

        try {
            if (DB::table('six_factor_evaluations')->useWritePdo()->exists()) {
                throw new RuntimeException('Rollback ditolak karena tabel evaluasi enam faktor masih berisi data.');
            }

            DB::statement('DROP TABLE six_factor_evaluations');
        } finally {
            DB::statement('UNLOCK TABLES');
        }
    }
};
