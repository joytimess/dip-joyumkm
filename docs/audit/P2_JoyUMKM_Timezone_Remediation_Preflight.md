# P2 — Timezone Remediation Preflight, JoyUMKM × Hermes (Docker)

Status: **siap review owner, menunggu persetujuan eksekusi.** Dev DB `joyumkm-v2` belum diubah. Enam migration P2 masih *Pending*. Webhook tetap nonaktif (`HERMES_WEBHOOK_ENABLED=false`). Tidak ada pemanggilan AI, tidak ada email, tidak ada `git reset` atau `clean`, dan P3 belum dimulai. Tidak ada secret yang ditampilkan.

Referensi: `docs/audit/P2_JoyUMKM_Schema_Data_Contract_Report.md` (T-1), `docs/evidence/p2-data-contract.md`, `docs/audit/P0_JoyUMKM_Hermes_Integration_Audit.md` (K3–K6, P5), `docs/audit/p2-timezone-correction.sql` (runbook koreksi).

---

## 1. Ringkasan

| Item | Hasil |
|---|---|
| Root cause offset 7 jam (T-1) | **Terbukti.** Laravel menulis UTC ke kolom `TIMESTAMP`, sedangkan sesi MySQL memakai zona sistem WIB (+07:00). Selisih `UNIX_TIMESTAMP` terhadap instan sebenarnya tepat **−25200 detik** pada 6 dari 6 baris pembanding independen (§2). |
| Cakupan | **36 kolom `TIMESTAMP`, 13 tabel. Tidak ada kolom `DATETIME`.** 35 kolom dikoreksi. `failed_jobs.failed_at` dikecualikan karena memakai `DEFAULT CURRENT_TIMESTAMP` (nilai dibuat DB dengan benar). Dev memiliki **1.965 nilai non-null** (§3). |
| Koreksi | Runbook SQL atomik, idempotensi berlapis, dan rollback. Semuanya **diuji di database lab terisolasi** `joyumkm_tz_lab` (§5). Seluruh test lulus. |
| **Perubahan urutan** | Rencana awal "migrasi P2 dulu, lalu koreksi" **tidak aman**. Urutan yang diuji: **(1) koreksi data, (2) config UTC, (3) migrasi P2.** Lihat §4.3 dan §4.1. |
| Kontrak pull-recipient (K3, K4, P5) | **Belum siap untuk P5.** K4 sebagian terpenuhi. K3 belum punya kontrak transport (endpoint GET bertanda tangan belum didefinisikan). Lihat §6. |
| Pepper | **Belum ada** di `.env` dev (terkonfirmasi). Lihat §7. |
| Verdict P2-05 | Tetap **PARTIAL** sampai owner menyetujui runbook dan urutan eksekusi. |

---

## 2. Bukti root cause

### 2.1 Konfigurasi

| Lokasi | Temuan |
|---|---|
| `config/app.php:68` | `'timezone' => 'UTC'`. Riwayat git: baris ini sudah ada sejak commit awal `201da54` dan tidak pernah berubah. |
| `config/database.php` (blok `mysql`) | **Tidak ada key `timezone`.** |
| `vendor/laravel/framework/.../MySqlConnector.php:110-111` | `time_zone` hanya di-set bila config `timezone` ada. Tanpa key itu, sesi memakai zona server. |

Kondisi runtime dev (read-only):

```
@@global.time_zone = SYSTEM      @@session.time_zone = SYSTEM
@@system_time_zone = SE Asia Standard Time
TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) = 25200
PHP date_default_timezone = UTC   config app.timezone = UTC
```

### 2.2 Mekanisme

1. Carbon/Laravel menulis `2026-11-22 13:10:20` (wall-clock UTC).
2. Kolom `TIMESTAMP` menafsirkan string itu dalam zona sesi (WIB, UTC+7), lalu menyimpan instan internal UTC = wall − 7 jam.
3. Pembacaan lewat Laravel dengan sesi yang sama membalik konversi itu, sehingga aplikasi terlihat benar.
4. Fungsi yang membaca instan (`UNIX_TIMESTAMP`, `CONVERT_TZ`, `FROM_UNIXTIME`, klien DB dengan zona lain) melihat instan **7 jam lebih awal**.

