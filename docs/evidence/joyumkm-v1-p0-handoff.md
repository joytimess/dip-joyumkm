# Handoff JoyUMKM V1 P0

**20 November 2026 · PASS P0 · Suite regresi masih memiliki lima kegagalan baseline**

P0 menambahkan penyimpanan evaluasi enam faktor dan layanan domain untuk melanjutkan Draft, memfinalisasi, membatalkan, serta membaca histori. Histori diurutkan berdasarkan `finalized_at` terbaru, lalu ID terbesar jika waktunya sama. Penyimpanan Draft dan aksi terminal memakai lock UMKM sebelum lock evaluasi. Format, skema, dan perilaku kandidat sudah diperiksa melalui QA independen.

## Kandidat dan database

- HEAD baseline dan basis kandidat pada `main` adalah `804738b0a39f9dc2658e40e92e796077d131aca6`. Saat mulai, working tree hanya berisi brief P0 yang belum dilacak Git. Kandidat diserahkan sebagai perubahan working tree.
- Database aplikasi lokal `joyumkm-v2` menggunakan MySQL 8.4.3. Pemeriksaan awal dan akhir menunjukkan delapan migration V1 tercatat serta nol tabel atau catatan migration P0.
- Tes berjalan pada MySQL 8.4.3 dalam skema khusus `joyumkm_testing`. Skema ini dibuat untuk pengujian dan guard tes memeriksa nama database aktif sebelum operasi DDL. Status migration kandidat adalah `Ran` pada skema uji.

## Hasil pengujian

| Pemeriksaan | Hasil |
|---|---|
| Baseline PHP sebelum perubahan | **FAIL** · 93 dari 98 lulus, 792 assertion, tiga kegagalan dan dua error. |
| Fitur P0 oleh QA independen | **PASS** · 35 dari 35 lulus, 355 assertion. |
| Suite penuh kandidat oleh QA independen | **FAIL** · 128 dari 133 lulus, 1.153 assertion. Kelima kegagalan dan error cocok dengan baseline. Status seluruh 98 tes lama sama. |
| Regresi JavaScript `npm test` | **PASS** · 26 dari 26 lulus. |
| Format dan sintaks | **PASS** · `php vendor/bin/pint --dirty --format agent`, lint tujuh file PHP, dan pemeriksaan whitespace. |

Baseline dijalankan dengan `php vendor/bin/phpunit --log-junit "$env:TEMP/joyumkm-p0-baseline.xml"`. QA menjalankan `php vendor/bin/phpunit --filter SixFactorEvaluation --log-junit "$env:TEMP/joyumkm-p0-qa-focused.xml"` dan `php vendor/bin/phpunit --log-junit "$env:TEMP/joyumkm-p0-qa.xml"`. Perbandingan JUnit memakai nama kasus dan jenis kegagalan. Enam assertion tambahan pada tes lama berasal dari jumlah baris acak yang diperiksa sebelum kegagalan batas evaluasi terdeteksi.

| Tes lama | Baseline dan kandidat |
|---|---|
| `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores` | FAIL · jumlah akun 4, ekspektasi 1. |
| `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` | FAIL · jumlah akun 4, ekspektasi 1. |
| `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` | ERROR · argumen parameterized test belum diberikan. |
| `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` | FAIL · empat evaluasi ditemukan pada profil dengan batas tiga. |
| `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` | ERROR · nama koneksi yang diterima berisi pesan assertion. |

## Bukti kriteria penerimaan

