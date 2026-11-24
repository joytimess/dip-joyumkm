-- =====================================================================
-- P2 POST-MIGRATION: koreksi offset timezone (JoyUMKM, MySQL 8.4)
-- Pengganti p2-timezone-correction.sql untuk kondisi SETELAH 6 migration P2 dijalankan.
-- File asli TIDAK diubah. Runbook: docs/audit/P2_JoyUMKM_Post_P2_Timezone_Recovery_Runbook.md
--
-- Prasyarat yang diperiksa gerbang (bukan sekadar harapan di komentar):
--   - 6 migration P2 sudah di-rollback: php artisan migrate:rollback --step=6 (G3, G4).
--   - Config koneksi Laravel mysql = '+00:00' di-deploy BERSAMAAN dengan COMMIT ini (lihat runbook).
--
-- Keamanan:
--   - Seluruh koreksi ada di stored procedure dengan EXIT HANDLER: error SQL apa pun
--     memicu ROLLBACK lalu RESIGNAL, sehingga tidak ada partial commit walau mysql client
--     berjalan dengan --force.
--   - Setiap gerbang memakai SIGNAL. COMMIT hanya tercapai bila G1..G6 dan V1..V3 lolos.
--   - Daftar 35 kolom dibaca dari tabel helper dan dicek silang dengan information_schema (G4).
--   - Anchor minimal 6 baris, wajib cocok dengan wall UTC payload dan offset sebelum koreksi (G5).
--
-- JANGAN dijalankan tanpa persetujuan owner, backup --tz-utc terverifikasi, dan php artisan down.
-- Jalankan dari satu sesi mysql. Jangan memakai --force: error harus menghentikan proses.
-- =====================================================================

-- 0. DDL pendukung. Aman dijalankan ulang (IF NOT EXISTS / INSERT IGNORE).
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
    phase VARCHAR(16) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    non_null BIGINT UNSIGNED NOT NULL,
    epoch_sum DECIMAL(24,0) NOT NULL,
    PRIMARY KEY (run_key, phase, table_name, column_name)
);

CREATE TABLE IF NOT EXISTS timezone_offset_columns (
    table_name VARCHAR(64) NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    PRIMARY KEY (table_name, column_name)
);

-- Daftar 35 kolom TIMESTAMP pra-P2 (failed_jobs.failed_at dikecualikan).
INSERT IGNORE INTO timezone_offset_columns (table_name, column_name) VALUES
    ('evaluation_answers', 'created_at'), ('evaluation_answers', 'updated_at'),
    ('evaluations', 'submitted_at'), ('evaluations', 'created_at'), ('evaluations', 'updated_at'),
    ('factors', 'created_at'), ('factors', 'updated_at'),
    ('intelligence_reports', 'generated_at'), ('intelligence_reports', 'received_at'),
    ('intelligence_reports', 'created_at'), ('intelligence_reports', 'updated_at'),
    ('password_reset_tokens', 'created_at'),
    ('personal_access_tokens', 'last_used_at'), ('personal_access_tokens', 'expires_at'),
    ('personal_access_tokens', 'created_at'), ('personal_access_tokens', 'updated_at'),
    ('questions', 'created_at'), ('questions', 'updated_at'),
    ('sectors', 'created_at'), ('sectors', 'updated_at'),
    ('six_factor_evaluations', 'finalized_at'), ('six_factor_evaluations', 'cancelled_at'),
    ('six_factor_evaluations', 'created_at'), ('six_factor_evaluations', 'updated_at'),
    ('umkms', 'created_at'), ('umkms', 'updated_at'), ('umkms', 'wa_checked_at'),
    ('users', 'email_verified_at'), ('users', 'created_at'), ('users', 'updated_at'),
    ('webhook_outbox', 'next_attempt_at'), ('webhook_outbox', 'lease_expires_at'),
    ('webhook_outbox', 'created_at'), ('webhook_outbox', 'updated_at'), ('webhook_outbox', 'delivered_at');