### 2.3 Bukti epoch: anchor independen dari payload webhook

Setiap `webhook_outbox` dengan `event_type = evaluation.finalized` membawa `occurred_at` (ISO UTC, `Z`) yang dibuat aplikasi dari instan finalisasi. `aggregate_id` menunjuk ke `six_factor_evaluations.id`. Ini sumber kebenaran independen (read-only).

| `six_factor_evaluations.id` | `occurred_at` (payload, UTC) | `finalized_at` tersimpan | `UNIX_TIMESTAMP(finalized_at) − epoch(occurred_at)` |
|---|---|---|---|
| 9 | 2026-11-22T13:10:20Z | 2026-11-22 13:10:20 | **−25200** |
| 10 | 2026-11-22T13:58:36Z | 2026-11-22 13:58:36 | **−25200** |
| 11 | 2026-11-22T14:31:03Z | 2026-11-22 14:31:03 | **−25200** |
| 12 | 2026-11-23T01:16:53Z | 2026-11-23 01:16:53 | **−25200** |
| 13 | 2026-11-23T03:50:03Z | 2026-11-23 03:50:03 | **−25200** |
| 14 | 2026-11-23T03:59:58Z | 2026-11-23 03:59:58 | **−25200** |

Pembacaan wall-clock sudah konsisten: string tersimpan sama dengan `occurred_at` (UTC). Yang salah hanya epoch yang dibaca MySQL.

### 2.4 SQL raw dan pembacaan dalam kode

- Tidak ada fungsi waktu SQL (`NOW()`, `UNIX_TIMESTAMP`, `CONVERT_TZ`, `FROM_UNIXTIME`, `DATE_ADD`, `TIMESTAMPDIFF`) di `app/`.
- Ada perbandingan waktu raw dengan parameter UTC dari PHP: `app/Services/HermesWebhookOutbox.php:125`, `app/Services/SixFactorMonthlyRecapService.php:113-117` (`COALESCE(submitted_at, created_at)`), serta `ORDER BY` waktu di beberapa service.
- Perbandingan ini **benar selama** (sesi SYSTEM dan data belum dikoreksi) atau (sesi UTC dan data sudah dikoreksi). Kombinasi campuran salah (§4.3). Ini diturunkan dari selisih konstan −25200 dan dikonfirmasi pada lab (§5, L5 dan C2).
- Periode (`period_month`) dihitung di PHP dari nilai yang benar, sehingga tidak terkena offset saat ditulis. Backfill migration 153541 membaca lewat Laravel, sehingga ikut urutan koreksi (§5, D1 dan D2).

### 2.5 Batas bukti (jujur)

- Bukti per baris hanya untuk **6 baris** anchor di atas.
- Untuk 1.959 nilai lainnya, kesimpulan didasarkan pada: jalur tulis yang sama (semua penulisan lewat Laravel), konfigurasi `app.timezone` dan koneksi yang tidak pernah berubah di git, dan selisih yang konstan pada semua anchor. Owner perlu menerima asumsi ini, atau menambah anchor dari sumber lain (mis. log Hermes atau artefak jmi) untuk memperkuatnya.

---

## 3. Daftar tabel, kolom, dan baris terdampak (dev `joyumkm-v2`, read-only)

Semua kolom `TIMESTAMP` diperlakukan sama: nilai dikoreksi `+25200` detik, kecuali yang dikecualikan.

