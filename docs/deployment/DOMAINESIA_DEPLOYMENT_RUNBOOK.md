# Runbook Deployment JoyUMKM — DomaiNesia Shared Hosting

Dokumen ini adalah langkah operasional untuk pemilik. Hasil audit dan alasan setiap perubahan ada di `docs/audit/D0_JoyUMKM_Deployment_Compatibility_Report.md`.

> **Guardrail:** dokumen ini **tidak** menjalankan deploy produksi. Bagian produksi (bagian 9) hanya checklist. Rollout Hermes tetap **OFF** (`HERMES_WEBHOOK_ENABLED=false`) sampai keputusan di bagian 9 dan dokumen D1 disetujui. Jangan tempel password, private key, atau nilai secret ke chat atau dokumen apa pun.

---

## 1. Lokasi dan nilai tetap

| Item | Nilai |
|---|---|
| Domain | `https://joyumkm.dokumenkita.online` |
| App root (di luar web root) | `/home/dokumen6/joyumkm.dokumenkita.online` |
| Document root yang harus menunjuk ke | `/home/dokumen6/joyumkm.dokumenkita.online/public` |
| PHP CLI 8.3.37 | `<PHP_CLI_8.3.37>` (isi dari hasil probe bagian 6.1) |
| Python 3.12.16 | `/opt/alt/python312/bin/python3.12` |
| QPDF 12.4.2 | `/home/dokumen6/.local/opt/qpdf-12.4.2/bin/qpdf` |
| Database QA | `dokumen6_joyumkm_qa` (sudah disiapkan, kosong) |
| Log cron | `/home/dokumen6/logs/hermes-cron.log` (di luar web root) |
| Host Hermes (belum tersedia) | `hermes-joyumkm.dokumenkita.online` |

Path di atas adalah nilai tetap yang sudah disepakati. Path `<PHP_CLI_8.3.37>` harus diisi dari probe.

---

## 2. Prasyarat
- Akses cPanel dan Terminal cPanel milik pemilik. Asisten tidak meminta password atau private key.
- Build frontend dilakukan di laptop (`npm ci && npm run build`), lalu folder `public/build` diunggah. Folder ini tidak di-commit ke git.
- `composer install` dijalankan di hosting memakai PHP 8.3.37, atau di laptop dengan `--no-dev` lalu vendor diunggah (pilih satu dan tetap konsisten).

---

## 3. Variabel lingkungan (hanya nama; nilai diisi pemilik di cPanel/`.env` hosting)

Jangan tulis nilai di dokumen, chat, atau repo. Nilai yang bukan secret ditandai.

**Wajib untuk QA:**
- `APP_ENV`, `APP_DEBUG` (harus `false`), `APP_URL`, `APP_KEY` (buat hanya jika lingkungan baru; jangan ganti jika sudah ada data terenkripsi)
- `DB_CONNECTION` (`mariadb`), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `SESSION_DRIVER`, `SESSION_SECURE_COOKIE` (`true` di HTTPS), `SESSION_DOMAIN`
- `SANCTUM_STATEFUL_DOMAINS`
- `QUEUE_CONNECTION` (`database`), `DB_QUEUE_CONNECTION`, `DB_QUEUE_TABLE`, `DB_QUEUE_RETRY_AFTER` (harus lebih besar dari 20; disarankan 90)
- `CACHE_STORE`, `LOG_CHANNEL`, `LOG_LEVEL`
- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
- `INTELLIGENCE_PDF_PYTHON` (path Python di atas)
- `INTELLIGENCE_PDF_QPDF` (path QPDF di atas)
- `INTELLIGENCE_ARTIFACT_ROOT` (opsional; default di `storage/app/private/intelligence`, harus di luar web root)
- `INTELLIGENCE_RECIPIENT_PEPPER`, `INTELLIGENCE_RECIPIENT_TTL_SECONDS`

**Hermes (keluar):**
- `HERMES_WEBHOOK_ENABLED` — **`false`** untuk QA dan produksi awal
- `HERMES_WEBHOOK_URL`, `HERMES_WEBHOOK_ALLOWED_HOST`, `HERMES_WEBHOOK_SECRET` — diisi hanya setelah host Hermes tersedia; wajib HTTPS dan FQDN
- `HERMES_WEBHOOK_UAT_SCOPE_ENABLED` — **`false`**
- `HERMES_WEBHOOK_TIMEOUT_SECONDS`, `HERMES_WEBHOOK_MAX_ATTEMPTS`, `HERMES_WEBHOOK_BACKOFF_BASE_SECONDS`, `HERMES_WEBHOOK_BACKOFF_CAP_SECONDS`

