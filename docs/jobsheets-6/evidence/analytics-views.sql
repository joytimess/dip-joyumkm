-- Run as a QA DBA with a durable view definer. Runtime account gets SELECT on each named view.
CREATE SQL SECURITY DEFINER VIEW joyumkm_p1_prod.analytics_umkms AS
SELECT u.id, u.name, s.name AS sector, u.province, u.city
FROM joyumkm_p1_prod.umkms u
JOIN joyumkm_p1_prod.sectors s ON s.id = u.sector_id
JOIN joyumkm_p1_prod.analytics_dataset_cohorts c ON c.user_id = u.user_id
WHERE c.dataset_key = 'joyumkm-analytics' AND c.dataset_version = 'v1';

CREATE SQL SECURITY DEFINER VIEW joyumkm_p1_prod.analytics_evaluations AS
SELECT e.id, e.umkm_id, e.ins_score, e.ect_score, e.ops_score, e.average_score,
       e.submitted_at, DATE_FORMAT(e.submitted_at + INTERVAL 7 HOUR, '%Y-%m') AS period_month
FROM joyumkm_p1_prod.evaluations e
JOIN joyumkm_p1_prod.analytics_umkms u ON u.id = e.umkm_id
WHERE e.status = 'final' AND e.submitted_at IS NOT NULL;

CREATE SQL SECURITY DEFINER VIEW joyumkm_p1_prod.analytics_six_factor_evaluations AS
SELECT e.id, e.umkm_id, e.sumber_daya, e.kepemimpinan, e.lingkungan_kerja,
       e.nilai_organisasi, e.stabilitas, e.kinerja, e.total_score, e.finalized_at, e.period_month
FROM joyumkm_p1_prod.six_factor_evaluations e
JOIN joyumkm_p1_prod.analytics_umkms u ON u.id = e.umkm_id
WHERE e.status = 'final' AND e.finalized_at IS NOT NULL;