| Tabel | Kolom | Non-null (dev) | Perlakuan |
|---|---|---:|---|
| `evaluation_answers` | `created_at`, `updated_at` | 810, 810 | Koreksi |
| `evaluations` (V1) | `submitted_at`, `created_at`, `updated_at` | 49, 53, 53 | Koreksi |
| `factors` | `created_at`, `updated_at` | 3, 3 | Koreksi |
| `failed_jobs` | `failed_at` | 0 | **Dikecualikan** (`DEFAULT CURRENT_TIMESTAMP`, dibuat DB) |
| `intelligence_reports` | `generated_at`, `received_at`, `created_at`, `updated_at` | 3, 3, 3, 3 | Koreksi **sebelum P2** (trigger immutable memblokir setelahnya) |
| `password_reset_tokens` | `created_at` | 0 | Koreksi (kosong) |
| `personal_access_tokens` | `last_used_at`, `expires_at`, `created_at`, `updated_at` | 3, 3, 3, 3 | Koreksi |
| `questions` | `created_at`, `updated_at` | 16, 16 | Koreksi |
| `sectors` | `created_at`, `updated_at` | 5, 5 | Koreksi |
| `six_factor_evaluations` | `finalized_at`, `cancelled_at`, `created_at`, `updated_at` | 10, 3, 14, 14 | Koreksi. `period_month` belum ada di dev. |
| `umkms` | `created_at`, `updated_at`, `wa_checked_at` | 25, 25, 1 | Koreksi |
| `users` | `email_verified_at`, `created_at`, `updated_at` | 0, 4, 4 | Koreksi |
| `webhook_outbox` | `next_attempt_at`, `lease_expires_at`, `created_at`, `updated_at`, `delivered_at` | 0, 0, 6, 6, 6 | Koreksi |

**Total non-null: 1.965 nilai.** Jumlah baris dev: `six_factor_evaluations` 14 (10 Final, 3 Cancelled, 1 Draft), `intelligence_reports` 3, `webhook_outbox` 6.

Dampak khusus:
- **`period_month`**: belum ada di dev. Ke-10 Final dev jatuh di **2026-11** baik dengan pembacaan benar maupun pembacaan bergeser. Tidak ada baris di jendela batas bulan. Backfill tetap harus dijalankan setelah koreksi (§4.3).
- **Trigger immutable P2** (`intelligence_reports_before_update_immutable`, migration 153536) memblokir perubahan `generated_at`, `received_at`, dan `created_at`. Koreksi `intelligence_reports` **hanya mungkin sebelum migration 153536** (terbukti pada lab, D3).
- **Pembaca eksternal** (klien DB, BI, skrip epoch) tidak ditemukan di repo. Kebutuhannya perlu dikonfirmasi owner.

---

## 4. Rencana koreksi dan rollback

### 4.1 Prasyarat

1. Persetujuan owner atas runbook (`docs/audit/p2-timezone-correction.sql`), urutan §4.2, dan jendela pemeliharaan.
2. Backup dev dengan `mysqldump --tz-utc --single-transaction --routines --triggers joyumkm-v2`. Simpan di lokasi aman, karena dump memuat data pengguna. Jangan tampilkan isinya.
3. `php artisan down` dan hentikan queue worker. Worker menulis `webhook_outbox` dan dapat menulis timestamp saat koreksi berjalan.
4. Migration P2 **belum** dijalankan (`php artisan migrate --pretend` untuk pratinjau).
5. Anchor dimuat ke `timezone_offset_anchors` dari payload (`occurred_at`), hanya setelah dicek bahwa `finalized_at` tersimpan sama dengan wall payload. Untuk dev, 6 anchor di §2.3.
6. Server MySQL harus memiliki offset +25200 (preflight `@offset_s` memastikan ini; koreksi berhenti bila tidak sesuai).

### 4.2 Urutan eksekusi (dev, setelah persetujuan)

| Langkah | Tindakan | Gerbang lolos |
|---|---|---|
| 1 | `php artisan down`, stop worker, backup `--tz-utc` | Backup tersimpan dan terbaca |
| 2 | Jalankan runbook **bagian 0 sampai 1** (DDL dan preflight) dalam satu sesi `mysql` | `measured_offset_s = 25200`, `session_tz = SYSTEM`, `triggers_on_db = 0`, `run_already_applied = 0` |
| 3 | Muat anchor (§4.1 poin 5) | `anchors_loaded` = jumlah anchor |
| 4 | Jalankan **bagian 2 sampai 5** (transaksi: snapshot, koreksi, verifikasi) | `delta_violations = 0`, `anchor_violations = 0` |
| 5 | `COMMIT` hanya jika kedua verifikasi 0. Selain itu `ROLLBACK`. | Keputusan tercatat |
| 6 | Ubah `config/database.php` mysql: tambah `'timezone' => '+00:00'`. Deploy **bersama** langkah 5 (tidak boleh terpisah). Lalu `php artisan config:clear`. | `SELECT @@session.time_zone` = `+00:00` pada koneksi aplikasi |
| 7 | `php artisan migrate` (enam migration P2). Backfill `period_month` berjalan di bawah UTC. | Migrate sukses. Periode sesuai §5, D2. |
| 8 | Verifikasi pasca: anchor diff 0, `app read == wall payload`, `period_month` sesuai, dan `php artisan up` | Semua cek lulus |

