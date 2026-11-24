# P2 — Schema & Data Contract, JoyUMKM × Hermes Agent (Docker)

Status: **siap review owner**. Tidak ada email terkirim, webhook tetap nonaktif (`HERMES_WEBHOOK_ENABLED=false`), tidak ada pemanggilan model AI nyata, tidak ada `migrate:fresh`, tidak ada data yang dihapus, dan P3 belum dimulai.

Referensi: `docs/audit/P0_JoyUMKM_Hermes_Integration_Audit.md`, `docs/audit/P1_JoyUMKM_Hermes_Stabilization_Report.md`, `docs/evidence/p2-data-contract.md`.

## 1. Ringkasan verdict

| Task | Verdict | Catatan singkat |
|---|---|---|
| P2-01 `intelligence_artifacts` | **PASS** | Tabel, model immutable, constraint, dan test lulus di MySQL terisolasi. PDF/XLSX generator tidak dibangun (di luar scope). |
| P2-02 `intelligence_deliveries` | **PASS** | Pemisahan saluran artifact/email, unik NULL-safe, dan idempotensi lulus test. Tidak ada pengiriman. |
| P2-03 `artifact_status` | **PASS** | Migrasi backward-compatible. Report JSON immutable via trigger dan model. Report lama default `pending`. |
| P2-04 Consent, suppression, kontrak pull | **PASS** | Default tanpa persetujuan untuk UMKM lama. Fingerprint HMAC. Kontrak pull didefinisikan, endpoint P5 belum dibangun. |
| P2-05 Periode WIB & rekap | **PARTIAL** | Logika periode WIB, rekap, dan data gap lulus test. Tetapi offset 7 jam di tingkat MySQL (T-1) belum diselesaikan karena butuh keputusan owner. |
| P2-06 Kontrak event & manifest | **PASS** | Event v2 additive (`period_month` opsional). Tidak ada perubahan breaking. Manifest tervalidasi. |
| P2-07 Test & regresi | **PASS** untuk test P2. Regresi penuh sesuai baseline. Lihat bagian 3. |

Verdict keseluruhan P2: **PARTIAL**, karena satu temuan P2-05 (T-1) dan beberapa langkah deploy memerlukan keputusan owner (lihat bagian 6).

## 2. Migrasi dan model

Migrasi (belum diterapkan ke dev DB `joyumkm-v2`; `migrate:status` menunjukkan keenamnya *Pending*):

1. `2026_11_23_153536_add_artifact_status_to_intelligence_reports_table` — kolom `artifact_status` (`pending`/`complete`/`partial`), unique `(id, event_id)`, trigger immutable.
2. `2026_11_23_153537_create_intelligence_artifacts_table` — FK komposit `(report_id, event_id)`, unique `(report_id, kind)`, unique `(storage_disk, storage_path)`, CHECK kind/MIME/ukuran/SHA-256, trigger append-only.
3. `2026_11_23_153538_create_intelligence_deliveries_table` — unique `(event_id, channel, recipient_fingerprint)`, unique `idempotency_key`, CHECK channel/status/aturan rekomendasi/`sent_at`/`error_code`.
4. `2026_11_23_153539_add_notification_consent_to_umkms_table` — `notification_consent_at`, `notification_consent_version`, `notification_consent_by_user_id`, CHECK pasangan.
5. `2026_11_23_153540_create_email_suppressions_table` — hanya fingerprint, CHECK alasan dan format.
6. `2026_11_23_153541_add_period_month_to_six_factor_evaluations_table` — `period_month CHAR(7)`, backfill di PHP dengan WIB, CHECK format dan konsistensi dengan status final.

Model baru: `IntelligenceArtifact`, `IntelligenceDelivery`, `EmailSuppression`. Model diubah: `IntelligenceReport` (immutable, `artifact_status` satu-satunya kolom yang bisa berubah), `SixFactorEvaluation` (`period_month` diturunkan saat menyimpan), `Umkm` (cast consent). Factory baru: artifact, delivery, email suppression.

Setiap migrasi memiliki `down()`. Rollback diuji di test migrasi.

## 3. QA dan regresi

Lingkungan: MySQL 8.4.3, database test terisolasi `joyumkm_testing` (dijaga `tests/TestCase.php`). Dev DB tidak disentuh.

| Suite | Hasil | Baseline sebelum P2 | Kesimpulan |
|---|---|---|---|
| Filter P2 (`Intelligence\|SixFactor\|HermesWebhook`) | **276 / 276 PASS** (2635 assertion) | — | Lulus |
| Full Laravel suite | **409 test: 404 pass, 3 fail, 2 error** | 363 test: 358 pass, 3 fail, 2 error | Tidak ada regresi baru |
| Hermes `test_callback` | **58 OK** | 58 OK | Sama |
| Hermes `test_jmi` + `test_review` | **158 dijalankan: 48 failures, 25 errors** | 48 failures, 25 errors | Jumlah sama |
| Pint (`--dirty`) | PASS | — | Lulus |

Kegagalan yang tersisa pada full suite, identik dengan baseline P1 (`DemoSeederTest` ×2 failure; `SampleDataSeederTest` ×1 failure dan ×2 error). Ini bukan regresi P2.

Catatan jujur tentang Hermes: perbandingan adalah jumlah, bukan nama test per test. Hermes tidak diubah di P2.

Test yang ditambahkan P2: `IntelligenceArtifactSchemaTest`, `IntelligenceDeliverySchemaTest`, `IntelligenceRecipientConsentTest`, `IntelligenceArtifactContractTest`, `SixFactorPeriodMonthTest`, `SixFactorPeriodMigrationTest`, `SixFactorMonthlyRecapTest`. Test yang diubah: `SixFactorEvaluationDatabaseTest`, `HermesWebhookOutboxTest`.

