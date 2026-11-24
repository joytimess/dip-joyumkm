-- =====================================================================
-- P2 — Koreksi offset timezone pada timestamp historis (JoyUMKM, MySQL 8.4)
-- Dibangkitkan dari information_schema: 35 kolom TIMESTAMP pada 12 tabel.
-- Dikecualikan: failed_jobs.failed_at (DEFAULT CURRENT_TIMESTAMP, dibuat DB dengan benar).
--
-- JANGAN dijalankan tanpa persetujuan owner, backup mysqldump --tz-utc, dan php artisan down.
-- Jalankan SEBELUM migrasi P2 (2026_11_23_153536 s.d. 153541) karena trigger immutable
-- intelligence_reports memblokir UPDATE kolom waktunya.
-- Seluruh bagian 1 sampai 7 harus dijalankan dalam SATU sesi mysql (variabel @offset_s).
-- Bagian 0 (DDL) boleh dijalankan lebih dulu. Bagian 2 sampai 6 berada dalam satu transaksi.
-- Ganti run_key 'p2-utc-2026-11' hanya jika owner memutuskan run baru.
-- =====================================================================

-- 0. DDL pendukung (bukan kolom TIMESTAMP, aman dijalankan ulang)
CREATE TABLE IF NOT EXISTS timezone_offset_corrections (
    run_key VARCHAR(64) NOT NULL,
    offset_seconds INT NOT NULL,
    applied_at DATETIME(0) NOT NULL,
    PRIMARY KEY (run_key)
);

CREATE TABLE IF NOT EXISTS timezone_offset_anchors (
    run_key VARCHAR(64) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    row_id BIGINT UNSIGNED NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    expected_epoch BIGINT NOT NULL,
    PRIMARY KEY (run_key, table_name, row_id, column_name)
);

CREATE TABLE IF NOT EXISTS timezone_offset_snapshot (
    run_key VARCHAR(64) NOT NULL,
    phase VARCHAR(8) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    non_null BIGINT UNSIGNED NOT NULL,
    epoch_sum DECIMAL(24,0) NOT NULL,
    PRIMARY KEY (run_key, phase, table_name, column_name)
);

-- 1. Preflight (baca saja). Harapan di komentar; hentikan jika tidak sesuai.
SET time_zone = 'SYSTEM';
SET @offset_s = TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW());
-- Harapan: @offset_s = 25200 (WIB, UTC+7). Jika bukan, JANGAN lanjut.
SELECT @offset_s AS measured_offset_s, @@global.time_zone AS global_tz, @@session.time_zone AS session_tz;
-- Harapan: global_tz = SYSTEM dan session_tz = SYSTEM (kondisi historis penulisan).
SELECT COUNT(*) AS triggers_on_db FROM information_schema.triggers WHERE trigger_schema = DATABASE();
-- Harapan: 0 (koreksi dijalankan sebelum P2).
SELECT COUNT(*) AS run_already_applied FROM timezone_offset_corrections WHERE run_key = 'p2-utc-2026-11';
-- Harapan: 0. Jika 1, koreksi sudah pernah dijalankan: STOP (cegah double correction).
SELECT COUNT(*) AS anchors_loaded FROM timezone_offset_anchors WHERE run_key = 'p2-utc-2026-11';
-- Harapan: >= 1. Anchor diisi dari payload webhook_outbox.occurred_at (lihat dokumen).
SELECT COUNT(*) AS anchors_not_shifted_as_expected
FROM timezone_offset_anchors a
JOIN six_factor_evaluations s ON s.id = a.row_id
WHERE a.run_key = 'p2-utc-2026-11' AND a.table_name = 'six_factor_evaluations' AND a.column_name = 'finalized_at'
  AND UNIX_TIMESTAMP(s.finalized_at) - a.expected_epoch <> -@offset_s;
-- Harapan: 0. Jika ada yang bernilai 0 selisihnya, nilai itu sudah dikoreksi: STOP.

-- 2. Mulai transaksi. Snapshot, ledger, dan seluruh UPDATE berada dalam satu transaksi,
--    sehingga kegagalan di tengah tidak menyisakan data setengah dikoreksi.
START TRANSACTION;

