# P1 — Stabilisasi & Rekonsiliasi JoyUMKM × Hermes

Tanggal pelaksanaan: 2026-11-23 (UTC untuk log Hermes dan outbox Laravel).
Referensi: `docs/audit/P0_JoyUMKM_Hermes_Integration_Audit.md`. Keputusan owner K1–K10 pada P0 disetujui. K11 (Gmail) tetap dikonfigurasi pada P5.
Batas scope: tidak ada migration, tidak ada perubahan kontrak callback, tidak ada replay AI, tidak ada email nyata, tidak ada `git reset`/`clean`/force push, tidak ada `migrate:fresh` pada database dev.

---

## 0. Ringkasan dan status gate

| Gate | Status | Alasan singkat |
|---|---|---|
| **P1-A** Rekonsiliasi evaluasi 9, 10, 11 | **PARTIAL** | Terbukti legacy (diproses sesi agent sebelum intake jmi, tanpa job, laporan, atau callback). Status `delivered` di Laravel tetap dibiarkan, didokumentasikan sebagai `legacy_agent_processed`. Asal-usul 7 job archived untuk evaluasi 11 belum terbukti sepenuhnya. |
| **P1-B** 7 job `archived_unmatched` dipertahankan | **PASS** | Tujuh direktori job, `job.json`, `report.json`, dan `evidence.json` masih ada. Tidak ada yang dihapus. Metadata registry bertambah, tidak ada yang diganti. |
| **P1-C** Cron `jmi-recover` | **PASS** | Berjalan setiap 5 menit. 102 eksekusi tercatat, semuanya `completed` pada tiga run terakhir, `cron_incidents` kosong. |
| **P1-D** Queue worker `hermes-webhooks` | **PARTIAL** | Tidak ada proses worker di host saat diperiksa. Tidak ada backlog (0 job di queue, semua outbox `delivered`). Worker wajib dijalankan sebelum pengiriman diaktifkan kembali. |
| **P1-E** Laravel scheduler | **PARTIAL** | Perintah terjadwal `hermes:webhook-scan` terbukti berjalan (exit 0). Tidak ada proses `schedule:run`/`schedule:work` dan tidak ada Windows Scheduled Task untuk artisan. Scheduler harus dipasang di mesin dev. |
| **P1-F** Test Hermes `test_jmi` dan `test_review` | **FAIL (pre-existing), tanpa regresi** | 48 failure + 25 error pada kode saat ini. Set kegagalannya identik dengan baseline pre-P4-04C (73 dari 73, selisih 0). |
| **P1-G** Test Laravel terkait | **PASS** | 230 test lolos (2400 assertion) pada seluruh suite Hermes, Outbox, SixFactor, dan Intelligence. |
| **P1-H** Suite Laravel penuh | **PARTIAL** | 358 lulus dari 363. 5 kegagalan di `DemoSeederTest` dan `SampleDataSeederTest`, di luar scope P1 (lihat §4.3). |
| **P1-I** Dev tidak memicu AI nyata | **PASS** (dengan catatan) | `HERMES_WEBHOOK_ENABLED=false` di dev. Test Laravel dipaksa `false` oleh `phpunit.xml`. Test Hermes memakai model fake. Tidak ada turn model baru di log selama run test. |
| **P1-J** Duplikasi `HERMES_INTELLIGENCE_CALLBACK_SECRET` | **PASS** | Baris duplikat dihapus. Nilai secret tidak berubah (hash sebelum dan sesudah sama). |

**Status P1 keseluruhan: PARTIAL.** Penyebabnya: P1-D dan P1-E memerlukan tindakan di mesin dev, dan asal-usul 7 job archived belum terbukti penuh. Keduanya tidak memblokir P2, tetapi perlu keputusan owner (lihat §9).

---

## 1. Investigasi evaluasi 9, 10, 11 (K1)

### 1.1 Data Laravel (read-only, DB `joyumkm-v2`)

