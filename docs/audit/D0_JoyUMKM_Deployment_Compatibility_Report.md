# D0 — Laporan Kompatibilitas Deployment JoyUMKM (DomaiNesia Shared Hosting)

- **Repositori:** `joytimess/dip-joyumkm` (branch `main`, commit referensi `9fa9ccb`)
- **Workspace:** `C:\laragon\www\joyumkm`
- **Target hosting:** cPanel, LiteSpeed, PHP 8.3.37, MariaDB 11.4.13, Python 3.12.16 (`/opt/alt/python312/bin/python3.12`), QPDF 12.4.2 (`/home/dokumen6/.local/opt/qpdf-12.4.2/bin/qpdf`), app root `/home/dokumen6/joyumkm.dokumenkita.online`, domain `https://joyumkm.dokumenkita.online`
- **Status:** Pekerjaan lokal D0 selesai dan terverifikasi di dua engine database. Verifikasi di hosting sebenarnya **belum dijalankan** (lihat bagian 8).

---

## 1. Ringkasan dan verdict D1

| Keputusan | Keterangan |
|---|---|
| **D1 (QA hosting terbatas): siap dengan syarat** | Syarat: probe pemilik (lihat `docs/deployment/DOMAINESIA_DEPLOYMENT_RUNBOOK.md` bagian 6) lulus, database `dokumen6_joyumkm_qa` saja, `HERMES_WEBHOOK_ENABLED=false`, rollout tetap OFF. |
| **Produksi: belum siap** | Belum ada probe hosting nyata; host Hermes `hermes-joyumkm.dokumenkita.online` belum tersedia; keputusan backlog dan jadwal cron di bagian 9 belum diputuskan pemilik. |

Yang sudah dilakukan dalam pekerjaan ini:
- Kompatibilitas MySQL 8.4 (eksisting) dan MariaDB 11.4 (instalasi baru) untuk CHECK, generated column, trigger, dan zona waktu UTC.
- Pemeriksaan runtime shared hosting: batas body, publikasi atomik, header cache, dan validator PDF.
- Cron terbatas `hermes:cron` dengan proteksi overlap dan batas anggaran waktu.
- Pengujian target pada kedua engine, plus pengujian di container Linux PHP 8.3 sebagai proksi lingkungan hosting.

Yang **tidak** dilakukan (sesuai guardrail): tidak ada deploy source produksi, tidak ada perubahan DB produksi, tidak ada `migrate:fresh` pada DB eksisting, tidak ada webhook atau email nyata, tidak ada pemanggilan AI produksi, tidak ada rollout, tidak ada commit/push, tidak ada secret yang ditampilkan.

---

## 2. Ketidakcocokan yang ditemukan dan diperbaiki

