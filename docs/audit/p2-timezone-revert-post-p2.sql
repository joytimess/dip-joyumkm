-- =====================================================================
-- P2 POST-MIGRATION: REVERT koreksi timezone (JoyUMKM, MySQL 8.4)
-- Membalik p2_tz_correct_post_p2 HANYA bila data masih persis sama dengan snapshot 'after'.
-- Jika ada penulisan setelah koreksi (app sudah up), revert DITOLAK (V0). Itu disengaja.
-- Revert harus dijalankan SEBELUM migration P2 dijalankan ulang, karena trigger immutable
-- intelligence_reports memblokir UPDATE (G3). Urutan lengkap ada di runbook.
-- Revert menghapus ledger dan snapshot untuk run_key, sehingga run yang sama bisa diulang.
-- Anchor dibiarkan. Jalankan dari sesi mysql tanpa --force.
-- =====================================================================

DROP PROCEDURE IF EXISTS p2_tz_revert_post_p2;
DELIMITER $$
CREATE PROCEDURE p2_tz_revert_post_p2(IN p_run_key VARCHAR(64), IN p_offset_s INT)
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

    -- G1: offset 25200 dan sesi SYSTEM.
    IF p_offset_s <> 25200 OR @@session.time_zone <> 'SYSTEM'
       OR TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) <> p_offset_s THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G1 gagal: offset atau zona sesi tidak sesuai';
    END IF;

    -- G0: run harus sudah tercatat di ledger (ada yang bisa dibalik).
    SELECT COUNT(*) INTO v_count FROM timezone_offset_corrections WHERE run_key = p_run_key;
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G0 gagal: run tidak ada di ledger';
    END IF;

    -- G3: tanpa trigger dan tanpa migration P2 (sama dengan koreksi).
    SELECT COUNT(*) INTO v_count FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE();
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G3a gagal: trigger ada, rollback P2 dulu sebelum revert';
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

    -- G6: tidak ada sesi lain yang aktif.
    SELECT COUNT(*) INTO v_count FROM information_schema.PROCESSLIST
     WHERE DB = DATABASE() AND ID <> CONNECTION_ID() AND COMMAND <> 'Sleep';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'G6 gagal: ada sesi aktif lain pada database';
    END IF;

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

    -- Snapshot kondisi saat ini (harus identik dengan 'after').
