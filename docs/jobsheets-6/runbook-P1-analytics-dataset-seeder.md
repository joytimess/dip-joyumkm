# Runbook: Dataset Analitik Sintetis (P1)

Dokumen ini menjelaskan cara menjalankan, memverifikasi, mengekspor, dan memulihkan dataset analitik sintetis (1 akun, 428 UMKM, 1.284 evaluasi Tiga Dimensi, 1.284 evaluasi Enam Faktor, 20.544 jawaban). Spesifikasi lengkap ada di [PRD-P1-Analytics-Dataset-Seeder.md](prd/PRD-P1-Analytics-Dataset-Seeder.md).

## Prasyarat

- Migrasi sudah dijalankan: `php artisan migrate` (menambahkan tabel `analytics_dataset_cohorts`).
- Data master sudah ada: 5 sektor (`fashion-kriya`, `grosir-retail`, `jasa`, `makanan-minuman`, `pertanian`), tepat tiga faktor aktif `INS`, `ECT`, `OPS` dengan 6/4/6 pertanyaan, dan 16 pertanyaan aktif. Jika tidak cocok, seeder berhenti sebelum menulis apa pun.
- Database MySQL. Seeder menolak driver lain.
- Kata sandi akun analitik minimal 12 karakter, maksimal 72 byte, tanpa karakter NUL.

## Menjalankan seeder

Seeder hanya berjalan lewat perintah operator. Tidak ada `DatabaseSeeder`, `db:seed` biasa, atau deploy yang memanggilnya.

Kata sandi tidak pernah dibaca dari `.env` atau config. Hanya tiga sumber yang didukung, dan nilainya tidak pernah dicetak:

| Cara | Contoh |
|------|--------|
| Interaktif (prompt tersembunyi) | `php artisan analytics-dataset:seed` |
| Berkas | `php artisan analytics-dataset:seed --password-file=/ruang/aman/analitik.secret --no-interaction` |
| Stdin | `Get-Content C:\aman\analitik.secret \| php artisan analytics-dataset:seed --password-stdin --no-interaction` (PowerShell) |

Opsi tambahan:

- `--reference-end=YYYY-MM` menentukan bulan akhir periode referensi (12 bulan berakhir pada bulan itu). Hanya berlaku saat cohort baru dibuat. Jika dihilangkan, bulan akhir adalah bulan kalender lengkap terakhir zona WIB. Nilai yang berbeda dari cohort yang sudah ada menghentikan proses.

Mode noninteraktif (`--no-interaction`) tanpa `--password-file` atau `--password-stdin` gagal tertutup dengan pesan yang jelas.

Di linux/bash, gunakan berkas dengan izin terbatas (`chmod 600`) dan hapus setelah dipakai. Jangan menaruh kata sandi langsung di argumen perintah, karena akan tersimpan di riwayat shell.

### Mode yang didukung

- **Lokal**: `APP_ENV=local`, database pengembangan. Seeder sama dengan produksi.
- **Produksi**: `APP_ENV=production`, `APP_DEBUG=false`, dengan `php artisan config:cache`. Aman karena secret tidak berada di config. Yang diuji di P1 hanya database terisolasi `joyumkm_p1_prod`, bukan server produksi sungguhan.

## Output yang diharapkan

Cohort baru:

```
Analytics dataset: dibuat
Waktu: 4.84 detik; memory peak: 56.0 MB
Sidik jari konten: 229e3dc07f86bcbaaae82c819fa367bdac56f37bdec186d7043dfa1b479e1560
Pemeriksaan integritas: 31 lulus.
```

Rerun dengan kata sandi yang benar:

```
Analytics dataset: terverifikasi (no-op, tidak ada data ditulis)
```

Sidik jari dengan nilai `229e3dc0…` dihasilkan deterministik dari seed `joyumkm-analytics-v1`. Nilai yang berbeda berarti konten cohort sudah berubah atau generator berubah.

## Perilaku rerun dan konflik

| Kondisi | Hasil |
|---------|-------|
| Cohort ada, kata sandi benar, konten utuh | No-op, verifikasi 31 pemeriksaan, tidak ada tulisan |
| Cohort ada, kata sandi salah | Gagal, tidak ada perubahan |
| Cohort ada, periode referensi berbeda | Gagal, cohort tidak digeser |
| Cohort ada, konten berubah (drift) | Gagal dan dilaporkan. Tidak diperbaiki otomatis |
| Tidak ada cohort, tetapi akun dengan email `analitik.sintetis@dataset.invalid` sudah ada | Gagal tertutup. Akun itu tidak disentuh |
| Master data tidak cocok | Gagal sebelum mutasi |
| Proses lain sedang menjalankan seeder | Menunggu kunci `GET_LOCK` sampai batas waktu, lalu gagal |

Batas waktu kunci diatur lewat `ANALYTICS_DATASET_LOCK_TIMEOUT_SECONDS` (default 120).

## Verifikasi dan ekspor

Ekspor CSV 428 baris (30 kolom) ke lokasi default `storage/app/analytics-dataset/umkm-analytics-dataset.csv`:

```
php artisan analytics-dataset:export
php artisan analytics-dataset:export C:\laporan\umkm-analytics-dataset.csv
```

Ekspor hanya mengambil data milik cohort, memvalidasi 428 baris unik, dan tidak memuat kata sandi atau email akun.

## Pemulihan

- **Gagal di tengah jalan**: seluruh tulisan berada dalam satu transaksi dan di-rollback. Perbaiki penyebabnya, lalu jalankan ulang perintah yang sama.
- **Kunci tertahan**: kunci dilepas di blok `finally`. Jika proses mati mendadak, koneksi MySQL yang menahan kunci ikut berakhir dan kunci lepas otomatis.
- **Drift atau konflik**: seeder tidak memperbaiki data. Tentukan keputusan secara manual (misalnya menghapus data sintetis yang salah secara terkontrol), lalu jalankan ulang.
- **Config cache tertinggal**: jalankan `php artisan config:clear` setelah pengujian produksi.

Di P1 tidak ada perintah hapus dataset. Penghapusan harus dilakukan manual dan terkontrol.

## Yang tidak dilakukan seeder

- Tidak menulis outbox webhook atau antrean job (`webhook_outbox` dan `jobs` tetap 0 baris untuk cohort).
- Tidak mengirim WhatsApp, onboarding, atau email.
- Tidak mengubah data master, tabel pengguna lain, atau berkas konfigurasi.
- Tidak mengubah data pengembangan `joyumkm-v2` kecuali dijalankan secara eksplisit di sana.

## Pengujian

```
php artisan test --filter=AnalyticsDatasetPlannerTest
php artisan test --filter=AnalyticsDatasetSeederTest
```

Pengujian berjalan di database MySQL `joyumkm_testing`. Pengujian menolak berjalan di database lain. Jangan menjalankan `migrate:fresh --env=testing` karena tidak ada `.env.testing`.