-- 1. Anchor dari payload webhook (evaluation.finalized). Epoch dihitung dari string UTC
--    dengan TIMESTAMPDIFF terhadap '1970-01-01', sehingga tidak bergantung zona sesi.
--    Gagal bila ada evaluasi dengan lebih dari satu outbox (PK), sehingga berhenti.
INSERT INTO timezone_offset_anchors (run_key, table_name, row_id, column_name, expected_epoch)
SELECT 'p2-utc-2026-11', 'six_factor_evaluations', e.id, 'finalized_at',
       TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00',
           STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(o.payload, '$.occurred_at')), '%Y-%m-%dT%H:%i:%sZ'))
FROM six_factor_evaluations e
JOIN webhook_outbox o ON o.aggregate_id = e.id AND o.event_type = 'evaluation.finalized'
WHERE e.status = 'final';

-- 2. Procedure koreksi. Seluruh badan berada dalam satu transaksi dengan EXIT HANDLER.
DROP PROCEDURE IF EXISTS p2_tz_correct_post_p2;
DELIMITER $$
CREATE PROCEDURE p2_tz_correct_post_p2(IN p_run_key VARCHAR(64), IN p_offset_s INT)
BEGIN
    DECLARE v_bad BIGINT DEFAULT 0;
    DECLARE v_count BIGINT DEFAULT 0;
    DECLARE v_lock INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET SESSION time_zone = 'SYSTEM';
        RESIGNAL;
    END;

    -- G1: offset tepat 25200 (WIB), sesi operator SYSTEM, dan offset server terukur sama.
    IF p_offset_s <> 25200 OR @@session.time_zone <> 'SYSTEM'
       OR TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) <> p_offset_s THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G1 gagal: offset atau zona sesi tidak sesuai';
    END IF;

    -- G2: run belum pernah tercatat di ledger (cegah double correction).
    SELECT COUNT(*) INTO v_count FROM timezone_offset_corrections WHERE run_key = p_run_key;
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G2 gagal: run sudah tercatat di ledger';
    END IF;

    -- G3: P2 sudah di-rollback. Tidak ada trigger, dan tidak ada migration P2 yang tercatat.
    SELECT COUNT(*) INTO v_count FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE();
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G3a gagal: trigger masih ada (rollback P2 belum dijalankan)';
    END IF;
    SELECT COUNT(*) INTO v_count FROM migrations
     WHERE migration IN (
         '2026_11_23_153536_add_artifact_status_to_intelligence_reports_table',
         '2026_11_23_153537_create_intelligence_artifacts_table',
         '2026_11_23_153538_create_intelligence_deliveries_table',
         '2026_11_23_153539_add_notification_consent_to_umkms_table',
         '2026_11_23_153540_create_email_suppressions_table',
         '2026_11_23_153541_add_period_month_to_six_factor_evaluations_table');
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G3b gagal: migration P2 masih tercatat';
    END IF;

    -- G4: inventaris TIMESTAMP sama persis dengan 35 kolom pra-P2.
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp'
       AND NOT (TABLE_NAME = 'failed_jobs' AND COLUMN_NAME = 'failed_at');
    SELECT COUNT(*) INTO v_bad FROM information_schema.COLUMNS c
     JOIN timezone_offset_columns e ON e.table_name = c.TABLE_NAME AND e.column_name = c.COLUMN_NAME
     WHERE c.TABLE_SCHEMA = DATABASE() AND c.DATA_TYPE = 'timestamp';
    IF v_count <> 35 OR v_bad <> 35 OR (SELECT COUNT(*) FROM timezone_offset_columns) <> 35 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G4 gagal: inventaris kolom TIMESTAMP tidak cocok 35';
    END IF;

    -- G5: anchor minimal 6 untuk run ini, setiap anchor cocok dengan wall UTC payload,
    --     dan selisih instan terhadap anchor sebelum koreksi tepat -p_offset_s.
    SELECT COUNT(*) INTO v_count FROM timezone_offset_anchors
     WHERE run_key = p_run_key AND table_name = 'six_factor_evaluations' AND column_name = 'finalized_at';
    IF v_count < 6 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G5a gagal: anchor kurang dari 6';
    END IF;
    SELECT COUNT(*) INTO v_bad FROM timezone_offset_anchors a
     LEFT JOIN six_factor_evaluations s ON s.id = a.row_id
     WHERE a.run_key = p_run_key AND a.table_name = 'six_factor_evaluations' AND a.column_name = 'finalized_at'
       AND (s.id IS NULL OR s.finalized_at IS NULL
            OR TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', s.finalized_at) <> a.expected_epoch
            OR UNIX_TIMESTAMP(s.finalized_at) - a.expected_epoch <> -p_offset_s);
    IF v_bad <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G5b gagal: anchor tidak cocok (baris, wall, atau offset)';
    END IF;

    -- G6: tidak ada sesi lain yang aktif pada database ini (app down, worker berhenti).
    SELECT COUNT(*) INTO v_count FROM information_schema.PROCESSLIST
     WHERE DB = DATABASE() AND ID <> CONNECTION_ID() AND COMMAND <> 'Sleep';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G6 gagal: ada sesi aktif lain pada database';
    END IF;

    -- Transaksi dimulai. Kunci seluruh tabel terdampak hingga COMMIT (blokir penulis konkuren).
    SET SESSION time_zone = '+00:00';
    START TRANSACTION;
    SELECT COUNT(*) INTO v_lock FROM `evaluation_answers` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `evaluations` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `factors` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `intelligence_reports` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `password_reset_tokens` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `personal_access_tokens` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `questions` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `sectors` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `six_factor_evaluations` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `umkms` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `users` FOR UPDATE;
    SELECT COUNT(*) INTO v_lock FROM `webhook_outbox` FOR UPDATE;

    -- Snapshot sebelum koreksi (dasar verifikasi delta).