-- 2a. Snapshot sebelum koreksi (bukti dan dasar verifikasi delta). Epoch tidak bergantung sesi.
INSERT INTO timezone_offset_snapshot (run_key, phase, table_name, column_name, non_null, epoch_sum)
    SELECT 'p2-utc-2026-11', 'before', 'evaluation_answers', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'evaluation_answers', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'evaluations', 'submitted_at', COUNT(`submitted_at`), COALESCE(SUM(UNIX_TIMESTAMP(`submitted_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'factors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `factors`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'factors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `factors`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'intelligence_reports', 'generated_at', COUNT(`generated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`generated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'intelligence_reports', 'received_at', COUNT(`received_at`), COALESCE(SUM(UNIX_TIMESTAMP(`received_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'intelligence_reports', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'intelligence_reports', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'password_reset_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `password_reset_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'personal_access_tokens', 'last_used_at', COUNT(`last_used_at`), COALESCE(SUM(UNIX_TIMESTAMP(`last_used_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'personal_access_tokens', 'expires_at', COUNT(`expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`expires_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'personal_access_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'personal_access_tokens', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'questions', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `questions`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'questions', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `questions`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'sectors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'sectors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'six_factor_evaluations', 'finalized_at', COUNT(`finalized_at`), COALESCE(SUM(UNIX_TIMESTAMP(`finalized_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'six_factor_evaluations', 'cancelled_at', COUNT(`cancelled_at`), COALESCE(SUM(UNIX_TIMESTAMP(`cancelled_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'six_factor_evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'six_factor_evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'umkms', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'umkms', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'umkms', 'wa_checked_at', COUNT(`wa_checked_at`), COALESCE(SUM(UNIX_TIMESTAMP(`wa_checked_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'users', 'email_verified_at', COUNT(`email_verified_at`), COALESCE(SUM(UNIX_TIMESTAMP(`email_verified_at`)), 0) FROM `users`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'users', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `users`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'users', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `users`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'webhook_outbox', 'next_attempt_at', COUNT(`next_attempt_at`), COALESCE(SUM(UNIX_TIMESTAMP(`next_attempt_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'webhook_outbox', 'lease_expires_at', COUNT(`lease_expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`lease_expires_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'webhook_outbox', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'webhook_outbox', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'before', 'webhook_outbox', 'delivered_at', COUNT(`delivered_at`), COALESCE(SUM(UNIX_TIMESTAMP(`delivered_at`)), 0) FROM `webhook_outbox`;

-- 3. Koreksi. Sesi UTC hanya untuk blok ini.
SET time_zone = '+00:00';

INSERT INTO timezone_offset_corrections (run_key, offset_seconds, applied_at)
VALUES ('p2-utc-2026-11', @offset_s, UTC_TIMESTAMP());

UPDATE `evaluation_answers` SET
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `evaluations` SET
    `submitted_at` = `submitted_at` + INTERVAL @offset_s SECOND,
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `factors` SET
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `intelligence_reports` SET
    `generated_at` = `generated_at` + INTERVAL @offset_s SECOND,
    `received_at` = `received_at` + INTERVAL @offset_s SECOND,
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `password_reset_tokens` SET
    `created_at` = `created_at` + INTERVAL @offset_s SECOND;

UPDATE `personal_access_tokens` SET
    `last_used_at` = `last_used_at` + INTERVAL @offset_s SECOND,
    `expires_at` = `expires_at` + INTERVAL @offset_s SECOND,
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `questions` SET
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `sectors` SET
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `six_factor_evaluations` SET
    `finalized_at` = `finalized_at` + INTERVAL @offset_s SECOND,
    `cancelled_at` = `cancelled_at` + INTERVAL @offset_s SECOND,
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `umkms` SET
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND,
    `wa_checked_at` = `wa_checked_at` + INTERVAL @offset_s SECOND;

UPDATE `users` SET
    `email_verified_at` = `email_verified_at` + INTERVAL @offset_s SECOND,
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND;

UPDATE `webhook_outbox` SET
    `next_attempt_at` = `next_attempt_at` + INTERVAL @offset_s SECOND,
    `lease_expires_at` = `lease_expires_at` + INTERVAL @offset_s SECOND,
    `created_at` = `created_at` + INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL @offset_s SECOND,
    `delivered_at` = `delivered_at` + INTERVAL @offset_s SECOND;

-- 4. Snapshot sesudah koreksi
INSERT INTO timezone_offset_snapshot (run_key, phase, table_name, column_name, non_null, epoch_sum)
    SELECT 'p2-utc-2026-11', 'after', 'evaluation_answers', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'evaluation_answers', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'evaluations', 'submitted_at', COUNT(`submitted_at`), COALESCE(SUM(UNIX_TIMESTAMP(`submitted_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'factors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `factors`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'factors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `factors`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'intelligence_reports', 'generated_at', COUNT(`generated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`generated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'intelligence_reports', 'received_at', COUNT(`received_at`), COALESCE(SUM(UNIX_TIMESTAMP(`received_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'intelligence_reports', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'intelligence_reports', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'password_reset_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `password_reset_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'personal_access_tokens', 'last_used_at', COUNT(`last_used_at`), COALESCE(SUM(UNIX_TIMESTAMP(`last_used_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'personal_access_tokens', 'expires_at', COUNT(`expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`expires_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'personal_access_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'personal_access_tokens', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'questions', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `questions`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'questions', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `questions`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'sectors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'sectors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'six_factor_evaluations', 'finalized_at', COUNT(`finalized_at`), COALESCE(SUM(UNIX_TIMESTAMP(`finalized_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'six_factor_evaluations', 'cancelled_at', COUNT(`cancelled_at`), COALESCE(SUM(UNIX_TIMESTAMP(`cancelled_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'six_factor_evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'six_factor_evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'umkms', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'umkms', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'umkms', 'wa_checked_at', COUNT(`wa_checked_at`), COALESCE(SUM(UNIX_TIMESTAMP(`wa_checked_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'users', 'email_verified_at', COUNT(`email_verified_at`), COALESCE(SUM(UNIX_TIMESTAMP(`email_verified_at`)), 0) FROM `users`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'users', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `users`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'users', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `users`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'webhook_outbox', 'next_attempt_at', COUNT(`next_attempt_at`), COALESCE(SUM(UNIX_TIMESTAMP(`next_attempt_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'webhook_outbox', 'lease_expires_at', COUNT(`lease_expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`lease_expires_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'webhook_outbox', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'webhook_outbox', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT 'p2-utc-2026-11', 'after', 'webhook_outbox', 'delivered_at', COUNT(`delivered_at`), COALESCE(SUM(UNIX_TIMESTAMP(`delivered_at`)), 0) FROM `webhook_outbox`;

-- 5. Verifikasi. Keduanya harus 0 sebelum COMMIT.
SELECT COUNT(*) AS delta_violations
FROM timezone_offset_snapshot b
JOIN timezone_offset_snapshot a
  ON a.run_key = b.run_key AND a.table_name = b.table_name AND a.column_name = b.column_name AND a.phase = 'after'
WHERE b.run_key = 'p2-utc-2026-11' AND b.phase = 'before'
  AND (a.non_null <> b.non_null OR a.epoch_sum - b.epoch_sum <> @offset_s * b.non_null);

SELECT COUNT(*) AS anchor_violations
FROM timezone_offset_anchors a
JOIN six_factor_evaluations s ON s.id = a.row_id
WHERE a.run_key = 'p2-utc-2026-11' AND a.table_name = 'six_factor_evaluations' AND a.column_name = 'finalized_at'
  AND UNIX_TIMESTAMP(s.finalized_at) <> a.expected_epoch;

-- 6. Keputusan: COMMIT hanya jika kedua verifikasi = 0. Selain itu ROLLBACK.
COMMIT;
-- ROLLBACK;  (gunakan bila ada violation)

-- 7. Kembalikan sesi operator ke zona sistem
SET time_zone = 'SYSTEM';