Runbook dijalankan dari satu sesi. Variabel `@offset_s` harus tetap dalam sesi itu.

### 4.3 Aturan kombinasi (mengapa urutan penting)

Hasil uji lab (§5, B dan D):

| Data | Config koneksi | Pembacaan aplikasi | Epoch / pembaca eksternal | Backfill `period_month` |
|---|---|---|---|---|
| Belum dikoreksi | SYSTEM (sekarang) | **Benar** (7/7) | **Salah** (3/7 bergeser ke bulan sebelumnya) | Benar |
| Belum dikoreksi | UTC | **Salah −7 jam** (7/7) | Salah | **Salah** |
| Sudah dikoreksi | UTC (target) | **Benar** (7/7) | **Benar** (7/7) | **Benar** (7/7) |
| Sudah dikoreksi | SYSTEM | **Salah +7 jam** (7/7) | Benar | **Salah** (B1, B3, B5) |

Aturan: **data dan config harus berubah bersamaan.** Kombinasi campuran selalu salah. Karena itu config UTC tidak boleh di-deploy sebelum koreksi, dan koreksi tidak boleh dibiarkan tanpa config UTC. Migrasi P2 harus dijalankan **setelah** keduanya.

### 4.4 Idempotensi (berlapis)

1. **Ledger** `timezone_offset_corrections` dengan PK `run_key`. Run kedua gagal sebelum mengubah data.
2. **Snapshot** `timezone_offset_snapshot` dengan PK `(run_key, phase, table, column)`. Snapshot kedua gagal dengan `1062`, dan transaksi dibatalkan.
3. **Gerbang anchor**: `anchor_violations` harus 0 sebelum `COMMIT`. Jika koreksi terjadi dua kali, anchor bergeser `+25200` dan transaksi dibatalkan. Ini diuji walau ledger sengaja dilewati (§5, I2).
4. **Offset terukur**: `@offset_s = TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())`. Jika bukan 25200, jangan lanjut. Ini melindungi bila server produksi memiliki zona sistem lain.
5. **Atomik**: snapshot, ledger, dan seluruh `UPDATE` berada dalam satu transaksi. Kegagalan di tengah tidak menyisakan data setengah dikoreksi.

Catatan: bagian 1 (preflight) memakai *harapan* di komentar. Runner lab tidak berhenti otomatis di sana, tetapi gerbang keras (PK dan COMMIT) tetap mencegah kerusakan. Operator tetap harus membaca hasil preflight.

### 4.5 Rencana rollback

| Kondisi | Tindakan |
|---|---|
| Verifikasi gagal sebelum `COMMIT` | `ROLLBACK` otomatis. Tidak ada perubahan. (Diuji I2.) |
| Setelah `COMMIT`, **sebelum** migrasi P2 | Jalankan SQL inverse di bawah, lalu kembalikan config (hapus `'timezone'`), `config:clear`. (Diuji R1.) |
| Setelah migrasi P2 | Jalankan `php artisan migrate:rollback --step=6` **dulu** (menghapus trigger), lalu SQL inverse, lalu kembalikan config. (Diuji R1.) |
| Inverse gagal atau data diragukan | Restore dari dump `--tz-utc` (`mysql ... < dump.sql`). Restore diuji pada lab (§5, B1). |

SQL inverse (sudah diuji; `-25200` detik pada 35 kolom, lalu hapus ledger):