| # | Area | Masalah | Perbaikan | Dampak |
|---|---|---|---|---|
| 1 | CHECK constraint hash hex | `REGEXP_LIKE` tidak diizinkan dalam CHECK di MariaDB (error 1901) | `DatabaseEngine::hexDigestCheck()`: MariaDB memakai `CAST(col AS BINARY) REGEXP '^[0-9a-f]{N}$'`; MySQL tetap `REGEXP_LIKE` (teks SQL tidak berubah) | Migrasi artifact, deliveries, email_suppressions, artifact manifests |
| 2 | Hapus CHECK | `ALTER ... DROP CHECK` tidak tersedia di MariaDB | `DROP CONSTRAINT` (didukung MySQL 8.0.19+ dan MariaDB) | Migrasi down untuk status artifact, consent UMKM, period_month, email status sync |
| 3 | Generated column uniqueness | `CASE WHEN ... THEN event_id ...` gagal (1901) di MariaDB saat `event_id` bertipe `CHAR(36)` (driver `mysql`) | Pada MariaDB saja: `RTRIM(event_id)` di dalam CASE. Diverifikasi untuk sumber CHAR dan UUID; unique index dan perilaku NULL berulang tetap benar | `2026_11_25_100000_add_email_status_sync_to_intelligence_deliveries` |
| 4 | Deteksi engine | Engine ditebak dari nama koneksi (`MariaDbConnection::isMaria()` selalu true) | `DatabaseEngine::isMariaDb()` membaca `PDO::ATTR_SERVER_VERSION` | Semua migrasi dan helper test |
| 5 | Zona waktu | Blok `mariadb` di `config/database.php` tidak memiliki `timezone`; sesi mengikuti zona sistem | Ditambahkan `'timezone' => '+00:00'` | Konsistensi UTC di hosting |
| 6 | Kode error CHECK | MySQL 3819 vs MariaDB 4025 | Helper test `databaseErrorCode()`. Runtime tidak berubah | Test saja |
| 7 | Metadata CHECK | MariaDB tidak punya kolom `ENFORCED` di `information_schema.table_constraints` | Helper test `checkConstraintEnforcement()` | Test saja |
| 8 | Default kolom & display width | MariaDB mengutip default string (`'draft'`) dan melaporkan `tinyint(3)` | Helper `columnDefault()` dan `withoutDisplayWidth()` | Test saja |
| 9 | Isolasi test (pra-existing) | `restoreSixFactorMigrations()` tidak memasukkan migrasi `2026_11_25` sehingga kolom `synced_email_event` hilang pada test lain (MySQL) | Rantai migrasi rollback/restore diperbaiki | Test saja |
| 10 | Cron Hermes | Belum ada entry cron shared hosting yang terbatas | `php artisan hermes:cron` (lihat bagian 5) | Baru |
| 11 | Validator PDF: konfigurasi kosong | `proc_open` menerima `null`/string kosong jika `INTELLIGENCE_PDF_*` tidak diisi | Gagal tertutup dengan `pdf_validation_failed` sebelum `proc_open`; uji regresi ditambahkan | `IntelligenceArtifactFormatValidator` |
| 12 | Cache LiteSpeed untuk API | Respons API tidak mengirim directive `private, no-store`. Respons error (mis. 401 dari auth) tidak melewati middleware karena dilempar setelah pipeline | Middleware `PreventApiResponseCaching` di grup `api`, plus hook `respond()` untuk respons error `api/*`; header `X-LiteSpeed-Cache-Control: no-cache`. Diuji untuk sukses dan 401 | `bootstrap/app.php`, `app/Http/Middleware/PreventApiResponseCaching.php` |
| 14 | Flake test timestamp (test saja) | Dua test tepat di batas toleransi 300 detik (`IntelligenceArtifactUploadAuthTest`, `IntelligenceSnapshotApiTest`) bergantung pada detik saat middleware membaca waktu; pada Linux yang lebih lambat, hasilnya tidak deterministik | `freezeTime()` pada kedua test. Logika toleransi tidak berubah | Test saja |
| 13 | Batas body upload | `ValidatePostSize` Laravel membaca `post_max_size` PHP. Default PHP 8M lebih kecil dari artefak 10 MB, sehingga klien menerima 413 generik (`Permintaan tidak dapat diproses.`), bukan `artifact_too_large` | Bukan perubahan kode: **syarat hosting** `post_max_size >= 16M` (lihat bagian 4). Penolakan tetap tertutup, tanpa penyimpanan | Konfigurasi PHP hosting |

### Catatan yang sengaja tidak diubah
- **Migrasi MySQL eksisting tidak dijalankan ulang.** Perubahan pada migrasi lama hanya cabang MariaDB (dan formatting Pint: urutan import dan posisi kurung). Pada MySQL, SQL yang dihasilkan tetap sama; dump skema DB dev dan skema fresh MySQL 8.4 identik untuk objek yang dikelola migrasi.
- **Regex CHECK dengan `$`** pada hash hex menerima baris baru di akhir (`...\n`) di kedua engine. Ini sudah ada sebelumnya. Tindak lanjut yang disarankan: migrasi terpisah dengan `\z`, bukan diubah di D0.
- **Pemanggilan `Pdo\Mysql`** tetap; kelas ini tersedia lewat `symfony/polyfill-php84` (dependensi produksi) pada PHP 8.3.
- **JSON di MariaDB** disimpan sebagai `LONGTEXT` dengan check `json_valid`; trigger imutabel membandingkan teks sehingga perubahan whitespace-only dapat ditolak lebih ketat di MariaDB.
- **Validator QPDF** hanya meneruskan env `PATH`, `SystemRoot`, `TEMP`, `TMP`; `LD_LIBRARY_PATH` tidak diteruskan. Binary QPDF di hosting harus menemukan library lewat RPATH. Ini wajib diuji probe (bagian 6).

