# PRD — P1: Analytics Dataset Seeder

**Project:** DIP-JoyUMKM  
**Repository:** https://github.com/joytimess/dip-joyumkm  
**Workspace:** `C:\laragon\www\joyumkm`  
**Scope:** Laravel dan MySQL  
**Target:** Production-ready candidate, diverifikasi melalui integration test MySQL sebelum deployment.

## 1. Role dan objective

Bertindak sebagai Senior Laravel Engineer, Database Engineer, dan QA Engineer. Implementasikan dataset analitik sintetis yang lengkap sebagai fondasi Conversational Business Intelligence JoyUMKM. Selesaikan P1 hingga seluruh acceptance criteria terbukti; jangan lanjut ke pengembangan Hermes, AI, Telegram, atau fase berikutnya.

## 2. Preflight

- Periksa working tree, `AGENTS.md`, dokumentasi, migration, model, service, seeder, konfigurasi, dan Graphify jika tersedia. Pertahankan perubahan pengguna yang sudah ada.
- Verifikasi MySQL aktual, schema, master sektor, questionnaire aktif, constraint, dan versi dependency. Jangan tampilkan credential.
- Review paket AnalyticsDatasetSeeder yang sebelumnya disiapkan jika tersedia; perlakukan sebagai candidate, bukan implementasi yang sudah teruji.
- Identifikasi side effect registrasi, pembuatan UMKM, finalisasi evaluasi, serta model event. Repository sebelumnya menunjukkan `AuthService::register()` dapat memicu onboarding; `UmkmService::store()` memeriksa WhatsApp; `SixFactorEvaluationService::finalize()` membuat webhook outbox. Verifikasi ulang source aktual.
- Audit Hermes hanya pada boundary Laravel yang relevan; jangan ubah Docker, workflow, queue, maupun konfigurasi Hermes.

## 3. Non-negotiable requirements

- Tidak ada penamaan akademik pada class, file aplikasi, akun, data, konfigurasi, atau migration. Gunakan nama domain analitik yang wajar. Dataset harus tetap dapat dikenali sebagai sintetis, bukan diklaim sebagai data survei asli.
- Mendukung local, staging, dan production dengan implementasi yang sama; tidak bergantung pada `APP_ENV=local`, Faker, dependency dev-only, atau pembacaan `env()` langsung di runtime.
- Eksekusi eksplisit oleh operator; jangan daftarkan seeder baru untuk eksekusi otomatis `DatabaseSeeder` atau deployment rutin. Pertahankan `SampleDataSeeder`, `DemoSeeder`, dan behavior existing.
- Jangan menjalankan seeder pada database production aktual tanpa persetujuan eksplisit. Pengujian production mode dilakukan pada MySQL terisolasi terlebih dahulu.
- Jangan menjalankan `migrate:fresh`, `db:wipe`, truncate, atau operasi destruktif pada database existing. Jangan mengubah data akun lain atau menghapus evaluasi final.
- Tidak boleh ada webhook, email, WhatsApp, Telegram, geocoding eksternal, atau pemanggilan AI sebagai akibat seeding; jangan mematikan integrasi secara global. Jangan mengandalkan `withoutEvents()` saja karena outbox dipanggil eksplisit oleh service.
- Credential akun analitik diberikan lewat mekanisme aman, tidak di-hardcode, tidak dicetak, tidak disimpan plaintext. Gunakan hashing existing. Jangan memakai email/nomor kontak sungguhan.
- Gunakan MySQL untuk integration/concurrency test; jangan menjalankan full suite SQLite. Maksimum tiga subagent bila diperlukan.

## 4. Struktur implementasi

```text
database/seeders/
  AnalyticsDatasetSeeder.php
  Analytics/
    BusinessProfileSeeder.php
    ThreeDimensionHistorySeeder.php
    SixFactorHistorySeeder.php
```

Tambahkan support class, command, test, dokumentasi atau migration aditif bila diperlukan dan beralasan. Orchestrator adalah satu entry point; sub-seeder tidak boleh menjadi jalur bypass safety. Pertahankan konvensi repo. Tidak perlu commit/push tanpa instruksi eksplisit.

## 5. Dataset contract

| Entity | Target |
|---|---:|
| Akun analitik sintetis khusus | 1 |
| UMKM | 428 |
| Kota / provinsi / sektor | 10 / 5 / 5 |
| Periode historis | 12 bulan |
| Evaluasi final Tiga Dimensi per UMKM | 3 |
| Evaluasi final Enam Faktor per UMKM | 3 |
| Total Tiga Dimensi | 1.284 |
| Total Enam Faktor | 1.284 |
| Total evaluasi | 2.568 |
| Jawaban kuesioner | 20.544 (16 pertanyaan aktif) |
| Evaluasi per bulan per jenis | 107 |