Cakupan sesuai brief P2-07:
- Migrasi: up/down, backfill WIB, rollback dengan urutan dependensi.
- Model: immutable (LogicException dan trigger 1644), relasi, `SUMMARY_COLUMNS`.
- Constraint: CHECK (3819), unique (1062), FK (1452), trigger (1644).
- Batas WIB: `16:59:59` vs `17:00:00` UTC, pergantian tahun, tahun kabisat.
- Rekap historis: Final terbaru per bulan, histori tetap utuh, data gap, V1 COALESCE.
- Consent: default tanpa persetujuan, urutan alasan eligibility, tanpa PII.
- Idempotensi: kunci deterministik, unik per penerima, NULL-safe.

## 4. Temuan

**T-1 — Offset 7 jam di tingkat MySQL (P2-05, belum diselesaikan).** Koneksi memakai `time_zone=SYSTEM` (zona sistem `SE Asia Standard Time`, UTC+7). Laravel menulis UTC sebagai nilai wall-clock, tetapi MySQL menafsirkannya sebagai waktu lokal dan menyimpan instan yang 7 jam lebih awal. Probe read-only pada tiga baris `six_factor_evaluations` final:

```
{"id":1,"raw":"2026-11-20 10:41:47","epoch_mysql":1795146107,"epoch_as_utc_string":1795171307,"difference_seconds":25200}
```

Konsekuensi: aplikasi konsisten karena round-trip Laravel benar, tetapi `UNIX_TIMESTAMP`, `DATE_ADD`, `CONVERT_TZ`, dan pembaca eksternal melihat instan yang salah. Perbaikannya butuh perubahan zona koneksi dan migrasi data historis, jadi tidak dilakukan di P2 (larangan menghapus data historis). Perhitungan periode dilakukan di PHP, sehingga tidak terpengaruh.

**T-2 — Pepper belum ada di environment.** `INTELLIGENCE_RECIPIENT_PEPPER` ditambahkan di `.env.example`, tetapi belum diisi di `.env` dev. Tanpa nilai minimal 32 karakter, layanan eligibility melempar `RuntimeException`. Ini sengaja: fingerprint tidak boleh dibuat tanpa kunci.

**T-3 — Dev DB belum dimigrasi (sesuai instruksi).** Enam migrasi P2 masih *Pending* di `joyumkm-v2`. Verifikasi dilakukan hanya di `joyumkm_testing`.

**T-4 — Test migrasi backfill memakai `DatabaseTruncation`.** Data yang di-commit dibersihkan di `tearDown`. Hook `beforeTruncatingDatabase` memastikan nama database `joyumkm_testing` sebelum truncation berjalan. Bug kebocoran data yang ditemukan saat QA sudah diperbaiki; tiga test `UmkmApiTest` sempat gagal karena itu dan sekarang lulus.

## 5. Daftar file

Baru (P2):
- `database/migrations/2026_11_23_153536_…` sampai `…153541_…` (6 file)
- `app/Models/IntelligenceArtifact.php`, `app/Models/IntelligenceDelivery.php`, `app/Models/EmailSuppression.php`
- `database/factories/IntelligenceArtifactFactory.php`, `IntelligenceDeliveryFactory.php`, `EmailSuppressionFactory.php`
- `app/Services/IntelligenceArtifactContract.php`, `IntelligenceRecipientFingerprint.php`, `IntelligenceRecipientEligibilityService.php`, `EmailSuppressionService.php`, `SixFactorMonthlyRecapService.php`
- `tests/Feature/IntelligenceArtifactSchemaTest.php`, `IntelligenceDeliverySchemaTest.php`, `IntelligenceRecipientConsentTest.php`, `IntelligenceArtifactContractTest.php`, `SixFactorPeriodMonthTest.php`, `SixFactorPeriodMigrationTest.php`, `SixFactorMonthlyRecapTest.php`
- `docs/evidence/p2-data-contract.md`, `docs/audit/P2_JoyUMKM_Schema_Data_Contract_Report.md`

Diubah (P2):
- `app/Models/IntelligenceReport.php`, `app/Models/SixFactorEvaluation.php`, `app/Models/Umkm.php`
- `app/Http/Resources/IntelligenceReportSummaryResource.php` (`artifact_status`)
- `app/Services/HermesWebhookOutbox.php` (`period_month` opsional)
- `config/services.php`, `.env.example`
- `tests/Feature/HermesWebhookOutboxTest.php`, `tests/Feature/SixFactorEvaluationDatabaseTest.php`
- `docs/HERMES_WEBHOOK.md` (`period_month` opsional)

Catatan: `git status` juga menampilkan file lain yang sudah ada sebelum P2 (frontend P4-05, controller/callback P4-04C, dan PRD). File-file itu bukan bagian dari pekerjaan P2 ini dan tidak diubah di sesi ini.

## 6. Keputusan yang dibutuhkan owner

1. **T-1:** setujui apakah zona koneksi MySQL dibuat UTC dan data historis diperbaiki dengan migrasi terpisah. Tanpa ini, P2-05 tetap PARTIAL.
2. **T-2:** isi `INTELLIGENCE_RECIPIENT_PEPPER` (minimal 32 karakter) di environment yang akan memakai eligibility.
3. **T-3:** jalankan migrasi P2 di dev DB saat siap. Belum dilakukan.
4. Konfirmasi kontrak pull-recipient (bagian 5 `p2-data-contract.md`) sebagai dasar P5, termasuk alasan eligibility dan larangan PII.

Belum dikerjakan (di luar scope P2 atau menunggu owner): pengiriman email (P5, Gmail SMTP K11), endpoint unduh privat, generator PDF/XLSX, installasi scheduler OS, dan P3.