| Evaluasi | Event ID (UUID penuh) | Versi | Dibuat (UTC) | Status outbox | Attempts | Delivered (UTC) |
|---|---|---|---|---|---|---|
| 9 | `78b9bc38-0cbb-430a-b1c5-1fdc8d6e7b00` | 1 | 2026-11-22 13:10:20 | delivered | 1 | 13:10:23 |
| 10 | `277c219f-01ba-4582-90ed-92ece6e614f3` | 2 | 2026-11-22 13:58:36 | delivered | 1 | 13:58:37 |
| 11 | `ba108a92-2609-4a0a-8b58-04bbf2afaeec` | 2 | 2026-11-22 14:31:03 | delivered | 1 | 14:31:05 |

Tidak ada baris `webhook_outbox` lain di atas id 6. `AUTO_INCREMENT` `webhook_outbox` = 7. `intelligence_reports` berisi 3 baris (evaluasi 12, 13, 14).

### 1.2 Bukti dari Hermes

| Bukti | Hasil |
|---|---|
| `gateway.log` 22 Nov 13:10:23, 13:58:37, 14:31:05 | `POST event=evaluation.finalized route=joyumkm-events` dengan delivery ID sama dengan event ID Laravel. |
| `agent.log` pada menit yang sama | Turn `platform=webhook` dengan model agent (`gpt-6.2-sol` untuk event 9, `claude-sonnet-5-5` untuk event 10 dan 11). Respons agent 325, 948, dan 1094 karakter. Tidak ada entri intake jmi. |
| `backups/webhook_subscriptions.json.pre-p4-03b-20261122T145827Z` (dibuat 22 Nov 14:58:27) | Route `joyumkm-events` **tanpa** key `script`. Prompt berisi instruksi verifikasi P4-02, dengan `{__raw__}` berisi data evaluasi. |
| `webhook_subscriptions.json` saat ini | Route memiliki `script: joyumkm_intake.py`, diubah pada P4-03B. |
| `state/jobs/` | Tidak ada direktori untuk ketiga event ID. |
| Laporan dan callback | Tidak ada `report.json` dan tidak ada entri callback untuk ketiga event. |

### 1.3 Kesimpulan

**Evaluasi 9, 10, 11 terbukti legacy.** Ketiganya diterima oleh route webhook saat route masih berupa agent prompt (sebelum P4-03B), diproses sesi agent, dan tidak menghasilkan job, laporan, atau callback jmi.

Operasionalnya:
- Status `delivered` di Laravel berarti receiver mengakui HTTP `202`. Status ini **tidak** membuktikan adanya laporan jmi.
- Status `delivered` **tidak diubah** (histori outbox tidak disentuh).
- Status operasional dicatat sebagai `legacy_agent_processed` di `callback_registry.json` Hermes (lihat §6.2). Key ini hanya dokumentasi dan tidak dibaca oleh `callback.py`.
- Tidak ada replay AI. Evaluasi 9–11 tidak punya laporan dan tidak akan dibuatkan laporan otomatis. Jika owner ingin laporan untuk evaluasi tersebut, itu keputusan terpisah dan perlu persetujuan eksplisit.

---

## 2. Tujuh job `archived_unmatched` (evaluasi 11)

### 2.1 Pelestarian

Tujuh job masih ada: `10fbd95d`, `1518f3e3`, `2815148a`, `46cfbb3f`, `64d8a492`, `9709bad0`, `b4357dcc`. Setiap direktori berisi `job.json`, `report.json`, `evidence.json`, dan `payload.raw.json`. Status callback tetap `archived_unmatched` dengan alasan `NO_LARAVEL_OUTBOX_PAIR`. Tidak ada job, laporan, atau outbox yang dihapus pada P1.

### 2.2 Asal-usul (bukti parsial, NOT VERIFIED penuh)

| Bukti | Temuan |
|---|---|
| Event ID tujuh job | Tidak ada di `webhook_outbox`. Auto-increment outbox = 7, sehingga tidak ada baris outbox di atas id 6 pada tabel ini. |
| Waktu job dibuat | 22 Nov 14:58–15:45 UTC. Evaluasi 11 difinalisasi pada 14:31:03. |
| POST ke route | `gateway.log`: POST dengan `delivery` ID tujuh job pada 14:58:38 dan 14:59:44–49 (`prompt_len` 366–380). Route sudah memakai script (setelah P4-03B). |
| Konteks sesi | Sesi CLI lokal (`platform=local`/`cli`) mengirim pesan 13.695 karakter pada 14:57:37, 14:58:41, dan 14:59:47, tepat sebelum POST tersebut. Isi sesi tidak diperiksa. |
| Status | Pola ini konsisten dengan pengiriman uji manual, bukan dispatch Laravel. Namun **asal-usul pasti tidak terbukti**, karena tidak ada pencatatan sumber POST di gateway. |