**Hermes (masuk, secret terpisah per jalur):**
- `HERMES_INTELLIGENCE_CALLBACK_SECRET`, `HERMES_INTELLIGENCE_CALLBACK_TOLERANCE_SECONDS`
- `HERMES_INTELLIGENCE_SNAPSHOT_SECRET`, `HERMES_INTELLIGENCE_SNAPSHOT_TOLERANCE_SECONDS`
- `HERMES_INTELLIGENCE_ARTIFACT_SECRET`, `HERMES_INTELLIGENCE_ARTIFACT_TOLERANCE_SECONDS`
- `HERMES_INTELLIGENCE_DELIVERY_STATUS_SECRET`, `HERMES_INTELLIGENCE_DELIVERY_STATUS_TOLERANCE_SECONDS`
- `HERMES_INTELLIGENCE_RECIPIENT_SECRET`, `HERMES_INTELLIGENCE_RECIPIENT_TOLERANCE_SECONDS`

**Email intelligence:**
- `INTELLIGENCE_EMAIL_TEST_MODE` — `true` di QA, dan `INTELLIGENCE_EMAIL_TEST_ALLOWLIST` hanya berisi alamat uji
- `INTELLIGENCE_DELIVERY_STATUS_MESSAGE_DOMAIN`

**Sisi Hermes (diatur di host Hermes, bukan di repo ini):** `JMI_HOME`, `JMI_ARTIFACT_PIPELINE_ENABLED`, `JMI_ARTIFACT_DELIVERY_ENABLED`, `JMI_EMAIL_DELIVERY_ENABLED`, `JMI_EMAIL_STATUS_SYNC_ENABLED`. Untuk QA semua `false` kecuali yang diputuskan.

**Isolasi lokal vs produksi:** setiap secret, `JMI_HOME`, subscription, cron pemulihan, dan endpoint harus unik per lingkungan. Jangan menyalin `.env` lokal ke hosting.

---

## 4. Konfigurasi PHP

Di cPanel: **MultiPHP INI Editor** (atau `.user.ini` di app root) untuk PHP 8.3 yang dipakai web dan CLI:

| Directive | Nilai | Status |
|---|---|---|
| `post_max_size` | `16M` (minimal di atas 10 MB artefak) | **wajib**, diuji |
| `memory_limit` | `256M` | disarankan, belum diuji |
| `max_execution_time` | `60` | disarankan, belum diuji |
| `upload_max_filesize` | tidak dipakai (upload artefak raw PUT) | tidak wajib |

Ekstensi yang harus aktif: `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`, `zip`, `pdo_mysql`.

Jika `post_max_size` di bawah 11M, upload artefak besar ditolak sebelum signature dicek (413 generik). Penolakan itu aman, tetapi bukan kontrak yang diharapkan.

---

## 5. Langkah deploy QA (D1) — hanya ke `dokumen6_joyumkm_qa`

1. **Siapkan rilis di laptop:** `git archive` atau salin tanpa `.env`, `node_modules`, `.git`, `storage/logs`. Jalankan `npm ci && npm run build`, lalu sertakan `public/build`.
2. **Unggah** ke `/home/dokumen6/joyumkm.dokumenkita.online` (bukan `public_html`). Pastikan document root menunjuk ke `public/`.
3. **Dependensi (PHP 8.3.37 CLI):**
   ```
   cd /home/dokumen6/joyumkm.dokumenkita.online
   <PHP_CLI_8.3.37> /path/to/composer install --no-dev --optimize-autoloader --no-interaction
   ```
4. **`.env`:** buat dari template, isi nama variabel di bagian 3 dengan nilai yang diisi pemilik. Set izin `chmod 600 .env`.
5. **Izin direktori:**
   ```
   chmod 755 storage bootstrap/cache
   mkdir -p storage/app/private/intelligence storage/framework/{cache,sessions,views} storage/logs
   chmod 700 storage/app/private storage/app/private/intelligence
   ```
   Jangan jalankan `php artisan storage:link` untuk folder private.
6. **Migrasi (hanya DB QA):**
   ```
   <PHP_CLI_8.3.37> artisan migrate:status
   <PHP_CLI_8.3.37> artisan migrate --force
   ```
   Jalankan `migrate` **hanya** setelah `migrate:status` diperiksa. Jangan pernah `migrate:fresh` di database yang berisi data.
7. **Cache konfigurasi (setelah `.env` final):**
   ```
   <PHP_CLI_8.3.37> artisan config:cache
   <PHP_CLI_8.3.37> artisan route:cache
   <PHP_CLI_8.3.37> artisan view:cache
   ```
8. **Smoke test (tanpa data nyata):**
   - `curl -sI https://joyumkm.dokumenkita.online/up` → `200`
   - `curl -sI https://joyumkm.dokumenkita.online/api/umkms` → `401` dan header `Cache-Control` mengandung `no-store`, `X-LiteSpeed-Cache-Control: no-cache`
   - `curl -sI https://joyumkm.dokumenkita.online/.env` → `403` atau `404`
   - `curl -sI https://joyumkm.dokumenkita.online/storage/app/private/intelligence/` → `403` atau `404`