---

## 3. Hasil verifikasi

### 3.1 Lingkungan uji

| Lingkungan | Keterangan |
|---|---|
| Windows lokal MySQL | Laragon, MySQL 8.4.3, port 3306 (`joyumkm_testing`) |
| Windows lokal MariaDB | Container disposable `joyumkm-d0-mariadb`, MariaDB 11.4.13, port 3307 (env override, `.env` tidak diubah) |
| MySQL 8.4 fresh | Container disposable `joyumkm-d0-mysql84`, MySQL 8.4.13 |
| Linux proksi hosting | Container disposable `joyumkm-d0-linux-php` (`php:8.3-cli`, Debian 13, PHP 8.3.37, QPDF 12.2.0, Python 3.13.5), memakai MariaDB 11.4.13 |

Catatan: Linux proksi **bukan** OS hosting. QPDF dan Python-nya berbeda versi (12.2.0 vs 12.4.2; 3.13 vs 3.12). Ini bukti pendukung, bukan bukti hosting.

### 3.2 Pengujian target (daftar 398 test)

Daftar mencakup migrasi, skema intelligence, delivery, recipient, consent, evaluasi six-factor, artifact storage/download/upload auth, validator PDF, Hermes cron, dan middleware cache.

| Lingkungan | Hasil | Catatan |
|---|---|---|
| Windows MySQL 8.4 | **397 lulus, 1 dilewati**, 3642 assertion | Dilewati kemungkinan besar uji XLSX terenkripsi (`markTestSkipped` saat libzip tanpa enkripsi). Pada Linux uji ini berjalan dan lulus; tidak diverifikasi langsung di Windows |
| Windows MariaDB 11.4 | **397 lulus, 1 dilewati**, 3642 assertion | Sama |
| Linux proksi + MariaDB 11.4 | **398 lulus**, 3645 assertion, 0 dilewati | Enkripsi XLSX berjalan dan lulus. Dijalankan dengan `memory_limit=512M` dan `post_max_size=16M` (lihat 3.4) |

### 3.3 Migrasi dan skema
- Migrasi fresh MySQL 8.4: lulus. Migrasi fresh MariaDB 11.4: lulus (juga dibuktikan oleh `RefreshDatabase` pada seluruh test Linux MariaDB).
- Skema MySQL hasil migrasi fresh identik dengan skema dev eksisting untuk objek yang dikelola migrasi (perbandingan dengan dumper PDO).
- Pengujian CHECK, trigger imutabel, generated column unique, dan UTC: lulus di kedua engine.

### 3.4 Catatan batas memori dan post_max_size
- `IntelligenceArtifactStorageTest` membangun fixture zip bomb 30 MB di memori. Pada satu proses CLI panjang dengan `memory_limit=128M` (default Docker), proses fatal. Ini bukan jalur produksi (produksi hanya membaca metadata ZIP dan membatasi XML 2 MB). Menaikkan `memory_limit` untuk proses test menyelesaikan masalah.
- `IntelligenceArtifactUploadAuthTest::test_declared_length_larger_than_limit...` gagal dengan `post_max_size=8M` (default Docker): Laravel menolak dengan 413 generik sebelum middleware signature. Dengan `post_max_size=16M` semua 18 test lulus. Ini menjadi syarat hosting (bagian 4).