```sql
SET time_zone = '+00:00';
SET @offset_s = 25200;
START TRANSACTION;

UPDATE `evaluation_answers` SET
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `evaluations` SET
    `submitted_at` = `submitted_at` - INTERVAL @offset_s SECOND,
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `factors` SET
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `intelligence_reports` SET
    `generated_at` = `generated_at` - INTERVAL @offset_s SECOND,
    `received_at` = `received_at` - INTERVAL @offset_s SECOND,
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `password_reset_tokens` SET
    `created_at` = `created_at` - INTERVAL @offset_s SECOND;

UPDATE `personal_access_tokens` SET
    `last_used_at` = `last_used_at` - INTERVAL @offset_s SECOND,
    `expires_at` = `expires_at` - INTERVAL @offset_s SECOND,
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `questions` SET
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `sectors` SET
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `six_factor_evaluations` SET
    `finalized_at` = `finalized_at` - INTERVAL @offset_s SECOND,
    `cancelled_at` = `cancelled_at` - INTERVAL @offset_s SECOND,
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `umkms` SET
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND,
    `wa_checked_at` = `wa_checked_at` - INTERVAL @offset_s SECOND;

UPDATE `users` SET
    `email_verified_at` = `email_verified_at` - INTERVAL @offset_s SECOND,
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND;

UPDATE `webhook_outbox` SET
    `next_attempt_at` = `next_attempt_at` - INTERVAL @offset_s SECOND,
    `lease_expires_at` = `lease_expires_at` - INTERVAL @offset_s SECOND,
    `created_at` = `created_at` - INTERVAL @offset_s SECOND,
    `updated_at` = `updated_at` - INTERVAL @offset_s SECOND,
    `delivered_at` = `delivered_at` - INTERVAL @offset_s SECOND;

DELETE FROM timezone_offset_corrections WHERE run_key = 'p2-utc-2026-11';
-- Verifikasi: bandingkan dengan timezone_offset_snapshot (phase = 'before') sebelum COMMIT.
COMMIT;
SET time_zone = 'SYSTEM';
```

---

## 5. Hasil test di MySQL terisolasi

**Lingkungan:** MySQL 8.4.3 lokal. Database baru **`joyumkm_tz_lab`** dibuat khusus untuk uji ini. Dev tidak disentuh. Setiap langkah yang menulis memverifikasi nama database lebih dulu. Migrasi dijalankan dengan migration dari repo (`DB_DATABASE` override; tidak ada `bootstrap/cache/config.php`, sehingga override berlaku). Fixture: 7 kasus Final (6 batas bulan/tahun/kabisat WIB + 1 tengah bulan), 1 Cancelled, 1 laporan, 1 outbox. Penulisan memakai jalur Carbon UTC seperti aplikasi.

**Catatan:** runbook dieksekusi per pernyataan lewat koneksi Laravel di lab (bukan `mysql` CLI). Isi pernyataannya sama dengan file `p2-timezone-correction.sql`.