**Verdict: NOT VERIFIED (asal-usul).** Diterima sebagai historis sesuai K2, dan dicatat di registry sebagai `archived_unmatched`. Keputusan owner K2 tetap diperlukan untuk penutupan formal.

---

## 3. Verifikasi operasional

### 3.1 Hermes — cron `jmi-recover`

| Item | Bukti | Hasil |
|---|---|---|
| Definisi | `cron/jobs.json`: `script: jmi_recover.py`, `schedule: every 5m`, `enabled: true`, `state: scheduled` | Sesuai |
| Eksekusi | `cron/executions.db` untuk job `5fa98346fe3a`: 102 baris. Tiga run terakhir `completed` (15:14:43, 15:20:43, dan sebelumnya). | PASS |
| Insiden | `cron_incidents`: 0 baris | PASS |
| Proses | Ticker Hermes aktif (`ticker_heartbeat` 15:12 UTC). Scheduler internal Hermes memegang jadwal ini. | PASS |

### 3.2 Laravel — queue worker `hermes-webhooks`

| Item | Bukti | Hasil |
|---|---|---|
| Konfigurasi | `QUEUE_CONNECTION=database`. `DeliverHermesWebhook` memakai queue `hermes-webhooks`, `tries=1`, `timeout=20`. | Sesuai |
| Proses worker | Tidak ditemukan proses `php artisan queue:work` di host. | **Tidak berjalan** |
| Backlog | `jobs` dengan queue `hermes-webhooks` = 0. Outbox: 6 `delivered`, 0 `pending`/`retry_wait`/`dead`. | Tidak ada antrean |

### 3.3 Laravel — scheduler

| Item | Bukti | Hasil |
|---|---|---|
| Definisi | `routes/console.php`: `Schedule::command('hermes:webhook-scan')->everyMinute()` | Sesuai |
| Eksekusi perintah | `php artisan hermes:webhook-scan` → "Pengiriman webhook ditunda (disabled)", exit 0 | Perintah berfungsi |
| Proses scheduler | Tidak ada `schedule:run`/`schedule:work` di host. Tidak ada Windows Scheduled Task yang menjalankan `artisan` (38 task non-Microsoft diperiksa). | **Tidak terpasang** |

Konsekuensi: saat ini tidak ada event yang tertunda, sehingga tidak ada kerugian langsung. Namun begitu `HERMES_WEBHOOK_ENABLED=true`, scan dan worker wajib berjalan. Lihat runbook §7.

---

## 4. Test

### 4.1 Hermes — `tests.test_jmi` dan `tests.test_review`

Kondisi run (sama untuk kode saat ini dan baseline):
- `JMI_HOME` menunjuk ke direktori sementara baru (`/tmp/jmi-p1-current.*` dan `/tmp/jmi-p1-baseline.*` di container).
- `JMI_TEST_MODE=1` dan `JMI_FAKE_MODEL=tests/fake_model.py`, sehingga `hermes_model_runner` tidak pernah memanggil `hermes -z` (model nyata).
- `INTELLIGENCE_CALLBACK_URL` tidak diset dan `JMI_HOME` sementara tidak punya `callback.env`, sehingga tidak ada callback ke Laravel.
- `PYTHONDONTWRITEBYTECODE=1`.

| Run | Kode | Hasil |
|---|---|---|
| Current | `/opt/data/joyumkm-intelligence` (P4-04C) | Ran 158, **FAILED (failures=48, errors=25)** |
| Baseline | Arsip `jmi-pre-p4-04c-20261123.tar.gz` (tanpa `state/`) | Ran 158, **FAILED (failures=48, errors=25)** |