Distribusi kota: Jakarta Selatan 43, Jakarta Timur 43 (DKI Jakarta); Bandung 43, Bekasi 43 (Jawa Barat); Semarang 43, Surakarta 43 (Jawa Tengah); Surabaya 43, Malang 43 (Jawa Timur); Tangerang 42, Tangerang Selatan 42 (Banten). Sesuaikan nomenklatur canonical aplikasi tanpa mengubah jumlah.

Distribusi sektor: Makanan & Minuman 120; Grosir & Retail 100; Jasa 85; Fashion & Kriya 75; Pertanian 48. Resolve master melalui slug/identifier yang valid, bukan hardcode PK. Distribusikan lintas kota.

## 6. P1-01 — Seeder foundation

Implementasikan orchestrator dengan preflight schema dan master data, explicit operator invocation, konfigurasi aman, dataset version/seed dan reference period, advisory lock MySQL dengan timeout dan release pada finally, satu transaksi atomik untuk seluruh dataset, final integrity validation, serta summary tanpa secret. Semua write dan lock harus memakai koneksi database yang sama. Hindari DDL dalam transaksi; jika migration diperlukan, jalankan terpisah melalui deployment normal.

Gunakan generator deterministik yang tidak memakai random global atau Faker. Seed, versi, dan periode referensi sama menghasilkan data bisnis sama. ID, password hash, metadata teknis yang intrinsik berubah tidak harus identik. Simpan periode referensi dan identitas cohort secara durable. Jangan hanya menggunakan keberadaan satu email sebagai bukti dataset lengkap; validasi ownership, version dan integrity. Jika konflik dengan akun non-seeder, dataset parsial, atau data existing telah berubah, fail closed dengan diagnosis; jangan menimpa atau auto-delete. Rerun dataset lengkap adalah no-op yang terverifikasi. Hindari menggeser periode pada rerun tanggal berbeda.

**AC01–AC06:** Artisan menemukan seeder; local/production mode didukung; config cache compatible; preflight gagal sebelum mutasi; master existing tidak berubah; seeder tidak berjalan otomatis.

## 7. P1-02 — Business profile

Buat satu akun sintetis khusus yang memiliki seluruh 428 profil supaya dashboard dan analitik terisolasi dari tenant lain. Gunakan email reserved/non-routable untuk identitas sintetis dan password yang diberikan operator dengan aman. Buat user dan UMKM melalui jalur persistence yang tidak memanggil onboarding maupun pemeriksaan kontak eksternal.

Isi nama usaha sintetis yang unik, sektor, provinsi, kota, omzet dan periode omzet, modal usaha, tahun berdiri, jumlah karyawan, nama pemilik sintetis, deskripsi, dan lokasi non-sensitif sesuai schema. Buat variasi masuk akal berdasarkan sektor. Field opsional kontak yang tidak diperlukan tetap null; consent notifikasi null/tidak aktif; jangan menghasilkan identitas legal atau nomor telepon acak yang mungkin nyata. Nama usaha tidak perlu mengandung istilah akademik. Identitas cohort dan dokumentasi harus tetap menjelaskan sifat sintetis.

**AC07–AC14:** tepat 428 UMKM, seluruhnya tenant analitik khusus, distribusi kota/provinsi dan sektor tepat, business identity unik, foreign key valid, dapat dibaca dashboard existing, tidak ada onboarding atau WhatsApp request.

## 8. P1-03 — Three Dimension History

Gunakan `QuestionnaireService`, `ScoringService`, serta `EvaluationService` apabila sesuai dan bebas side effect. Generate tiga evaluasi final per UMKM dengan jawaban lengkap dari opsi pertanyaan aktif. Master saat audit sebelumnya: INS 6 pertanyaan skala 1–3; ECT 4 skala 1–5; OPS 6 skala 1–5. Jika master aktual berbeda, laporkan dan fail closed terhadap kontrak jumlah; jangan ubah questionnaire sepihak.

Hitung INS, ECT, OPS, average score, dan kategori melalui business logic existing; jangan hardcode skor hasil. Variasikan jawaban antar dimensi dan antar UMKM. Cakup seluruh kategori kesehatan final yang attainable sesuai konfigurasi scoring aktual. Pastikan timestamp historis benar, tanpa clock global yang bocor ke request lain. Jika memakai test clock secara scoped dalam CLI, restore pada finally dan uji. Pertahankan `evaluation_answers` dan constraint unik jawaban per evaluasi/pertanyaan.