| ID | Skenario | Hasil |
|---|---|---|
| L1 | Migrasi penuh, rollback 6 P2 (`--step=6`), migrasi lagi | **PASS** (up/down P2 pada MySQL 8.4.3) |
| L2 | Selisih epoch `finalized_at` terhadap instan yang ditulis, 7 kasus | **PASS**: semua **−25200** (bukti root cause). Selisih per kolom lain diuji lewat snapshot (C1, R1). |
| L3 | Pembacaan aplikasi, sesi SYSTEM | **PASS**: konsisten dengan yang ditulis (7/7) |
| L4 | Pembaca eksternal WIB dari epoch | **Bukti**: salah pada B2, B4, B6 (jam 00:00–06:59 WIB tanggal 1 terbaca bulan sebelumnya) |
| L5 | Koneksi UTC **tanpa** koreksi | **Bukti hazard**: pembacaan aplikasi −7 jam (7/7) |
| C1 | Koreksi (transaksi) | **PASS**: `delta_violations = 0`, `anchor_violations = 0`, **COMMIT** |
| C2 | Pasca-koreksi, sesi SYSTEM | **Bukti hazard**: pembacaan aplikasi +7 jam (7/7). Config harus berubah bersamaan. |
| C3 | Pasca-koreksi, sesi UTC | **PASS**: pembacaan aplikasi 7/7, periode SQL WIB 7/7 |
| D1 | Migrasi P2 di SYSTEM **setelah** koreksi | **Bukti hazard**: `period_month` salah pada **B1, B3, B5** (akhir bulan WIB) |
| D2 | Migrasi P2 di UTC **setelah** koreksi | **PASS**: `period_month` 7/7, termasuk 2028 kabisat |
| D3 | Koreksi `intelligence_reports` setelah P2 | **Bukti ordering**: `SQLSTATE[45000] 1644 Laporan intelligence bersifat immutable.` |
| I1 | Jalankan ulang setelah sukses | **PASS**: ditolak (`1062` pada snapshot), **ROLLBACK** |
| I2 | Double correction dengan ledger sengaja dilewati | **PASS**: `anchor_violations = 7`, **ROLLBACK**, **0 kolom berubah** |
| R1 | SQL inverse (setelah P2 `down`) | **PASS**: 35/35 kolom identik dengan snapshot historis, **COMMIT** |
| R2 | Koreksi ulang setelah rollback, lalu migrasi UTC | **PASS**: delta 0, anchor 0, COMMIT; `period_month` dan `finalized_at` 7/7 |
| B1 | Backup `mysqldump --tz-utc`, restore ke DB kedua | **PASS**: checksum identik (jumlah epoch dan non-null semua kolom + jumlah baris semua tabel) |

**Dev dicek setelah semua uji (read-only):** 6 migration P2 masih *Pending*, total non-null tetap **1.965**, dan anchor dev tetap **−25200** pada keenam baris.

**Batas uji:** fixture sintetis (7 baris), bukan salinan data dev. Anchor lab dibuat dari instan yang sudah diketahui. Zona sistem server produksi bisa berbeda. Karena itu `@offset_s` menjadi gerbang. Database `joyumkm_tz_lab` masih ada di server lokal dan dapat dihapus atas persetujuan.

---

## 6. Review kontrak pull-recipient (K3, K4, P5)

| Keputusan P0 | Kontrak di `p2-data-contract.md` §5–6 | Implementasi (kode) | Status |
|---|---|---|---|
| **K4** consent eksplisit per UMKM, legacy tanpa email | Default `notification_consent_at` NULL. Kolom `notification_consent_version` dan `notification_consent_by_user_id` ada. Alasan `no_consent` diurutkan kedua. | `IntelligenceRecipientEligibilityService.php:38` cek `notification_consent_at === null` setelah cek evaluasi Final. Default NULL dibuktikan oleh test. | **Sebagian.** Lihat gap A dan B. |
| **K4** gap A: siapa dan bagaimana consent dicatat | Kontrak tidak mendefinisikan alur. | **Tidak ada endpoint atau UI** untuk memberi consent. Hanya test dan `DB::table` yang menulisnya (`grep notification_consent` di `app/`). | **GAP.** Dibutuhkan keputusan dan alur sebelum P5. |
| **K4** gap B: consent terikat ke alamat | Tidak disebut. | Consent disimpan di `umkms`, tidak terikat ke `business_email` yang disetujui. Mengubah `business_email` tidak mencabut consent. | **GAP.** Rekomendasi: simpan fingerprint alamat saat consent, atau reset consent saat alamat berubah. |
| **K3** pull (Hermes ambil email dengan GET bertanda tangan saat kirim) | §5 hanya mendefinisikan `evaluate()` (bentuk hasil). Pengambilan alamat ditunda ke P5. | **Tidak ada endpoint GET.** | **GAP.** Path, skema autentikasi (secret terpisah?), bentuk respons, TTL, kode error, dan larangan logging belum didefinisikan. |
| **K3** konflik internal | §5: "Respons tidak pernah berisi alamat email." Tujuan pull K3 justru mengembalikan alamat. | — | **Konflik.** Pisahkan dua kontrak: (a) kelayakan tanpa alamat, (b) pengambilan alamat hanya untuk penerima yang eligible, bertanda tangan, sekali pakai atau berumur pendek. |
| **K3** PII tidak masuk payload | Payload tidak berisi email, nama, PIC, atau WA. | Test `IntelligenceRecipientConsentTest` (nama test di kontrak) mengecek ini. | **PASS** untuk payload. |
| **K5** WIB untuk periode | `period_month` dari `finalized_at` dengan `Asia/Jakarta`. | `SixFactorEvaluation::periodMonthFor` (`app/Models/SixFactorEvaluation.php:43`). | **PASS** setelah koreksi dan config UTC (§4.3). |
| **K6** Final terakhir per bulan | Rekap memakai Final terbaru per bulan, lalu `id` terbesar. | `SixFactorMonthlyRecapService` (`orderByDesc finalized_at`, `orderByDesc id`). | **PASS** (di luar cakupan waktu ini). |
| Urutan alasan eligibility | 1 `evaluation_not_final`, 2 `no_consent`, 3 `no_business_email`, 4 `invalid_email`, 5 `suppressed` | Sama persis pada kode. | **PASS** |
| Format `recipient_fingerprint` | Doc §5 menulis `"<64 hex>"` untuk semua hasil. | Kode mengembalikan **`null`** untuk alasan sebelum fingerprint dan untuk `invalid_email`. | **Dokumen tidak akurat.** Ubah menjadi nullable. |
| Pepper dan suppression | Fingerprint = HMAC-SHA256 dengan pepper. | `IntelligenceRecipientFingerprint.php`. Suppression dicocokkan lewat fingerprint yang sama. | **RISIKO.** Mengganti pepper membuat semua suppression dan idempotensi tidak cocok. Lihat §7. |
| **K11** penyedia email | Belum dipilih. | — | **Blokir P5** (keputusan owner). |