| AC | Bukti kandidat | Status |
|---|---|---|
| 01–03 | Tes migration membandingkan `SHOW CREATE TABLE`, jumlah baris, dan SHA-256 isi delapan tabel bisnis V1 yang berisi fixture. Satu tabel ditambahkan. Rollback tabel kosong berhasil. Rollback dengan data ditolak dan baris tetap ada. | PASS |
| 04–07 | Metadata MySQL membuktikan FK RESTRICT, kedua indeks, status default, enam kolom `TINYINT UNSIGNED NULL`, kolom generated STORED, dan CHECK aktif. SQL menolak nilai di luar rentang pada keenam faktor. Service menerima integer 1–5 atau null dan menolak kunci di luar kontrak dengan pesan Indonesia. | PASS |
| 08–09, 11 | UNIQUE menolak Draft kedua untuk UMKM yang sama. UMKM berbeda memiliki slot sendiri. Penyimpanan kosong, parsial, pembaruan sebagian, dan pengosongan faktor melanjutkan ID yang sama. | PASS |
| 10 · kompetisi MySQL | Sesi independen terlihat menunggu lock induk di `SHOW FULL PROCESSLIST`. Save/Save menghasilkan satu Draft dengan kedua perubahan. Save/Finalize menghasilkan satu Final dengan perubahan terakhir. Finalize/Save mempertahankan Final dan membuka Draft baru. Pada dua UMKM berbeda, QA juga mereproduksi deadlock MySQL 1213. Sesudah perbaikan, tes mencatat jumlah percobaan 1 dan 2 serta kedua Draft tersimpan. | PASS |
| 10 · simulasi UNIQUE | Saat benturan indeks Draft disuntikkan sekali, layanan mengulang transaksi dan membaca kembali Draft yang sudah ada. Benturan berulang dipropagasikan setelah dua percobaan. Benturan pada indeks lain langsung dipropagasikan. | PASS |
| 12–15 | Delapan batas total 6, 12, 13, 18, 19, 24, 25, dan 30 menghasilkan kategori yang ditentukan server. Finalisasi parsial mempertahankan Draft. INSERT dan UPDATE SQL dengan status, skor, kategori, atau waktu yang inkonsisten ditolak. | PASS |
| 16–19 | Guard Eloquent menolak perubahan instance terminal, termasuk instance Draft lama, operasi quiet, dan operasi penghitung. Semua status menolak penghapusan instance. Histori berisi Final milik UMKM terkait dan cocok dengan latest. Cancel mempertahankan faktor dan membuka slot Draft. Kegagalan sesudah UPDATE menggulung balik finalisasi. | PASS |
| 20–21 | Enam operasi menolak akun lain. MySQL menolak FK ke UMKM yang hilang dan penghapusan UMKM dengan evaluasi enam faktor. Evaluasi V1 tetap mengikuti penghapusan induk tanpa evaluasi enam faktor. | PASS |
| 22–23 | Diff produk mencakup satu migration, model, tiga relasi tambahan, service, dan factory. Dua kelas tes membuktikan kontrak P0. Pemeriksaan format lulus dan perbandingan seluruh kasus lama menemukan nol regresi baru. | PASS |

## Dampak operasional

- FK RESTRICT membuat penghapusan UMKM yang memiliki evaluasi enam faktor gagal dengan MySQL 1451. Jalur hapus HTTP V1 masih meneruskan error database. Pemetaan error ke respons pengguna menjadi bagian fase yang membuka akses tulis HTTP.
- Guard immutabilitas berlaku pada operasi instance Eloquent. Bulk query dan SQL langsung perlu menghormati batas penulisan melalui service. FK dan CHECK tetap berlaku pada level database.
- Collation `utf8mb4_0900_bin` memerlukan MySQL 8.0.17 atau lebih baru. Rollback memakai `LOCK TABLES` agar penulis tidak bisa menyisipkan evaluasi di antara pemeriksaan tabel kosong dan penghapusan. Akun rollback memerlukan privilege tersebut. [Catatan rilis MySQL 8.0.17](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-17.html) · [Referensi LOCK TABLES](https://dev.mysql.com/doc/refman/8.4/en/lock-tables.html)

## SHA-256 file kandidat yang diuji

| File | SHA-256 |
|---|---|
| `app/Models/Umkm.php` | `64fcdf32aff0abbc0b8592c44349ac0d58d9649c27480d0308ee390bfb76e1e6` |
| `app/Models/SixFactorEvaluation.php` | `a02bd8b5a2b7e0eb371e61f335829cf368f90a148da9e4fb2c5f66f1109da867` |
| `app/Services/SixFactorEvaluationService.php` | `a8e832adfbb965d29aeb81e8054e5f2caf3889ef32d764d57e2c705e87c75dcb` |
| `database/factories/SixFactorEvaluationFactory.php` | `20ee8f4617d07b829c8d12a5fcd1a494ddfb46f36d166d49553091c53f96f3d7` |
| `database/migrations/2026_11_20_084534_create_six_factor_evaluations_table.php` | `573bf2f968559e70c5ccf6997498cc524082290db8c80185c4371ea608f86b32` |
| `tests/Feature/SixFactorEvaluationDatabaseTest.php` | `f767021ec85df78de4632520923d629157a532f87466e263806e130cc8e13082` |
| `tests/Feature/SixFactorEvaluationServiceTest.php` | `09649d89690931def783df17f05df6bdf863377425453596ed76f2789ad31b27` |