INSERT INTO timezone_offset_snapshot (run_key, phase, table_name, column_name, non_null, epoch_sum)
    SELECT p_run_key, 'pre_revert', 'evaluation_answers', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'evaluation_answers', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'evaluations', 'submitted_at', COUNT(`submitted_at`), COALESCE(SUM(UNIX_TIMESTAMP(`submitted_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'factors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `factors`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'factors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `factors`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'intelligence_reports', 'generated_at', COUNT(`generated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`generated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'intelligence_reports', 'received_at', COUNT(`received_at`), COALESCE(SUM(UNIX_TIMESTAMP(`received_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'intelligence_reports', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'intelligence_reports', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'password_reset_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `password_reset_tokens`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'personal_access_tokens', 'last_used_at', COUNT(`last_used_at`), COALESCE(SUM(UNIX_TIMESTAMP(`last_used_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'personal_access_tokens', 'expires_at', COUNT(`expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`expires_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'personal_access_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'personal_access_tokens', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'questions', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `questions`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'questions', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `questions`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'sectors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'sectors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'six_factor_evaluations', 'finalized_at', COUNT(`finalized_at`), COALESCE(SUM(UNIX_TIMESTAMP(`finalized_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'six_factor_evaluations', 'cancelled_at', COUNT(`cancelled_at`), COALESCE(SUM(UNIX_TIMESTAMP(`cancelled_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'six_factor_evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'six_factor_evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'umkms', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'umkms', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'umkms', 'wa_checked_at', COUNT(`wa_checked_at`), COALESCE(SUM(UNIX_TIMESTAMP(`wa_checked_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'users', 'email_verified_at', COUNT(`email_verified_at`), COALESCE(SUM(UNIX_TIMESTAMP(`email_verified_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'users', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'users', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'webhook_outbox', 'next_attempt_at', COUNT(`next_attempt_at`), COALESCE(SUM(UNIX_TIMESTAMP(`next_attempt_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'webhook_outbox', 'lease_expires_at', COUNT(`lease_expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`lease_expires_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'webhook_outbox', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'webhook_outbox', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'pre_revert', 'webhook_outbox', 'delivered_at', COUNT(`delivered_at`), COALESCE(SUM(UNIX_TIMESTAMP(`delivered_at`)), 0) FROM `webhook_outbox`;

    -- V0: kondisi saat ini harus persis 'after'. Jika tidak, ada penulisan setelah koreksi: STOP.
    SELECT COUNT(*) INTO v_bad
      FROM timezone_offset_snapshot b
      JOIN timezone_offset_snapshot a
        ON a.run_key = b.run_key AND a.table_name = b.table_name AND a.column_name = b.column_name
     WHERE b.run_key = p_run_key AND b.phase = 'after' AND a.phase = 'pre_revert'
       AND (a.non_null <> b.non_null OR a.epoch_sum <> b.epoch_sum);
    IF v_bad <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'V0 gagal: data berubah sejak koreksi, revert ditolak';
    END IF;

    -- Balik koreksi: -offset detik pada 35 kolom.
    -- (blok UPDATE di bawah diturunkan dari p2-timezone-correction-post-p2.sql dengan tanda dibalik)
UPDATE `evaluation_answers` SET
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `evaluations` SET
    `submitted_at` = `submitted_at` - INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `factors` SET
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `intelligence_reports` SET
    `generated_at` = `generated_at` - INTERVAL p_offset_s SECOND,
    `received_at` = `received_at` - INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `password_reset_tokens` SET
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND;

UPDATE `personal_access_tokens` SET
    `last_used_at` = `last_used_at` - INTERVAL p_offset_s SECOND,
    `expires_at` = `expires_at` - INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `questions` SET
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `sectors` SET
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `six_factor_evaluations` SET
    `finalized_at` = `finalized_at` - INTERVAL p_offset_s SECOND,
    `cancelled_at` = `cancelled_at` - INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `umkms` SET
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND,
    `wa_checked_at` = `wa_checked_at` - INTERVAL p_offset_s SECOND;

UPDATE `users` SET
    `email_verified_at` = `email_verified_at` - INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND;

UPDATE `webhook_outbox` SET
    `next_attempt_at` = `next_attempt_at` - INTERVAL p_offset_s SECOND,
    `lease_expires_at` = `lease_expires_at` - INTERVAL p_offset_s SECOND,
    `created_at` = `created_at` - INTERVAL p_offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL p_offset_s SECOND,
    `delivered_at` = `delivered_at` - INTERVAL p_offset_s SECOND;

    -- Snapshot setelah revert.
INSERT INTO timezone_offset_snapshot (run_key, phase, table_name, column_name, non_null, epoch_sum)
    SELECT p_run_key, 'post_revert', 'evaluation_answers', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'evaluation_answers', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluation_answers`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'evaluations', 'submitted_at', COUNT(`submitted_at`), COALESCE(SUM(UNIX_TIMESTAMP(`submitted_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `evaluations`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'factors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `factors`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'factors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `factors`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'intelligence_reports', 'generated_at', COUNT(`generated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`generated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'intelligence_reports', 'received_at', COUNT(`received_at`), COALESCE(SUM(UNIX_TIMESTAMP(`received_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'intelligence_reports', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'intelligence_reports', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `intelligence_reports`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'password_reset_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `password_reset_tokens`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'personal_access_tokens', 'last_used_at', COUNT(`last_used_at`), COALESCE(SUM(UNIX_TIMESTAMP(`last_used_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'personal_access_tokens', 'expires_at', COUNT(`expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`expires_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'personal_access_tokens', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'personal_access_tokens', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `personal_access_tokens`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'questions', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `questions`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'questions', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `questions`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'sectors', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'sectors', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `sectors`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'six_factor_evaluations', 'finalized_at', COUNT(`finalized_at`), COALESCE(SUM(UNIX_TIMESTAMP(`finalized_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'six_factor_evaluations', 'cancelled_at', COUNT(`cancelled_at`), COALESCE(SUM(UNIX_TIMESTAMP(`cancelled_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'six_factor_evaluations', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'six_factor_evaluations', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `six_factor_evaluations`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'umkms', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'umkms', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'umkms', 'wa_checked_at', COUNT(`wa_checked_at`), COALESCE(SUM(UNIX_TIMESTAMP(`wa_checked_at`)), 0) FROM `umkms`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'users', 'email_verified_at', COUNT(`email_verified_at`), COALESCE(SUM(UNIX_TIMESTAMP(`email_verified_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'users', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'users', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `users`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'webhook_outbox', 'next_attempt_at', COUNT(`next_attempt_at`), COALESCE(SUM(UNIX_TIMESTAMP(`next_attempt_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'webhook_outbox', 'lease_expires_at', COUNT(`lease_expires_at`), COALESCE(SUM(UNIX_TIMESTAMP(`lease_expires_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'webhook_outbox', 'created_at', COUNT(`created_at`), COALESCE(SUM(UNIX_TIMESTAMP(`created_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'webhook_outbox', 'updated_at', COUNT(`updated_at`), COALESCE(SUM(UNIX_TIMESTAMP(`updated_at`)), 0) FROM `webhook_outbox`
    UNION ALL
    SELECT p_run_key, 'post_revert', 'webhook_outbox', 'delivered_at', COUNT(`delivered_at`), COALESCE(SUM(UNIX_TIMESTAMP(`delivered_at`)), 0) FROM `webhook_outbox`;

    -- V1: setelah revert, setiap kolom harus identik dengan snapshot 'before' (non-null dan jumlah epoch).
    SELECT COUNT(*) INTO v_bad
      FROM timezone_offset_snapshot b
      JOIN timezone_offset_snapshot a
        ON a.run_key = b.run_key AND a.table_name = b.table_name AND a.column_name = b.column_name
     WHERE b.run_key = p_run_key AND b.phase = 'before' AND a.phase = 'post_revert'
       AND (a.non_null <> b.non_null OR a.epoch_sum <> b.epoch_sum);
    IF v_bad <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'V1 gagal: hasil revert tidak identik dengan before';
    END IF;

    -- V2: setiap anchor kembali ke instan pra-koreksi (selisih -p_offset_s).
    SELECT COUNT(*) INTO v_bad
      FROM timezone_offset_anchors a
      JOIN six_factor_evaluations s ON s.id = a.row_id
     WHERE a.run_key = p_run_key AND a.table_name = 'six_factor_evaluations' AND a.column_name = 'finalized_at'
       AND (s.finalized_at IS NULL OR UNIX_TIMESTAMP(s.finalized_at) - a.expected_epoch <> -p_offset_s);
    IF v_bad <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'V2 gagal: anchor tidak kembali ke pra-koreksi';
    END IF;

    -- Hapus ledger, snapshot, dan anchor run ini, supaya run dapat diulang dari awal
    -- (anchor dimuat ulang dari payload oleh langkah 1 file koreksi).
    DELETE FROM timezone_offset_anchors WHERE run_key = p_run_key;
    DELETE FROM timezone_offset_snapshot WHERE run_key = p_run_key;
    DELETE FROM timezone_offset_corrections WHERE run_key = p_run_key;
    COMMIT;
    SET SESSION time_zone = 'SYSTEM';
END$$
DELIMITER ;

-- Eksekusi revert. Jalankan HANYA bila restore dari dump tidak dipilih dan owner menyetujui.
SET SESSION time_zone = 'SYSTEM';
CALL p2_tz_revert_post_p2('p2-utc-2026-11', 25200);
DROP PROCEDURE IF EXISTS p2_tz_revert_post_p2;