**Kesimpulan review:** kontrak P2 **cukup untuk kelayakan dan idempotensi**, tetapi **belum cukup untuk P5**. Sebelum P5, owner perlu menyetujui: alur consent (A) dan keterikatannya ke alamat (B), serta addendum kontrak pull untuk K3 (transport, autentikasi, batas PII). Laporan P2 yang menulis "Kontrak pull didefinisikan" sebaiknya diperbaiki menjadi "kelayakan didefinisikan; transport pull belum".

---

## 7. Checklist konfigurasi pepper dan migration P2

### 7.1 Pepper `INTELLIGENCE_RECIPIENT_PEPPER`

- [ ] Owner membuat nilai acak minimal 32 karakter. Contoh perintah (dijalankan owner sendiri, hasilnya tidak ditampilkan di chat): `php -r "echo bin2hex(random_bytes(32));"`
- [ ] Simpan di `.env` dev oleh owner. **Jangan** commit. `.env.example` tetap kosong.
- [ ] Simpan juga di penyimpanan secret yang aman dan terpisah dari dump database.
- [ ] Jalankan `php artisan config:clear`.
- [ ] Verifikasi tanpa menampilkan nilai: `php artisan tinker --execute 'echo strlen((string) config("services.intelligence.recipient_pepper")) >= 32 ? "ok" : "kurang";'`
- [ ] Catat kebijakan: **pepper tidak boleh diganti** setelah dipakai. Penggantian memutus suppression dan idempotensi. Jika rotasi diperlukan, harus ada prosedur hitung ulang seluruh fingerprint `email_suppressions` (belum ada).
- [ ] Sebelum pepper terisi, `evaluate()` melempar `RuntimeException`. Ini disengaja (fingerprint tidak dibuat tanpa kunci).

### 7.2 Migration P2 (urutan wajib)

- [ ] Backup `--tz-utc` (§4.1).
- [ ] `php artisan migrate:status`: harus 6 *Pending* (sebelum koreksi).
- [ ] `php artisan migrate --pretend`: pratinjau SQL.
- [ ] Jalankan koreksi (§4.2 langkah 2–5). **Jangan** jalankan migrasi P2 sebelum langkah ini.
- [ ] Deploy `'timezone' => '+00:00'` ke `config/database.php` bersamaan dengan COMMIT. Lalu `config:clear`.
- [ ] `php artisan migrate` (enam migration).
- [ ] Verifikasi: `period_month` setiap Final sama dengan bulan WIB dari `finalized_at` yang dibaca lewat Laravel. Anchor diff 0. Pembacaan `finalized_at` sama dengan wall payload.
- [ ] Verifikasi trigger P2 ada (4 trigger: 2 di `intelligence_reports`, 2 di `intelligence_artifacts`).
- [ ] `php artisan up`.