### 3.5 Lainnya
- **Composer platform (`--no-dev`)**: semua requirement PHP dan ekstensi lolos pada PHP 8.3.36 lokal (hosting 8.3.37; syarat sama).
- **Vite build** (`npm run build`): sukses. Peringatan: chunk `charts` 938 kB (276 kB gzip) melewati 500 kB. Tidak memblokir; code-splitting dicatat sebagai pekerjaan lanjutan.
- **Pint** (`--dirty`): merapikan tiga migrasi (urutan import, posisi kurung). Tidak mengubah SQL.
- **PDF validator** (unit): valid, malformed, active content, terenkripsi, dan executable hilang. Pada Linux proksi, helper Python dan QPDF juga lulus saat dijalankan langsung dengan env yang dibatasi (`env -i PATH=...`).

---

## 4. Syarat runtime hosting

| Komponen | Syarat | Sumber |
|---|---|---|
| PHP | 8.3.37 CLI dan web | Target hosting |
| Ekstensi PHP | `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring` (atau polyfill), `openssl`, `pcre`, `session`, `tokenizer`, `zip` (validasi XLSX dan deteksi enkripsi), `pdo_mysql` (MySQL/MariaDB) | Platform check Composer + kode |
| PHP ini web | `post_max_size >= 16M` (diuji); `memory_limit >= 256M` dan `max_execution_time >= 60` (disarankan, belum diuji) | Bagian 2 nomor 13; uji Linux |
| Python | `/opt/alt/python312/bin/python3.12` dijalankan dengan `-I` | `INTELLIGENCE_PDF_PYTHON` |
| QPDF | `/home/dokumen6/.local/opt/qpdf-12.4.2/bin/qpdf`; harus berjalan tanpa `LD_LIBRARY_PATH` (RPATH) | `INTELLIGENCE_PDF_QPDF`; probe bagian 6 |
| Database | MariaDB 11.4.13 (`DB_CONNECTION=mariadb`); sesi UTC | `config/database.php` |
| Filesystem | `storage/app/private/intelligence` tidak dapat diakses publik (`serve => false`); izin direktori 0700, file 0600 | `config/filesystems.php`, `IntelligenceArtifactReceiver` |

Jika probe menemukan `post_max_size` tidak dapat dinaikkan, sebagai alternatif (belum diuji): konfigurasi endpoint PUT artefak di LiteSpeed atau reverse proxy. Itu keputusan terpisah.

---

## 5. Cron dan antrean

### 5.1 Perintah
```
php artisan hermes:cron --budget=300
```
Perilaku:
1. Kunci file non-blocking `storage/app/hermes-webhook-cron.lock` (`flock`). Siklus yang bertabrakan keluar `SUCCESS` dengan pesan "dilewati".
2. Jika `HERMES_WEBHOOK_ENABLED=false` atau konfigurasi tujuan tidak lengkap, siklus berhenti tanpa menyentuh outbox atau antrean. Konfigurasi tidak lengkap keluar `FAILURE`.
3. `outbox->scan()` mengantrekan event pending/retry yang jatuh tempo ke antrean `hermes-webhooks` (dengan lease dan batas attempt yang sudah ada).
4. Sisa anggaran waktu dihitung. Jika tersisa >= 1 detik, `queue:work database --queue=hermes-webhooks --stop-when-empty --max-time=<sisa> --tries=1 --timeout=20 --memory=96` dijalankan. Antrean `default` tidak disentuh.
5. Batas `--budget` di-clamp ke 30–330 detik (di bawah interval cron 6 menit = 360 detik).

### 5.2 Entry cPanel yang disarankan (belum dipasang)
```
*/6 * * * * cd /home/dokumen6/joyumkm.dokumenkita.online && <PHP_CLI_8.3.37> artisan hermes:cron --budget=300 >> /home/dokumen6/logs/hermes-cron.log 2>&1
```
Ganti `<PHP_CLI_8.3.37>` dengan path PHP CLI hasil probe. Log harus berada di luar web root.

### 5.3 Konfigurasi antrean
- `DB_QUEUE_CONNECTION=database`, `DB_QUEUE_TABLE=jobs` (default).
- `DB_QUEUE_RETRY_AFTER` harus lebih besar dari timeout worker (20 detik). Gunakan 90 (default `config/queue.php`).
- Dokumen operasi `docs/HERMES_WEBHOOK.md` mencatat `schedule:run` setiap menit. **Keputusan (bagian 9):** pada hosting, jangan memasang `schedule:run` untuk Hermes. `routes/console.php` menjadwalkan `hermes:webhook-scan` setiap menit; itu tidak memakai kunci `hermes:cron` dan akan tumpang tindih dengan siklus cron. Pilih salah satu.