**Perbandingan set:** 73 nama kegagalan unik pada current dan 73 pada baseline. Selisih = 0. Tidak ada regresi dari P4-04C. Kegagalan ini bersifat pre-existing (validator V4 vs fixture lama, sesuai catatan memori), sehingga status tetap **FAIL (pre-existing)**, bukan PASS.

Catatan: kegagalan di `tests.test_jmi` dan `tests.test_review` tidak diperbaiki karena di luar scope stabilisasi.

### 4.2 Laravel — test terkait

| Perintah | Hasil |
|---|---|
| `php artisan test --compact` pada 11 file: HermesWebhookSignatureTest, HermesWebhookOutboxTest, HermesWebhookWorkerTest, SixFactorEvaluation{Service,Concurrency,Database,Api}Test, SixFactorSummaryApiTest, IntelligenceCallback{,Concurrency}Test, IntelligenceReportApiTest | **230 passed**, 2400 assertion |

Database test: `phpunit.xml` memaksa `DB_DATABASE=joyumkm_testing` (`force="true"`), dan `tests/TestCase.php` menghentikan run jika koneksi bukan `joyumkm_testing`.

### 4.3 Laravel — suite penuh

Hasil: **363 test, 358 passed, 3 failure, 2 error** (3.556 assertion).

| Test | Pesan | Verifikasi |
|---|---|---|
| `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores` | `users` diharapkan 1 entri, ditemukan 4 | Gagal juga saat file dijalankan sendiri |
| `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` | sama | sama |
| `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` | 4 evaluasi melebihi batas 3 | sama |
| `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` | error argumen fungsi test | bug di test |
| `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` | "Database connection [...] not configured" | bug di test |

Status: **pre-existing dan di luar scope P1**. File test dan seeder ini tidak termasuk perubahan P1 (P1 tidak menyentuh kode PHP). Kegagalan deterministik, tidak bergantung urutan. **Belum diverifikasi terhadap commit HEAD yang bersih** (tidak dilakukan stash atau reset karena larangan scope). Perbaikan dicatat sebagai item terpisah.

---

## 5. Keamanan pengujian dev (P1-I)

| Jalur | Mekanisme | Bukti |
|---|---|---|
| Test Laravel tidak memicu pengiriman | `phpunit.xml`: `HERMES_WEBHOOK_ENABLED` `force="true"` = `false`; `QUEUE_CONNECTION=sync`; `MAIL_MAILER=array`. | Dikonfirmasi di `phpunit.xml` baris 36. |
| Dev tidak mengirim event baru | `.env`: `HERMES_WEBHOOK_ENABLED=false` (sebelumnya `true`). | Tinker: `enabled=false`, `configurationIssue=disabled`. |
| Test Hermes tidak memanggil model nyata | `JMI_TEST_MODE=1` dan `JMI_FAKE_MODEL` di semua run. Log agent: turn terakhir 23 Nov 04:00 UTC, tidak ada turn baru selama run test. | `agent.log` (277 turn_context, yang terakhir 04:00:05). |

Catatan: finalisasi di dev saat `HERMES_WEBHOOK_ENABLED=false` **tetap menulis baris outbox** berstatus `pending` (tidak dikirim). Baris ini akan terkirim pada scan berikutnya setelah diaktifkan. Lihat §7.

---

## 6. Perubahan operasional

### 6.1 `.env` (lokal, tidak di-track git)

| Item | Sebelum | Sesudah |
|---|---|---|
| `HERMES_WEBHOOK_ENABLED` | `true` | `false` |
| Baris `HERMES_INTELLIGENCE_CALLBACK_SECRET` | 2 (baris 91 di blok webhook dan baris 95 di blok terdokumentasi) | 1 (baris 95 dipertahankan karena berada di blok ber-komentar) |
| Nilai secret | — | Tidak berubah. Panjang 64 karakter, hash sebelum dan sesudah sama. |
| Format file | LF, tanpa BOM | LF, tanpa BOM. Newline akhir dipertahankan. |

Nilai secret tidak ditampilkan di mana pun. Perubahan dilakukan dengan operasi byte-level di PowerShell.

