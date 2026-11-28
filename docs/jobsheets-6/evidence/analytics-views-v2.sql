-- JoyUMKM analytics views, v2 membership contract (P6). Run as a local DBA on `joyumkm-v2`.
-- Same view names, columns and eligibility rules as analytics-views.sql (legacy, owner-based);
-- only the cohort anchor changes: analytics_dataset_members -> analytics_dataset_cohorts (dataset_version = 'v2').
-- Owner (umkms.user_id / analytics_dataset_cohorts.user_id) is provenance only and never used for selection.
-- MySQL DDL auto-commits: apply step by step. Idempotent (CREATE OR REPLACE / IF NOT EXISTS).

-- 1. Locked, least-privileged definer (never used to log in; no password is stored anywhere).
CREATE USER IF NOT EXISTS 'joyumkm_analytics_def'@'localhost' IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK;
GRANT SELECT ON `joyumkm-v2`.`umkms` TO 'joyumkm_analytics_def'@'localhost';
GRANT SELECT ON `joyumkm-v2`.`sectors` TO 'joyumkm_analytics_def'@'localhost';
GRANT SELECT ON `joyumkm-v2`.`evaluations` TO 'joyumkm_analytics_def'@'localhost';
GRANT SELECT ON `joyumkm-v2`.`six_factor_evaluations` TO 'joyumkm_analytics_def'@'localhost';
GRANT SELECT ON `joyumkm-v2`.`analytics_dataset_members` TO 'joyumkm_analytics_def'@'localhost';
GRANT SELECT ON `joyumkm-v2`.`analytics_dataset_cohorts` TO 'joyumkm_analytics_def'@'localhost';

-- 2. Views.
CREATE OR REPLACE DEFINER = 'joyumkm_analytics_def'@'localhost' SQL SECURITY DEFINER VIEW `joyumkm-v2`.analytics_umkms AS
SELECT u.id, u.name, s.name AS sector, u.province, u.city
FROM `joyumkm-v2`.umkms u
JOIN `joyumkm-v2`.analytics_dataset_members m ON m.umkm_id = u.id
JOIN `joyumkm-v2`.analytics_dataset_cohorts c ON c.id = m.analytics_dataset_cohort_id
JOIN `joyumkm-v2`.sectors s ON s.id = u.sector_id
WHERE c.dataset_key = 'joyumkm-analytics' AND c.dataset_version = 'v2';

CREATE OR REPLACE DEFINER = 'joyumkm_analytics_def'@'localhost' SQL SECURITY DEFINER VIEW `joyumkm-v2`.analytics_evaluations AS
SELECT e.id, e.umkm_id, e.ins_score, e.ect_score, e.ops_score, e.average_score,
       e.submitted_at, DATE_FORMAT(e.submitted_at + INTERVAL 7 HOUR, '%Y-%m') AS period_month
FROM `joyumkm-v2`.evaluations e
JOIN `joyumkm-v2`.analytics_dataset_members m ON m.umkm_id = e.umkm_id
JOIN `joyumkm-v2`.analytics_dataset_cohorts c ON c.id = m.analytics_dataset_cohort_id
JOIN `joyumkm-v2`.umkms u ON u.id = e.umkm_id
JOIN `joyumkm-v2`.sectors s ON s.id = u.sector_id
WHERE c.dataset_key = 'joyumkm-analytics' AND c.dataset_version = 'v2'
  AND e.status = 'final' AND e.submitted_at IS NOT NULL;

CREATE OR REPLACE DEFINER = 'joyumkm_analytics_def'@'localhost' SQL SECURITY DEFINER VIEW `joyumkm-v2`.analytics_six_factor_evaluations AS
SELECT e.id, e.umkm_id, e.sumber_daya, e.kepemimpinan, e.lingkungan_kerja,
       e.nilai_organisasi, e.stabilitas, e.kinerja, e.total_score, e.finalized_at, e.period_month
FROM `joyumkm-v2`.six_factor_evaluations e
JOIN `joyumkm-v2`.analytics_dataset_members m ON m.umkm_id = e.umkm_id
JOIN `joyumkm-v2`.analytics_dataset_cohorts c ON c.id = m.analytics_dataset_cohort_id
JOIN `joyumkm-v2`.umkms u ON u.id = e.umkm_id
JOIN `joyumkm-v2`.sectors s ON s.id = u.sector_id
WHERE c.dataset_key = 'joyumkm-analytics' AND c.dataset_version = 'v2'
  AND e.status = 'final' AND e.finalized_at IS NOT NULL;

-- 3. Runtime account: SELECT on the three views only (existing account, REQUIRE SSL already set).
GRANT SELECT ON `joyumkm-v2`.`analytics_umkms` TO 'joyumkm_analytics_ro'@'localhost';
GRANT SELECT ON `joyumkm-v2`.`analytics_evaluations` TO 'joyumkm_analytics_ro'@'localhost';
GRANT SELECT ON `joyumkm-v2`.`analytics_six_factor_evaluations` TO 'joyumkm_analytics_ro'@'localhost';

-- Rollback (reverse order; legacy grants on joyumkm_p1_prod were never touched):
--   REVOKE SELECT ON `joyumkm-v2`.`analytics_umkms` FROM 'joyumkm_analytics_ro'@'localhost';   (and the other two)
--   DROP VIEW `joyumkm-v2`.analytics_six_factor_evaluations, `joyumkm-v2`.analytics_evaluations, `joyumkm-v2`.analytics_umkms;
--   DROP USER 'joyumkm_analytics_def'@'localhost';