---

## 6. Perilaku yang perlu diperhatikan (bukan bug)
- **Backlog historis:** saat `HERMES_WEBHOOK_ENABLED=true`, event pending lama ikut terkirim. Tidak ada replay otomatis yang dibuat di D0. Pemilik harus memutuskan cutoff atau tinjauan manual sebelum mengaktifkan (bagian 9).
- **Event `dead`** tidak diantrekan ulang otomatis; hanya lewat `hermes:webhook-requeue` secara manual.
- **Recovery saat laptop offline:** outbox berada di DB hosting, sehingga event tetap tersimpan dan terkirim saat siklus cron berjalan, tanpa bergantung pada laptop.
- **Retry exhaustion:** attempt_limit dari `HERMES_WEBHOOK_MAX_ATTEMPTS` memindahkan event ke `dead`; status dapat diperiksa dengan `hermes:webhook-status`.

---

## 7. Konfigurasi (nama variabel saja; nilai tidak ditulis di dokumen ini)

Kelompok dan nama di bawah berasal dari `config/*.php` pada repo.

**Aplikasi & database:** `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY`, `APP_LOCALE`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET`, `DB_COLLATION`, `DB_URL`, `DB_SOCKET`, `MYSQL_ATTR_SSL_CA`, `DB_SSLMODE`
**Sesi, cache, antrean:** `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN`, `SESSION_ENCRYPT`, `CACHE_STORE`, `CACHE_PREFIX`, `DB_CACHE_CONNECTION`, `QUEUE_CONNECTION`, `DB_QUEUE_CONNECTION`, `DB_QUEUE_TABLE`, `DB_QUEUE`, `DB_QUEUE_RETRY_AFTER`
**Sanctum & log:** `SANCTUM_STATEFUL_DOMAINS`, `SANCTUM_TOKEN_EXPIRATION_DAYS`, `LOG_CHANNEL`, `LOG_LEVEL`, `LOG_STACK`
**Mail:** `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
**Hermes webhook (keluar):** `HERMES_WEBHOOK_ENABLED` (`false`), `HERMES_WEBHOOK_URL`, `HERMES_WEBHOOK_ALLOWED_HOST`, `HERMES_WEBHOOK_SECRET`, `HERMES_WEBHOOK_UAT_SCOPE_ENABLED` (`false`), `HERMES_WEBHOOK_TIMEOUT_SECONDS`, `HERMES_WEBHOOK_MAX_ATTEMPTS`, `HERMES_WEBHOOK_BACKOFF_BASE_SECONDS`, `HERMES_WEBHOOK_BACKOFF_CAP_SECONDS`
**Intelligence (masuk dari Hermes):** `HERMES_INTELLIGENCE_CALLBACK_SECRET`, `HERMES_INTELLIGENCE_CALLBACK_TOLERANCE_SECONDS`, `HERMES_INTELLIGENCE_SNAPSHOT_SECRET`, `HERMES_INTELLIGENCE_SNAPSHOT_TOLERANCE_SECONDS`, `HERMES_INTELLIGENCE_ARTIFACT_SECRET`, `HERMES_INTELLIGENCE_ARTIFACT_TOLERANCE_SECONDS`, `HERMES_INTELLIGENCE_DELIVERY_STATUS_SECRET`, `HERMES_INTELLIGENCE_DELIVERY_STATUS_TOLERANCE_SECONDS`, `HERMES_INTELLIGENCE_RECIPIENT_SECRET`, `HERMES_INTELLIGENCE_RECIPIENT_TOLERANCE_SECONDS`
**Intelligence (lain):** `INTELLIGENCE_RECIPIENT_PEPPER`, `INTELLIGENCE_RECIPIENT_TTL_SECONDS`, `INTELLIGENCE_PDF_PYTHON`, `INTELLIGENCE_PDF_QPDF`, `INTELLIGENCE_ARTIFACT_ROOT`, `INTELLIGENCE_EMAIL_TEST_MODE`, `INTELLIGENCE_EMAIL_TEST_ALLOWLIST`, `INTELLIGENCE_DELIVERY_STATUS_MESSAGE_DOMAIN`
**Sisi Hermes (di luar repo ini):** `JMI_HOME`, `JMI_ARTIFACT_PIPELINE_ENABLED`, `JMI_ARTIFACT_DELIVERY_ENABLED`, `JMI_EMAIL_DELIVERY_ENABLED`, `JMI_EMAIL_STATUS_SYNC_ENABLED`