### 6.2 Hermes — `jmi/callback_registry.json` (container `hermes-agent`)

| Item | Sebelum | Sesudah |
|---|---|---|
| Key | `version`, `verified_at`, `verified_against`, `legacy_cutoff`, `archive_reason`, `note`, `verified_eligible` (3), `archived_unmatched` (7) | Sama, ditambah `legacy_agent_processed` (3 event 9, 10, 11) |
| Key lama | — | Tidak ada yang diubah |
| Pembaca | `callback.py` baris 198–199 membaca `legacy_cutoff`, `archived_unmatched`, `verified_eligible`, `archive_reason` saja | Tidak terpengaruh. Modul `jmi.callback` masih bisa di-import. |
| Backup | — | `backups/callback_registry.json.pre-p1-20261123` (1628 byte, versi asli) |
| Kepemilikan dan izin | `hermes:hermes`, `644` | Sama |

### 6.3 Yang tidak diubah

- `webhook_outbox`: tidak ada baris yang diubah atau dihapus.
- `intelligence_reports`: tidak ada baris yang diubah. Tidak ada laporan yang diregenerasi.
- `state/jobs/*`: tidak ada yang diubah atau dihapus.
- Route Hermes `joyumkm-events`: tidak diubah.
- Kontrak callback: tidak diubah.
- Migration: tidak ada.
- Git: tidak ada commit atau operasi git.

---

## 7. Runbook: menonaktifkan dan mengaktifkan kembali pengiriman Hermes

### 7.1 Kondisi saat ini (dev)
`HERMES_WEBHOOK_ENABLED=false`. Finalisasi evaluasi tetap berhasil dan menulis outbox `pending`, tetapi tidak dikirim ke Hermes dan tidak memicu analisis AI.

### 7.2 Mengaktifkan kembali (urutan wajib)
1. **Pastikan receiver yang dituju.** `HERMES_WEBHOOK_URL` harus menunjuk ke receiver yang dimaksud. `HERMES_WEBHOOK_ALLOWED_HOST` harus cocok dengan host-nya.
2. **Periksa backlog.** Jalankan `php artisan hermes:webhook-status`. Setiap baris `pending` atau `retry_wait` yang terbentuk selama nonaktif akan **dikirim pada scan berikutnya** dan memicu analisis AI. Putuskan terlebih dahulu apakah baris itu boleh dikirim. Jangan mengubah status outbox secara manual untuk menyembunyikannya.
3. **Ubah `.env`:** `HERMES_WEBHOOK_ENABLED=true`. Tidak ada config cache di `bootstrap/cache/` (hanya `services.php` dan `packages.php`), jadi tidak perlu `config:clear`. Verifikasi dengan tinker: `config('services.hermes_webhook.enabled')` dan `app(HermesWebhookSender::class)->configurationIssue()` harus `null`.
4. **Jalankan queue worker** (wajib, jika tidak ada pengiriman tidak terjadi):
   `php artisan queue:work database --queue=hermes-webhooks --tries=1 --timeout=20`
5. **Jalankan scheduler** (wajib, untuk scan dan recovery):
   `php artisan schedule:work` (development) atau Windows Task Scheduler yang menjalankan `php artisan schedule:run` setiap menit.
6. **Verifikasi:** `php artisan hermes:webhook-status` dan periksa log Hermes `gateway.log`.

### 7.3 Menonaktifkan lagi
Set `HERMES_WEBHOOK_ENABLED=false`. Tidak ada tindakan lain yang diperlukan. Baris outbox yang sudah `delivered` tidak berubah.

### 7.4 Larangan operasional
- Jangan finalisasi evaluasi di dev saat `HERMES_WEBHOOK_ENABLED=true` tanpa receiver uji.
- Jangan menjalankan `migrate:fresh` atau perintah migrasi lain dengan `--env=testing` (tidak ada `.env.testing`; perintah itu mengarah ke `joyumkm-v2`).

---

## 8. Matriks sebelum/sesudah