9. **Uji cron aman (tanpa webhook):**
   ```
   <PHP_CLI_8.3.37> artisan hermes:cron --budget=60
   ```
   Output yang diharapkan: `Pengiriman webhook ditunda (disabled).` dengan exit 0. Tidak ada job yang diantrekan.
10. **Jangan pasang** `schedule:run` cron untuk Hermes (lihat bagian 7).

---

## 6. Probe pemilik (satu blok, dijalankan di Terminal cPanel)

Probe ini hanya mencetak versi dan status. Tidak mencetak secret, tidak meminta password atau private key. Jalankan bagian per bagian dan simpan hasilnya.

### 6.1 PHP CLI dan ekstensi
```
ls /opt/cpanel/ea-php83/root/usr/bin/php 2>/dev/null; ls /opt/alt/php83/usr/bin/php 2>/dev/null; which -a php
```
Pilih path yang versinya 8.3.37, lalu jalankan (ganti `PHP_CLI`):
```
PHP_CLI=/path/hasil/probe
$PHP_CLI -v | head -1
$PHP_CLI -r 'foreach (["zip","pdo_mysql","fileinfo","mbstring","openssl","tokenizer","iconv","session","filter","libxml"] as $e) echo $e, "=", extension_loaded($e) ? "ok" : "MISSING", PHP_EOL; echo "post_max_size=", ini_get("post_max_size"), " memory_limit=", ini_get("memory_limit"), " max_execution_time=", ini_get("max_execution_time"), PHP_EOL;'
```
Syarat lulus: versi 8.3.37, semua ekstensi `ok`, `post_max_size` minimal `16M`.

### 6.2 Python
```
/opt/alt/python312/bin/python3.12 -I -c 'import sys; print(sys.version.split()[0])'
```
Syarat lulus: `3.12.16`.

### 6.3 QPDF dengan lingkungan terbatas (meniru validator)
```
QPDF=/home/dokumen6/.local/opt/qpdf-12.4.2/bin/qpdf
$QPDF --version | head -1
env -i PATH=/usr/bin:/bin "$QPDF" --version | head -1
```
Baris kedua menguji bahwa QPDF berjalan tanpa `LD_LIBRARY_PATH` (RPATH). Jika baris kedua gagal (`error while loading shared libraries`), laporkan sebagai blocker. Jangan menambahkan `LD_LIBRARY_PATH` tanpa persetujuan karena validator tidak meneruskannya.

Uji validator dengan PDF contoh di repo (lingkungan dibatasi seperti di PHP):
```
APP=/home/dokumen6/joyumkm.dokumenkita.online
env -i PATH=/usr/bin:/bin /opt/alt/python312/bin/python3.12 -I "$APP/app/Services/validate-intelligence-pdf.py" "$QPDF" "$APP/docs/evidence/p3-07/samples/complete/report.pdf"; echo "exit=$?"
```
Syarat lulus: `exit=0`.

Uji negatif (QPDF hilang harus gagal tertutup):
```
env -i PATH=/usr/bin:/bin /opt/alt/python312/bin/python3.12 -I "$APP/app/Services/validate-intelligence-pdf.py" /tidak/ada/qpdf "$APP/docs/evidence/p3-07/samples/complete/report.pdf"; echo "exit=$?"
```
Syarat lulus: `exit` bukan `0`.

### 6.4 MariaDB (diisi password di prompt; jangan tempel password ke chat)
```
mysql -u <DB_USERNAME> -p dokumen6_joyumkm_qa -e "SELECT VERSION(); SET SESSION time_zone = '+00:00'; SELECT @@session.time_zone, @@global.time_zone; SELECT @@character_set_server, @@collation_server;"
```
Syarat lulus: versi `11.4.13`, `@@session.time_zone` = `+00:00`, collation `utf8mb4_*` tersedia.

### 6.5 Cron minimum interval
Di cPanel **Cron Jobs**, buat entri `*/6 * * * *` dengan perintah sementara `date >> /home/dokumen6/logs/cron-probe.log`. Konfirmasi entri tersimpan dan berjalan. Hapus entri probe setelah itu.

### 6.6 Izin dan web root
```
stat -c '%a %n' /home/dokumen6/joyumkm.dokumenkita.online/.env /home/dokumen6/joyumkm.dokumenkita.online/storage /home/dokumen6/joyumkm.dokumenkita.online/bootstrap/cache
```
Syarat: `.env` tidak terbaca publik (`600` atau `640`), `storage` dan `bootstrap/cache` dapat ditulis PHP.