INSERT INTO timezone_offset_snapshot (run_key, phase, table_name, column_name, non_null, epoch_sum)
    SELECT p_run_key, 'before', 'evaluation_answers', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT p_run_key, 'before', 'evaluation_answers', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT p_run_key, 'before', 'evaluations', 'submitted_at', COUNT(`submitted_at`), COALESCE(SUM(UNIX_TIMESTAMP(`submitted_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'before', 'evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'before', 'evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'before', 'factors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `factors`
    UNION ALL
    SELECT p_run_key, 'before', 'factors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `factors`
    UNION ALL
    SELECT p_run_key, 'before', 'intelligence_reports', 'generated_at', COUNT(`generated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`generated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'before', 'intelligence_reports', 'received_at', COUNT(`received_at`), COALESCE(SUM(UNIX_TIMESTAMP(`received_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'before', 'intelligence_reports', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'before', 'intelligence_reports', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'before', 'password_reset_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `password_reset_tokens`
    UNION ALL
    SELECT p_run_key, 'before', 'personal_access_tokens', 'last_used_at', COUNT(`last_used_at`), COALESCE(SUM(UNIX_TIMESTAMP(`last_used_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'before', 'personal_access_tokens', 'expires_at', COUNT(`expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`expires_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'before', 'personal_access_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'before', 'personal_access_tokens', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'before', 'questions', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `questions`
    UNION ALL
    SELECT p_run_key, 'before', 'questions', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `questions`
    UNION ALL
    SELECT p_run_key, 'before', 'sectors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT p_run_key, 'before', 'sectors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT p_run_key, 'before', 'six_factor_evaluations', 'finalized_at', COUNT(`finalized_at`), COALESCE(SUM(UNIX_TIMESTAMP(`finalized_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'before', 'six_factor_evaluations', 'cancelled_at', COUNT(`cancelled_at`), COALESCE(SUM(UNIX_TIMESTAMP(`cancelled_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'before', 'six_factor_evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'before', 'six_factor_evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'before', 'umkms', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'before', 'umkms', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'before', 'umkms', 'wa_checked_at', COUNT(`wa_checked_at`), COALESCE(SUM(UNIX_TIMESTAMP(`wa_checked_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'before', 'users', 'email_verified_at', COUNT(`email_verified_at`), COALESCE(SUM(UNIX_TIMESTAMP(`email_verified_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'before', 'users', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'before', 'users', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'before', 'webhook_outbox', 'next_attempt_at', COUNT(`next_attempt_at`), COALESCE(SUM(UNIX_TIMESTAMP(`next_attempt_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'before', 'webhook_outbox', 'lease_expires_at', COUNT(`lease_expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`lease_expires_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'before', 'webhook_outbox', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'before', 'webhook_outbox', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'before', 'webhook_outbox', 'delivered_at', COUNT(`delivered_at`), COALESCE(SUM(UNIX_TIMESTAMP(`delivered_at`)), 0) FROM `webhook_outbox`;

    -- Koreksi: +offset detik pada 35 kolom (sama dengan file asli, dengan parameter).
UPDATE `evaluation_answers` SET
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `evaluations` SET
    `submitted_at` = `submitted_at` + INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `factors` SET
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `intelligence_reports` SET
    `generated_at` = `generated_at` + INTERVAL p_offset_s SECOND,
    `received_at` = `received_at` + INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `password_reset_tokens` SET
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND;

UPDATE `personal_access_tokens` SET
    `last_used_at` = `last_used_at` + INTERVAL p_offset_s SECOND,
    `expires_at` = `expires_at` + INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `questions` SET
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `sectors` SET
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `six_factor_evaluations` SET
    `finalized_at` = `finalized_at` + INTERVAL p_offset_s SECOND,
    `cancelled_at` = `cancelled_at` + INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `umkms` SET
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND,
    `wa_checked_at` = `wa_checked_at` + INTERVAL p_offset_s SECOND;

UPDATE `users` SET
    `email_verified_at` = `email_verified_at` + INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND;

UPDATE `webhook_outbox` SET
    `next_attempt_at` = `next_attempt_at` + INTERVAL p_offset_s SECOND,
    `lease_expires_at` = `lease_expires_at` + INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` + INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` + INTERVAL p_offset_s SECOND,
    `delivered_at` = `delivered_at` + INTERVAL p_offset_s SECOND;

    -- Snapshot sesudah koreksi.
INSERT INTO timezone_offset_snapshot (run_key, phase, table_name, column_name, non_null, epoch_sum)
    SELECT p_run_key, 'after', 'evaluation_answers', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT p_run_key, 'after', 'evaluation_answers', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT p_run_key, 'after', 'evaluations', 'submitted_at', COUNT(`submitted_at`), COALESCE(SUM(UNIX_TIMESTAMP(`submitted_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'after', 'evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'after', 'evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'after', 'factors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `factors`
    UNION ALL
    SELECT p_run_key, 'after', 'factors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `factors`
    UNION ALL
    SELECT p_run_key, 'after', 'intelligence_reports', 'generated_at', COUNT(`generated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`generated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'after', 'intelligence_reports', 'received_at', COUNT(`received_at`), COALESCE(SUM(UNIX_TIMESTAMP(`received_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'after', 'intelligence_reports', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'after', 'intelligence_reports', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'after', 'password_reset_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `password_reset_tokens`
    UNION ALL
    SELECT p_run_key, 'after', 'personal_access_tokens', 'last_used_at', COUNT(`last_used_at`), COALESCE(SUM(UNIX_TIMESTAMP(`last_used_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'after', 'personal_access_tokens', 'expires_at', COUNT(`expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`expires_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'after', 'personal_access_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'after', 'personal_access_tokens', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'after', 'questions', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `questions`
    UNION ALL
    SELECT p_run_key, 'after', 'questions', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `questions`
    UNION ALL
    SELECT p_run_key, 'after', 'sectors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT p_run_key, 'after', 'sectors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT p_run_key, 'after', 'six_factor_evaluations', 'finalized_at', COUNT(`finalized_at`), COALESCE(SUM(UNIX_TIMESTAMP(`finalized_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'after', 'six_factor_evaluations', 'cancelled_at', COUNT(`cancelled_at`), COALESCE(SUM(UNIX_TIMESTAMP(`cancelled_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'after', 'six_factor_evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'after', 'six_factor_evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'after', 'umkms', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'after', 'umkms', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'after', 'umkms', 'wa_checked_at', COUNT(`wa_checked_at`), COALESCE(SUM(UNIX_TIMESTAMP(`wa_checked_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'after', 'users', 'email_verified_at', COUNT(`email_verified_at`), COALESCE(SUM(UNIX_TIMESTAMP(`email_verified_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'after', 'users', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'after', 'users', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'after', 'webhook_outbox', 'next_attempt_at', COUNT(`next_attempt_at`), COALESCE(SUM(UNIX_TIMESTAMP(`next_attempt_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'after', 'webhook_outbox', 'lease_expires_at', COUNT(`lease_expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`lease_expires_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'after', 'webhook_outbox', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'after', 'webhook_outbox', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'after', 'webhook_outbox', 'delivered_at', COUNT(`delivered_at`), COALESCE(SUM(UNIX_TIMESTAMP(`delivered_at`)), 0) FROM `webhook_outbox`;

    -- V1: delta epoch per kolom harus tepat p_offset_s per baris non-null, dan jumlah non-null tetap.
    SELECT COUNT(*) INTO v_bad
      FROM timezone_offset_snapshot b
      JOIN timezone_offset_snapshot a
        ON a.run_key = b.run_key AND a.table_name = b.table_name AND a.column_name = b.column_name AND a.phase = 'after'
     WHERE b.run_key = p_run_key AND b.phase = 'before'
       AND (a.non_null <> b.non_null OR a.epoch_sum - b.epoch_sum <> p_offset_s * b.non_null);
    IF v_bad <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'V1 gagal: delta epoch tidak sesuai offset';
    END IF;

    -- V2: setiap anchor harus sama dengan instan UTC yang diharapkan setelah koreksi.
    SELECT COUNT(*) INTO v_bad
      FROM timezone_offset_anchors a
      JOIN six_factor_evaluations s ON s.id = a.row_id
     WHERE a.run_key = p_run_key AND a.table_name = 'six_factor_evaluations' AND a.column_name = 'finalized_at'
       AND (s.finalized_at IS NULL OR UNIX_TIMESTAMP(s.finalized_at) <> a.expected_epoch);
    IF v_bad <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'V2 gagal: anchor tidak cocok setelah koreksi';
    END IF;

    -- V3: snapshot before dan after lengkap, 35 baris masing-masing (total 70).
    SELECT COUNT(*) INTO v_bad FROM timezone_offset_snapshot WHERE run_key = p_run_key;
    IF v_bad <> 70 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'V3 gagal: snapshot before atau after tidak lengkap';
    END IF;

    -- Keputusan: hanya di sini COMMIT. Semua gerbang sudah lolos.
    INSERT INTO timezone_offset_corrections (run_key, offset_seconds, applied_at)
    VALUES (p_run_key, p_offset_s, UTC_TIMESTAMP());
    COMMIT;
    SET SESSION time_zone = 'SYSTEM';
END$$
DELIMITER ;

-- 3. Eksekusi. Jalankan HANYA setelah backup terverifikasi, php artisan down, dan preflight manual lulus.
--    Sesi harus SYSTEM sebelum CALL (G1 memeriksanya).
SET SESSION time_zone = 'SYSTEM';
CALL p2_tz_correct_post_p2('p2-utc-2026-11', 25200);

-- 4. Bersihkan procedure setelah CALL berhasil. Objek hanya untuk run ini.
DROP PROCEDURE IF EXISTS p2_tz_correct_post_p2;