| # | Item | Sebelum P1 | Sesudah P1 | Bukti |
|---|---|---|---|---|
| 1 | Status evaluasi 9–11 | `delivered` tanpa penjelasan | `delivered` (tidak diubah) + `legacy_agent_processed` terdokumentasi | §1, `callback_registry.json` |
| 2 | Job archived | 7 | 7 (tidak dihapus) | §2.1 |
| 3 | Asal-usul 7 job | NOT VERIFIED | NOT VERIFIED (bukti parsial) | §2.2 |
| 4 | `HERMES_WEBHOOK_ENABLED` dev | `true` | `false` | §6.1 |
| 5 | Baris duplikat secret | 2 | 1 | §6.1 |
| 6 | Cron `jmi-recover` | NOT VERIFIED | PASS (102 eksekusi) | §3.1 |
| 7 | Queue worker `hermes-webhooks` | NOT VERIFIED | PARTIAL (tidak berjalan, tidak ada backlog) | §3.2 |
| 8 | Scheduler Laravel | NOT VERIFIED | PARTIAL (perintah berjalan, proses tidak terpasang) | §3.3 |
| 9 | `tests.test_jmi` + `tests.test_review` | NOT RUN | 48 F + 25 E, identik dengan baseline (0 regresi) | §4.1 |
| 10 | Test Laravel terkait | Tidak dijalankan pada P1 (P0: 159 test) | 230 passed | §4.2 |
| 11 | Suite Laravel penuh | NOT RUN | 358/363 (5 gagal di seeder, pre-existing) | §4.3 |
| 12 | Pengujian dev memicu AI | Mungkin (`ENABLED=true`) | Tidak (ENABLED=false, test dipaksa aman) | §5 |

---

## 9. Daftar perubahan file

| Lokasi | File | Jenis |
|---|---|---|
| Repo | `docs/audit/P1_JoyUMKM_Hermes_Stabilization_Report.md` | Baru (laporan ini) |
| Repo lokal (tidak di-track) | `.env` | Edit: 1 baris dihapus, 1 nilai diubah |
| Container `hermes-agent` | `/opt/data/joyumkm-intelligence/jmi/callback_registry.json` | Edit: key `legacy_agent_processed` ditambahkan |
| Container `hermes-agent` | `/opt/data/joyumkm-intelligence/backups/callback_registry.json.pre-p1-20261123` | Backup baru |
| Container `hermes-agent` | `/tmp/jmi-baseline/`, `/tmp/p1-*.log`, `/tmp/jmi-p1-*` | Artefak sementara test (bisa dihapus setelah review) |

Tidak ada perubahan pada kode PHP, migration, route, konfigurasi, test, atau `.env.example`.

---

## 10. Handoff ke P2 dan keputusan owner

**Kesiapan masuk P2: belum penuh, tetapi tidak diblokir.** P2 (schema dan kontrak data) tidak bergantung pada P1-D dan P1-E, sehingga bisa dimulai setelah review owner. Namun item berikut perlu keputusan atau tindakan sebelum pengiriman dev diaktifkan kembali:

1. **Owner: setujui atau tolak keberadaan `legacy_agent_processed`** untuk evaluasi 9–11 (dicatat di registry, tidak ada laporan baru).
2. **Owner (K2):** tutup asal-usul tujuh job archived. Bukti saat ini parsial (lihat §2.2).
3. **Owner: setujui pemasangan scheduler dan queue worker di mesin dev.** Ini perubahan tingkat OS (Windows Task Scheduler), sehingga tidak saya buat tanpa persetujuan.
4. **Owner: putuskan nasib baris outbox `pending` yang mungkin terbentuk selama `ENABLED=false`** sebelum mengaktifkan kembali (§7.2 langkah 2).
5. **Kegagalan pre-existing** (tidak dikerjakan di P1, di luar scope):
   - 73 kegagalan di `tests.test_jmi` dan `tests.test_review` (validator V4 vs fixture lama).
   - 5 kegagalan di `DemoSeederTest` dan `SampleDataSeederTest` (bug test dan state DB).
6. **Tidak dilakukan:** commit P1, regenerasi laporan, email, pemanggilan model.

Gate P2 yang disarankan: keputusan 1–4 di atas, dan konfirmasi scheduler serta queue worker berjalan saat pengiriman diuji ulang ke receiver uji.
