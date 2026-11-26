-- MySQL 8.4. Jalankan satu sesi pada schema QA joyumkm_p1_prod.
-- Menggunakan cohort sintetis P1. Q1-Q9 berikut adalah adaptasi.
START TRANSACTION READ ONLY;

-- Q1: Daftar nama dan kota UMKM pada cohort.
SELECT u.id, u.name, u.city
FROM umkms u JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id
WHERE c.dataset_key = 'joyumkm-analytics'
ORDER BY u.name, u.id LIMIT 10;

-- Q2: Jumlah UMKM per kota.
SELECT u.city, COUNT(*) AS jumlah_umkm
FROM umkms u JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id
WHERE c.dataset_key = 'joyumkm-analytics'
GROUP BY u.city ORDER BY jumlah_umkm DESC, u.city;

-- Q3: Nama, kota, dan skor Tiga Dimensi terbaru berdasarkan waktu dan ID.
WITH ranked AS (
    SELECT u.id, u.name, u.city, e.average_score,
           ROW_NUMBER() OVER (PARTITION BY u.id ORDER BY e.submitted_at DESC, e.id DESC) AS urutan
    FROM evaluations e JOIN umkms u ON u.id = e.umkm_id
    JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id
    WHERE c.dataset_key = 'joyumkm-analytics' AND e.submitted_at IS NOT NULL
)
SELECT name, city, average_score FROM ranked WHERE urutan = 1 ORDER BY name, id LIMIT 10;

-- Q4: Rata-rata INS, ECT, OPS per sektor. Satu observasi adalah satu evaluasi.
SELECT s.name AS sektor, COUNT(*) AS observasi,
       ROUND(AVG(e.ins_score), 2) AS ins, ROUND(AVG(e.ect_score), 2) AS ect,
       ROUND(AVG(e.ops_score), 2) AS ops
FROM evaluations e JOIN umkms u ON u.id = e.umkm_id
JOIN sectors s ON s.id = u.sector_id
JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id
WHERE c.dataset_key = 'joyumkm-analytics' AND e.submitted_at IS NOT NULL
GROUP BY s.id, s.name ORDER BY s.name, s.id;

-- Q5: Kota dengan minimal 10 evaluasi Enam Faktor final.
SELECT u.city, COUNT(*) AS observasi, ROUND(AVG(e.total_score), 2) AS rata_rata
FROM six_factor_evaluations e JOIN umkms u ON u.id = e.umkm_id
JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id
WHERE c.dataset_key = 'joyumkm-analytics' AND e.status = 'final' AND e.finalized_at IS NOT NULL
GROUP BY u.city HAVING COUNT(*) >= 10 ORDER BY rata_rata DESC, u.city;

-- Q6: Rata-rata Enam Faktor per kota dan bulan WIB.
SELECT e.period_month, u.city, COUNT(*) AS observasi, ROUND(AVG(e.total_score), 2) AS rata_rata
FROM six_factor_evaluations e JOIN umkms u ON u.id = e.umkm_id
JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id
WHERE c.dataset_key = 'joyumkm-analytics' AND e.status = 'final' AND e.finalized_at IS NOT NULL
GROUP BY e.period_month, u.city ORDER BY e.period_month, u.city LIMIT 20;

-- Q7: Lima kota teratas dalam tiga bulan kalender terakhir yang tersedia pada cohort.
WITH cohort_final AS (
    SELECT u.city, e.period_month, e.total_score
    FROM six_factor_evaluations e JOIN umkms u ON u.id = e.umkm_id
    JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id
    WHERE c.dataset_key = 'joyumkm-analytics' AND e.status = 'final' AND e.finalized_at IS NOT NULL
), batas AS (
    SELECT MAX(period_month) AS akhir FROM cohort_final
), periode AS (
    SELECT akhir, DATE_FORMAT(DATE_SUB(CONCAT(akhir, '-01'), INTERVAL 2 MONTH), '%Y-%m') AS awal
    FROM batas
)
SELECT p.awal, p.akhir, f.city, COUNT(*) AS observasi, ROUND(AVG(f.total_score), 2) AS rata_rata
FROM cohort_final f JOIN periode p ON f.period_month BETWEEN p.awal AND p.akhir
GROUP BY p.awal, p.akhir, f.city ORDER BY AVG(f.total_score) DESC, f.city LIMIT 5;

-- Q8: JOIN tanpa ON. Dua baris dikali tiga baris menghasilkan enam pasangan.
SELECT a.huruf, b.angka
FROM (SELECT 'A' AS huruf UNION ALL SELECT 'B') a
JOIN (SELECT 1 AS angka UNION ALL SELECT 2 UNION ALL SELECT 3) b
ORDER BY a.huruf, b.angka;

-- Q9: Bedakan UMKM yang dinilai dengan jumlah evaluasi Enam Faktor.
SELECT COUNT(DISTINCT u.id) AS jumlah_umkm, COUNT(*) AS jumlah_evaluasi
FROM six_factor_evaluations e JOIN umkms u ON u.id = e.umkm_id
JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id
WHERE c.dataset_key = 'joyumkm-analytics' AND e.status = 'final' AND e.finalized_at IS NOT NULL;

COMMIT;

-- Latihan 1 (adaptasi): Ubah ambang observasi Q5 menjadi 25.
-- Latihan 2 (adaptasi): Batasi Q6 pada satu kota dan tampilkan semua bulannya.
-- Latihan 3 (adaptasi): Tambahkan MIN dan MAX skor Enam Faktor per kota pada Q7.