**AC15–AC21:** tepat 1.284 evaluasi final; 20.544 jawaban jika 16 pertanyaan aktif; seluruh pertanyaan terjawab; opsi valid; skor ulang cocok menurut rounding existing; kategori valid; tidak ada orphan evaluation/answer.

## 9. P1-04 — Six Factor History

Isi `sumber_daya`, `kepemimpinan`, `lingkungan_kerja`, `nilai_organisasi`, `stabilitas`, `kinerja` dengan integer 1–5. Tiga evaluasi final per UMKM. Total merupakan penjumlahan enam nilai. Kategori existing: 6–12 Kurang Sehat, 13–18 Cukup Sehat, 19–24 Sehat, 25–30 Sangat Sehat. Cakup seluruh skor 6–30. Target distribusi generator yang sebelumnya diusulkan: 360 Kurang Sehat, 309 Cukup Sehat, 308 Sehat, 307 Sangat Sehat. Verifikasi terhadap output aktual; jangan klaim target sebagai hasil pengujian.

Gunakan fungsi scoring domain bersama dengan service normal (ekstrak helper murni bila diperlukan dan regression-test) serta kontrak model/constraint existing. Jangan memanggil finalization flow yang menulis outbox. Jalur seed yang terkontrol harus menyimpan final yang valid tanpa mengubah perilaku finalisasi normal. `period_month` diturunkan dengan timezone Asia/Jakarta sesuai `SixFactorEvaluation::periodMonthFor()`; timestamp tersimpan sesuai konvensi UTC. Jangan mengubah final evaluation yang sudah tersimpan.

**AC22–AC29:** tepat 1.284 final, keenam faktor lengkap dan valid, skor penjumlahan tepat, kategori tepat, seluruh skor 6–30 muncul, distribusi target terbukti, period_month konsisten, nol outbox akibat seeder.

## 10. P1-05 — Historical data

Distribusikan tiga evaluasi tiap jenis per UMKM pada tiga bulan berbeda dalam jendela 12 bulan yang sama. Target 107 evaluasi per bulan per jenis (1.284 / 12). Gunakan periode referensi eksplisit, default 12 bulan kalender terakhir yang telah selesai, dan persist pilihan pada run pertama. Urutkan evaluasi kronologis; evaluasi Tiga Dimensi terkait tersedia pada atau sebelum timestamp Enam Faktor periode yang sama. Jangan buat timestamp future. Sediakan tren meningkat, menurun, dan stabil dengan variasi realistis; hasil harus diverifikasi, bukan hanya label generator. Pastikan latest evaluation sesuai query/service aplikasi dan period WIB benar pada boundary bulan.

**AC30–AC35:** 12 periode; masing-masing 107 evaluasi per jenis per bulan; tiga periode berbeda per UMKM; timestamp berurutan dan bukan future; keselarasan riwayat kedua jenis; tren meningkat/menurun/stabil terwakili.

## 11. P1-06 — Production hardening

- Gunakan transaksi atomik seluruh cohort dan lock MySQL lintas proses. Kegagalan di tengah proses tidak meninggalkan user/UMKM/evaluasi parsial. Release lock pada success/failure. Pastikan worker paralel tidak menghasilkan duplikasi.
- Rerun tidak membuat data baru, tidak mengubah credential atau record existing, dan tidak menggeser periode. Identitas cohort serta dataset version tidak boleh tertukar dengan user biasa. Konflik dan drift dilaporkan tanpa auto-repair destruktif.
- Gunakan mekanisme explicit opt-in yang sama pada semua environment; `--force` hanya konfirmasi bawaan Laravel, bukan pengganti preflight. Sediakan invocation yang cocok untuk interactive dan noninteractive dengan sumber secret aman, serta dokumentasikan. Jangan menambah perubahan konfigurasi yang wajib bagi fitur lain.
- Pastikan query dan runtime cukup efisien untuk 428 UMKM dan >20 ribu jawaban. Gunakan batch write jika aman; jaga validasi domain. Catat waktu aktual dan memory peak, bukan estimasi.
- Isolasi side effect melalui jalur persistence khusus. Buktikan nol webhook outbox, queue job, delivery, dan outbound HTTP/mail yang berasal dari cohort seed. Worker production normal tidak boleh dihentikan atau diubah. Verifikasi regression finalisasi normal tetap membuat outbox sebagaimana semestinya.
- Jangan memasukkan dataset sintetis ke laporan agregat pengguna riil. Pertahankan tenant scope; akses admin lintas tenant tidak diasumsikan tersedia.