Aturan pemisahan lokal/produksi: secret, `JMI_HOME`, subscription, cron pemulihan, dan endpoint **harus berbeda** antara lokal dan produksi. Tidak ada secret yang sama dipakai di dua lingkungan.

---

## 8. Yang belum dijalankan di hosting (bukti belum ada)
- Tidak ada akses SSH atau eksekusi perintah di DomaiNesia dalam pekerjaan ini.
- PHP CLI 8.3.37 hosting, `post_max_size`, `memory_limit`, dan ekstensi `zip`/`pdo_mysql` belum diperiksa di hosting.
- Python 3.12.16 dan QPDF 12.4.2 belum diuji di hosting, termasuk RPATH tanpa `LD_LIBRARY_PATH`.
- MariaDB 11.4.13 hosting: `SELECT VERSION()`, `SET SESSION time_zone`, dan `CHECK` belum diuji langsung pada DB `dokumen6_joyumkm_qa`.
- Batas minimum interval cron (6 menit) belum diverifikasi pada hosting.
- Konektivitas HTTPS ke `hermes-joyumkm.dokumenkita.online`: host **belum tersedia**.
- Trusted proxy dan skema HTTPS di balik LiteSpeed: belum diverifikasi. Konfigurasi default Laravel tidak mempercayai proxy; jika TLS diterminasi di depan PHP, verifikasi bahwa host dan skema yang dipakai signature HMAC sesuai.
- Probe pemilik ada di `docs/deployment/DOMAINESIA_DEPLOYMENT_RUNBOOK.md` bagian 6.

---

## 9. Keputusan yang perlu dibuat pemilik (tidak diimplementasikan tanpa persetujuan)
1. **Backlog Hermes:** tentukan cutoff (mis. hanya event setelah tanggal tertentu) atau tinjauan manual sebelum `HERMES_WEBHOOK_ENABLED=true`.
2. **Jadwal Hermes di hosting:** gunakan `hermes:cron` saja (rekomendasi), dan jangan memasang `schedule:run` untuk Hermes.
3. **post_max_size:** setujui `>= 16M` pada PHP web hosting (atau alternatif lewat LiteSpeed).
4. **Host Hermes:** provisi `hermes-joyumkm.dokumenkita.online` sebelum D2.
5. **Regex CHECK dengan `$`:** setujui migrasi tindak lanjut dengan `\z`.
6. **Chunk Vite > 500 kB:** code-splitting ditunda; tidak memblokir D1.

---

## 10. Cleanup dan artefak
- Container disposable `joyumkm-d0-mariadb`, `joyumkm-d0-mysql84`, dan `joyumkm-d0-linux-php` dihapus setelah verifikasi. Container Hermes tidak disentuh.
- Perubahan lokal belum di-commit atau di-push (instruksi pemilik).
- `graphify update .` dijalankan setelah perubahan kode.
- Build Vite menulis ke `public/build`, yang di-ignore git (`.gitignore`), sehingga tidak masuk commit.

## 11. Status keseluruhan
- Lokal: selesai dan terverifikasi di MySQL 8.4 dan MariaDB 11.4 (397 lulus + 1 dilewati pada keduanya; 398/398 pada Linux proksi).
- Hosting: menunggu probe pemilik.
- Produksi: tidak disentuh. Rollout tetap OFF.