### 6.7 Konektivitas keluar ke host Hermes (diharapkan gagal sampai host tersedia)
```
curl -sS -o /dev/null -w '%{http_code}\n' --max-time 10 https://hermes-joyumkm.dokumenkita.online/
```
Hasil yang diharapkan saat ini: gagal (host belum tersedia). Catat hasilnya. Ini bukan blocker D1 selama `HERMES_WEBHOOK_ENABLED=false`.

### 6.8 HTTPS dan proxy
Verifikasi bahwa `APP_URL` memakai `https://joyumkm.dokumenkita.online` dan bahwa Laravel melihat skema `https` di balik LiteSpeed. Jika tidak, laporkan: signature HMAC bergantung pada host dan skema yang dilihat aplikasi, dan konfigurasi trusted proxy belum dibuat di repo.

---

## 7. Cron dan antrean Hermes

**Entri cron (pasang setelah D1 QA lulus, dengan `HERMES_WEBHOOK_ENABLED=false`):**
```
*/6 * * * * cd /home/dokumen6/joyumkm.dokumenkita.online && <PHP_CLI_8.3.37> artisan hermes:cron --budget=300 >> /home/dokumen6/logs/hermes-cron.log 2>&1
```
- `--budget` 30–330 detik (di bawah interval 6 menit). Default 240.
- Perintah hanya memproses antrean `hermes-webhooks`; antrean `default` tidak disentuh.
- Kunci `storage/app/hermes-webhook-cron.lock` mencegah siklus bertumpuk.

**Jangan** pasang `* * * * * ... artisan schedule:run` untuk Hermes. `routes/console.php` menjadwalkan `hermes:webhook-scan` setiap menit yang tidak memakai kunci `hermes:cron`. Keduanya tidak boleh berjalan bersamaan pada hosting. Rekomendasi: hanya `hermes:cron`. Keputusan ada di bagian 9.

**Menghentikan rollout (rollback aman):**
1. Set `HERMES_WEBHOOK_ENABLED=false` di `.env`, lalu `<PHP_CLI> artisan config:clear && artisan config:cache`.
2. Hapus baris cron `hermes:cron` di cPanel.
3. Event yang sudah `pending` tetap tersimpan; tidak ada yang terkirim.

**Memeriksa status (baca-saja):**
```
<PHP_CLI_8.3.37> artisan hermes:webhook-status
```

**Requeue event `dead`:** hanya manual dan per event, lewat `hermes:webhook-requeue <event_id>` setelah penyebab diketahui.

---

## 8. Pemisahan lokal dan produksi

| Aspek | Lokal (laptop) | Hosting QA | Produksi |
|---|---|---|---|
| Database | MySQL 8.4 / MariaDB Docker (disposable) | `dokumen6_joyumkm_qa` | belum ditentukan |
| Hermes webhook | `false` | `false` | `false` sampai bagian 9 |
| Secret | unik | unik | unik, tidak disalin |
| `JMI_HOME` dan subscription | unik | unik | unik |
| Cron pemulihan | tidak ada | `hermes:cron` (setelah lulus) | belum |

---

## 9. Gerbang produksi (checklist, **tidak dijalankan**)

Semua poin harus terpenuhi dan disetujui pemilik sebelum deploy produksi:

- [ ] Probe bagian 6 lulus seluruhnya, atau ada keputusan tertulis untuk setiap kegagalan.
- [ ] D1 QA lulus: smoke test bagian 5 langkah 8 dan 9.
- [ ] Keputusan backlog Hermes: cutoff atau tinjauan manual sebelum `HERMES_WEBHOOK_ENABLED=true`.
- [ ] Keputusan jadwal: hanya `hermes:cron`, tanpa `schedule:run` untuk Hermes.
- [ ] Host `hermes-joyumkm.dokumenkita.online` tersedia dengan HTTPS dan FQDN yang sesuai `HERMES_WEBHOOK_ALLOWED_HOST`.
- [ ] Secret Hermes dibuat baru untuk produksi (tidak dipakai ulang dari QA atau lokal).
- [ ] Backup database produksi diambil dan diuji pemulihannya.
- [ ] `migrate:status` pada database produksi diperiksa; hanya migrasi pending yang dijalankan, dengan `--force`, setelah persetujuan tertulis.
- [ ] Tidak ada perubahan data historis; tidak ada `migrate:fresh`.
- [ ] Rollout tetap OFF (`HERMES_WEBHOOK_ENABLED=false`) pada hari deploy.

---

## 10. Hasil yang harus dilaporkan pemilik setelah probe
Kirim ringkasan pass/fail per item bagian 6 (tanpa output yang memuat secret). Item yang gagal disertai pesan error. Dengan itu D1 dapat diputuskan.