**AC36–AC44:** rerun no-op; rollback failure injection; concurrency aman; konflik identitas fail closed; production mode dengan config cache PASS; no dependency dev-only; nol side effect; data pengguna lain unchanged; service normal tanpa regresi.

## 12. P1-07 — Testing, CSV, dashboard, evidence

Gunakan MySQL disposable/isolated dengan migration aktual, termasuk constraint yang relevan. Jangan memakai database existing sebagai target destructive tests. Uji:

1. Clean run dengan `APP_ENV=local` dan `APP_ENV=production` pada database terisolasi, termasuk konfigurasi cached dan autoload tanpa dependency dev-only bila tersedia.
2. Rerun dan dua proses concurrent dengan dataset identik; cek hasil hanya satu cohort dan count tetap.
3. Failure injection setelah sebagian writes; verifikasi rollback serta lock release.
4. Missing master, schema mismatch, invalid credential, konflik identitas, dan cohort drift: fail closed.
5. SQL integrity: total row count scoped tenant, distribusi kota/sektor/periode/kategori, orphan/duplicate check, answer validity, recompute skor kedua jenis, range coverage, latest records, consent null, outbox/queue nihil untuk cohort.
6. Regression terhadap seeder existing, tenant isolation, scoring, evaluation finalization, dan dashboard. Jika baseline sudah gagal, pisahkan dari regresi baru dengan bukti.
7. Dashboard authenticated (Playwright bila tersedia): akun analitik, daftar UMKM, filter dan pagination, detail Tiga Dimensi dan Enam Faktor, riwayat; cek console/page errors dan overflow yang relevan. Jangan pakai akun riil.
8. Ekspor 428 baris profil UMKM sintetis (satu baris per UMKM) ke `umkm-analytics-dataset.csv` dengan header stabil, UTF-8, tanpa password/token, email pribadi, atau data tenant lain. Sertakan skor terbaru kedua jenis dan periode bila relevan. Verifikasi jumlah baris data dan key unik; bila menguji impor, gunakan tabel staging pada schema disposable, bukan import ulang ke tabel aplikasi. CSV ini bukan dataset survei asli.
9. Jalankan PHP lint/Pint yang relevan dan targeted MySQL tests. Jangan menjalankan full SQLite suite. Catat command dan hasil aktual.

**AC45–AC52:** MySQL tests PASS; production mode PASS; dashboard PASS; integritas SQL PASS; CSV 428 baris valid; regression PASS; bukti side effect nihil; dokumentasi/runbook lengkap.

## 13. Invocation dan deployment contract

Entry point yang diharapkan:

```bash
php artisan db:seed --class=AnalyticsDatasetSeeder --force
```

Dokumentasikan prerequisite master data/migration, input periode/seed, cara memasok credential secara aman pada PowerShell dan deployment Linux, perilaku noninteractive, serta rerun. Jika implementasi memerlukan command wrapper yang lebih aman, pertahankan entry point yang jelas dan laporkan alasan perubahan. Tidak perlu mengganti APP_ENV atau mematikan Hermes. Jangan menjalankan command pada production aktual tanpa izin. Jangan mencetak credential pada screenshot/evidence.

## 14. Deliverables dan final handoff

- Source code lengkap dan test yang terintegrasi repository lokal.
- CSV sintetis 428 baris yang telah divalidasi.
- Dokumentasi penggunaan, prerequisite, batasan, dan recovery non-destruktif.
- Evidence machine-readable atau Markdown untuk count, distribusi, scoring, integrity, concurrency, rollback, runtime, dan regression.
- Screenshot dashboard dan output SQL jika QA dapat dilakukan.
- Final handoff berisi verdict PASS/PARTIAL/FAIL untuk setiap AC01–AC52, command dan hasil aktual, daftar file baru/berubah, perubahan schema/config (jika ada), blocker, dan risiko tersisa. Tandai secara jelas pengujian production mode terisolasi versus deployment production aktual.

## 15. Definition of done dan stop condition

P1 FULL PASS hanya jika semua AC yang wajib terbukti dengan MySQL dan tidak ada regresi atau side effect terkait seed. Jangan mengklaim production runtime PASS bila hanya diuji pada environment production-mode terisolasi. Jika bukti belum tersedia, nyatakan PARTIAL atau FAIL beserta blocker dan cara reproduksi. Jangan lanjut ke P2, jangan mengembangkan MySQL Node, jangan ubah Hermes/Docker, jangan deploy production, dan jangan mengirim Telegram. Berhenti setelah handoff P1.