### 7.3 Perubahan kode yang dibutuhkan (setelah persetujuan, di luar dokumen ini)

```php
// config/database.php, blok 'mysql'
'timezone' => '+00:00',
```

Perubahan ini belum dibuat. Ini perubahan config yang harus masuk bersama koreksi data.

---

## 8. Checklist eksekusi aman pada dev DB (ringkas)

1. [ ] Owner menyetujui dokumen ini, runbook, dan urutan §4.2.
2. [ ] Jadwalkan jendela pemeliharaan. Matikan worker.
3. [ ] `php artisan down`.
4. [ ] Backup `mysqldump --tz-utc` tersimpan. Jika dibutuhkan, verifikasi restore ke DB kedua (seperti B1).
5. [ ] Preflight (runbook bagian 0–1). Semua harapan terpenuhi.
6. [ ] Muat anchor dari payload. Verifikasi wall anchor sama.
7. [ ] Koreksi dalam satu sesi (runbook bagian 2–5). Verifikasi keduanya 0.
8. [ ] `COMMIT` bila lolos. `ROLLBACK` bila tidak.
9. [ ] Deploy config UTC dan `config:clear` (bersamaan dengan langkah 8).
10. [ ] `php artisan migrate` (6 migration P2).
11. [ ] Verifikasi pasca (§7.2).
12. [ ] `php artisan up`.
13. [ ] Laporkan hasil ke owner dengan angka (jumlah kolom, delta, anchor, period).

---

## 9. Handoff untuk persetujuan owner

Keputusan yang dibutuhkan:

| # | Keputusan | Rekomendasi |
|---|---|---|
| O1 | Setujui runbook koreksi dan jendela pemeliharaan. | Setujui, dengan urutan §4.2 (koreksi lalu config lalu P2). |
| O2 | Setujui **perubahan urutan** dari rencana awal ("migrasi P2 dulu"). | Setujui. Urutan lama menghasilkan `period_month` salah (B1, B3, B5) dan memblokir koreksi `intelligence_reports`. |
| O3 | Terima cakupan koreksi: semua 35 kolom `TIMESTAMP` yang ditulis aplikasi. Bukti per baris hanya untuk 6 anchor. | Terima. Tambah anchor dari sumber lain bila owner ingin bukti lebih kuat. |
| O4 | Setujui perubahan `config/database.php` (`'timezone' => '+00:00'`) sebagai PR, di-deploy bersama koreksi. | Setujui. |
| O5 | Pepper: owner membuat dan menyimpan nilai, dan menerima kebijakan "tidak diganti" (§7.1). | Setujui. |
| O6 | Consent: tentukan alur pemberian consent dan keterikatannya ke alamat email (gap A dan B). | Wajib sebelum P5. |
| O7 | Pull K3: setujui addendum kontrak (transport, autentikasi, batas PII, pemisahan dari eligibility). | Wajib sebelum P5. |
| O8 | Pembaca eksternal epoch: konfirmasi apakah ada (BI, klien DB). | Jika ada, beri tahu mereka sebelum koreksi. |
| O9 | Hapus `joyumkm_tz_lab` setelah review. | Boleh dihapus kapan saja. |

---

## 10. Yang belum dikerjakan dan batasan

- Dev DB `joyumkm-v2`: **tidak diubah**. Enam migration P2 masih *Pending*.
- Tidak ada perubahan kode aplikasi di sesi ini. File baru: dokumen ini dan `docs/audit/p2-timezone-correction.sql`. Perubahan config (§7.3) menunggu persetujuan.
- Kontrak `docs/evidence/p2-data-contract.md` **tidak diubah**. Koreksi dokumen (§6) menunggu persetujuan owner.
- Webhook, AI, email, dan P3 tidak disentuh. Endpoint P5 belum dibangun.
- Hasil lab adalah bukti untuk runbook. Belum diverifikasi pada server MySQL produksi.
