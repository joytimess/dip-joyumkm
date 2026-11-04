# JoyUMKM — UMKM Health Dashboard

JoyUMKM membantu pendamping mengelola UMKM miliknya, mengisi evaluasi INS/ECT/OPS, dan membaca ringkasan kesehatan melalui radar, distribusi, serta perbandingan. Setiap akun memiliki kapabilitas yang sama; tidak ada administrator global atau akses silang akun. Data bisnis berasal dari API dan MySQL; Blade hanya mengirim shell halaman.

Acuan produk adalah `PRD-JoyUMKM-Fullstack-End-to-End-V1.md`, Bagian 1–18 mengungguli Lampiran A. Status tahap, bukti pemeriksaan, serta matriks penerimaan/integrasi ada di [docs/IMPLEMENTATION.md](docs/IMPLEMENTATION.md). Kontrak lengkap ada di [docs/API.md](docs/API.md).

## Prasyarat

- PHP 8.3+ dengan extension Laravel, termasuk PDO MySQL, mbstring, OpenSSL, tokenizer, XML, ctype, fileinfo, session; Composer 2.
- MySQL 8 dengan collation case-insensitive `utf8mb4_0900_ai_ci`.
- Node sesuai engine Vite 8: `^20.19.0 || >=22.12.0`, serta npm.
- Browser desktop modern atau viewport mobile mulai 360 px.

Lockfile mematok Laravel 13.40.0, Sanctum 4.3.3, Vite 8.3.5, Tailwind CSS 4.3.4, dan ApexCharts 8.7.0. Lingkungan implementasi memakai PHP 8.3.36, Composer 2.8.12, Node 22.19.0, npm 10.9.3, MySQL 8.4.3. Gunakan `composer install` dan `npm ci` untuk mengulang versi terkunci.

## Instalasi dari checkout

Jalankan dari direktori proyek. Verifikasi `php -v`, `composer --version`, `node -v`, dan `npm -v`.

Windows PowerShell:

```powershell
if (-not (Test-Path -LiteralPath .env)) { Copy-Item -LiteralPath .env.example -Destination .env }
composer install
npm ci
```

Bash:

```bash
test -f .env || cp .env.example .env
composer install
npm ci
```

Buat schema baru melalui akun MySQL yang berwenang; siapkan user aplikasi dan grant sesuai server lokal:

```sql
CREATE DATABASE IF NOT EXISTS joyumkm CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

Edit `.env`: isi `DB_HOST`, `DB_PORT`, `DB_DATABASE=joyumkm`, `DB_USERNAME`, `DB_PASSWORD` sesuai MySQL tersebut. `.env.example` memakai port 3306. Lingkungan implementasi memakai instance terpisah 3307; ini bukan kewajiban instalasi. Set `APP_NAME=JoyUMKM`, `APP_ENV=local`, dan `APP_URL` sesuai alamat serve. Locale/fallback adalah `id`; timestamp API memakai UTC ISO 8601.

Isi `DEMO_PASSWORD` dengan pilihan Anda (minimal 8 karakter, maksimal 72 byte, tanpa NUL) untuk demo lokal; kosongkan bila hanya memerlukan master data.

```sh
php artisan key:generate
php artisan config:clear
php artisan migrate --no-interaction
php artisan db:seed --no-interaction
npm run build
php artisan serve
```

`key:generate` untuk instalasi baru; pertahankan APP_KEY pada instalasi berjalan. Jangan menjalankan `migrate:fresh` pada database aplikasi berisi data penting. Buka alamat yang dicetak `php artisan serve`. Untuk hot reload jalankan `npm run dev` di terminal kedua. Build biasa memakai `public/build/manifest.json`; jika dev server berhenti tetapi `public/hot` tersisa, hapus file tersebut agar Blade memakai build. Dari direktori proyek, PowerShell:

```powershell
if (Test-Path -LiteralPath public/hot) { Remove-Item -LiteralPath public/hot }
```

Tidak perlu queue worker atau Redis untuk alur inti; baseline memakai queue sync, session file, cache file. Sanctum, migration, serta aset sudah tersedia dalam checkout; tidak perlu `laravel new`/`install:api` lagi.

### Pemulihan Composer pada Windows

Pada pemeriksaan instalasi bersih lokal, ekstraksi dependency berhasil tetapi proses berhenti lama pada `Generating optimized autoload files`. Jika mengalami kondisi yang sama, hentikan proses Composer tersebut di terminalnya, lalu gunakan autoload biasa untuk pengembangan lokal. Lockfile tidak berubah. Jalankan dari direktori proyek dan pastikan nama backup berikut belum digunakan:

```powershell
Copy-Item -LiteralPath composer.json -Destination composer.json.joyumkm-backup
composer config optimize-autoloader false
composer dump-autoload --no-scripts --no-plugins --no-interaction
composer install --no-interaction --prefer-dist
Move-Item -LiteralPath composer.json.joyumkm-backup -Destination composer.json -Force
```

Pemulihan ini telah lolos instalasi beserta package discovery pada lingkungan handover. Manifest dipulihkan setelah selesai; jika perintah gagal, tetap pulihkan backup sebelum melanjutkan. Penyebab internal perlambatan optimizer belum ditetapkan. Optimasi autoload pada deployment perlu diverifikasi di lingkungan operator.

## Demo lokal

Master seeder membuat lima sektor, tiga faktor, 16 soal aktif (INS 6, ECT 4, OPS 6), dan menggunakan 38 provinsi dari config. DatabaseSeeder memanggil demo hanya pada `APP_ENV=local`; DemoSeeder juga memiliki guard internal untuk pemanggilan langsung di nonlocal.

Akun demo bernama **Warjon**, email **demo@joyumkm.test**, password sesuai `DEMO_PASSWORD` lokal (`config/umkm.php`). Password kosong membuat demo dilewati dengan peringatan; master tetap tersedia.

| UMKM | Provinsi | Sektor | Omzet (Rp) | Status | Skor awal |
|---|---|---|---:|---|---:|
| Kawalogy | DKI Jakarta | Makanan & Minuman | 15.000.000 | final | 96,5 |
| Warjon | DKI Jakarta | Makanan & Minuman | 50.000.000 | final | 95,1 |
| Upside Down | Sumatera Utara | Grosir & Retail | 5.000.000 | final | 91,7 |
| ayam geprek | Nusa Tenggara Barat | Makanan & Minuman | 2.000.000 | draft | 87,5 |

Skor dihitung service dari jawaban integer, bukan hardcode angka perkiraan brief. Draft boleh memiliki seluruh jawaban. Grup akun demo dan empat fixture dibuat atomik, create-if-missing berdasarkan email. Jika email demo sudah ada, seluruh grup dilewati: seeder tidak mereset password, menimpa edit, menambahkan ulang UMKM yang dihapus, atau menempelkan fixture ke akun existing. Mengubah DEMO_PASSWORD lalu seed ulang tidak mengubah password akun existing. Gunakan schema lokal baru bila membutuhkan fixture awal lagi.

### Data contoh (SampleDataSeeder)

DatabaseSeeder juga memanggil SampleDataSeeder pada `APP_ENV=local` untuk mengisi data dummy yang membuat tiga grafik dashboard (Profil Faktor, Distribusi, Perbandingan), Home, dan tabel UMKM terlihat hidup.

Isi `SEED_PASSWORD` di `.env` (minimal 8 karakter, maksimal 72 byte, tanpa NUL). Profil aktif ditentukan lewat `SEED_PROFILE`:

| Variabel | Nilai | Keterangan |
|---|---|---|
| `SEED_PASSWORD` | pilihan Anda | Wajib diisi agar SampleDataSeeder berjalan. |
| `SEED_PROFILE` | `small` (default) | ≈20 UMKM, 3 akun, maks 3 evaluasi/UMKM. |
| `SEED_PROFILE` | `benchmark` | ≈1.000 UMKM, 10 akun, maks 10 evaluasi/UMKM. |

Contoh pengisian `.env`:

```dotenv
SEED_PASSWORD=JoyUMKM-Sample-2026!
SEED_PROFILE=small
```

Jalankan seeder:

```sh
php artisan config:clear
php artisan db:seed --no-interaction
```

Atau hanya SampleDataSeeder (hanya pada `APP_ENV=local`):

```sh
php artisan db:seed --class=SampleDataSeeder --no-interaction
```

Ketiga akun sample memakai email `sample1@joyumkm.test`, `sample2@joyumkm.test`, `sample3@joyumkm.test` dengan password sesuai `SEED_PASSWORD`. SampleDataSeeder bersifat idempotent: jika email `sample1@joyumkm.test` sudah ada, seluruh grup dilewati tanpa perubahan. Data demo (akun Warjon dan empat UMKM-nya) tidak tersentuh.

## Formula dan grafik

Untuk faktor berjawaban: `raw = jumlah nilai / jumlah terjawab`, lalu `normalized = (raw - scale_min) / (scale_max - scale_min) × 100`. Skor akhir adalah mean faktor non-null pada presisi penuh, dibulatkan half-up satu desimal. Faktor disimpan empat desimal dan ditampilkan satu desimal; bobot faktor sama. Faktor tanpa jawaban dikeluarkan dari mean; semua kosong menghasilkan null, bukan nol.

Kategori dari skor akhir yang sudah dibulatkan: ≥85 SANGAT BAIK; ≥70 BAIK; ≥55 CUKUP; ≥40 KURANG; <40 KRITIS; null BELUM DINILAI. Threshold, warna, dan presisi berasal dari `config/umkm.php`, diteruskan questionnaire ke JS. Server selalu menghitung ulang saat simpan.

Setiap simpan membuat riwayat baru. Latest adalah ID terbesar, termasuk draft; draft kosong setelah final mengganti kontribusi final lama. Skor nol tetap dihitung. Total responden menghitung UMKM yang punya evaluasi termasuk draft kosong; total donut menghitung seluruh UMKM. Ringkasan Home/UMKM mencakup seluruh portofolio, tidak mengikuti filter tabel.

Radar membandingkan satu UMKM dengan rerata faktor portofolio; faktor pilihan belum lengkap ditampilkan sebagai teks dan CTA, tanpa polygon null=0. Distribusi menunjukkan kategori dan mean skor non-null tiap sektor. Comparison menampilkan maksimal lima UMKM dengan garis mean faktor seluruh portofolio, termasuk UMKM di luar pilihan. Faktor kosong berlabel Belum dijawab, bukan bar nol. Rincian angka menjadi alternatif tekstual grafik.

## Contoh API nyata dengan curl

Contoh memakai Bash (misalnya Git Bash), `curl`, dan `jq`; jalankan pada aplikasi lokal. Semua ID/opsi berasal dari API. Token disimpan pada variabel sesi, jangan letakkan pada URL/log atau bagikan. Contoh membuat satu akun dan UMKM baru.

```bash
set -e
BASE='http://localhost:8000/api'
EMAIL="uji-$(date +%s)@example.test"
PASSWORD='JoyUMKM-Local-2026!'
REGISTER=$(jq -n --arg email "$EMAIL" --arg password "$PASSWORD" '{name:"Pendamping Lokal",email:$email,password:$password,password_confirmation:$password}' | curl -fsS "$BASE/auth/register" -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @-)
REGISTER_TOKEN=$(printf '%s' "$REGISTER" | jq -er '.data.token')
curl -fsS "$BASE/auth/logout" -X POST -H 'Accept: application/json' -H "Authorization: Bearer $REGISTER_TOKEN"
LOGIN=$(jq -n --arg email "$EMAIL" --arg password "$PASSWORD" '{email:$email,password:$password}' | curl -fsS "$BASE/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @-)
TOKEN=$(printf '%s' "$LOGIN" | jq -er '.data.token')
curl -fsS "$BASE/auth/me" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
OPTIONS=$(curl -fsS "$BASE/umkms/options" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN")
BODY=$(printf '%s' "$OPTIONS" | jq '{name:"Usaha Lokal",province:.data.provinces[0],sector_id:.data.sectors[0].id,omzet:15000000}')
UMKM=$(printf '%s' "$BODY" | curl -fsS "$BASE/umkms" -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" --data-binary @-)
UMKM_ID=$(printf '%s' "$UMKM" | jq -er '.data.id')
QUESTIONNAIRE=$(curl -fsS "$BASE/questionnaire" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN")
ANSWERS=$(printf '%s' "$QUESTIONNAIRE" | jq '{status:"final",answers:[.data.factors[].questions[] | {question_id:.id,value:(.options | map(.value) | max)}]}')
printf '%s' "$ANSWERS" | curl -fsS "$BASE/umkms/$UMKM_ID/evaluations" -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" --data-binary @-
curl -fsS "$BASE/umkms/summary" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -fsS "$BASE/dashboard/factor-profile?umkm_id=$UMKM_ID" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -fsS "$BASE/dashboard/distribution" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -fsS "$BASE/dashboard/comparison" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -fsS "$BASE/auth/logout" -X POST -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
unset TOKEN REGISTER_TOKEN REGISTER LOGIN PASSWORD
```

Semua opsi maksimum pada instrumen awal menghasilkan 100 SANGAT BAIK. Contoh tidak retry POST otomatis; jika koneksi putus ketika simpan, baca latest sebelum mengirim lagi. Dua POST sukses membuat dua riwayat.

## Pemeriksaan lokal

Tes memakai schema MySQL **joyumkm_testing**, terpisah dari aplikasi. Buat schema kosong dan grant untuk user pengujian. Host/port/kredensial dapat diatur pada `.env.testing`; `phpunit.xml` memaksa driver mysql/database joyumkm_testing dan TestCase menolak nama lain. RefreshDatabase dapat membuat ulang schema pengujian; jangan menyimpan data penting di sana.

```sql
CREATE DATABASE IF NOT EXISTS joyumkm_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

```sh
php artisan config:clear
php artisan test --compact
npm test
php artisan route:list --path=api --except-vendor
npm run build
```

Ini perintah untuk mengulang pemeriksaan, bukan klaim hasil pada setiap lingkungan. PASS/FAIL/NOT RUN dan bukti ada di [laporan implementasi](docs/IMPLEMENTATION.md) serta `docs/evidence/`. Walkthrough: daftar akun A → tambah dua UMKM → simpan final dan draft → periksa Home/tabel/tiga grafik → ubah sektor → periksa summary → hapus UMKM → logout. Akun B harus mendapat 404 saat mencoba ID A.

## Autentikasi, batas, dan asumsi V1

Sanctum memakai Bearer token, baseline tujuh hari dari `SANCTUM_TOKEN_EXPIRATION_DAYS` (minimum satu hari), tanpa refresh token. Logout mencabut token saat ini saja. Login/register dibatasi lima percobaan per menit; login memakai IP+email, register IP. Protected 401 membersihkan sesi lokal; network error/404/422/429/500 mempertahankan token.

Browser menyimpan `joyumkm.token` dan `joyumkm.user` di localStorage. Shell dapat dikirim tanpa token; konten ditahan sampai `/api/auth/me` berhasil. Guard JS menjaga navigasi; `auth:sanctum` dan service ownership menjaga data. localStorage Bearer dapat dicuri oleh XSS: tampilkan data pengguna sebagai escaped text/textContent, jangan sisipkan HTML pengguna, dan gunakan HTTPS. Sesi antar-tab disinkronkan melalui storage event.

Placeholder nonaktif hanya Help, Profil Saya, notifikasi, PDF panduan, serta informasi footer Pusat Bantuan/Kebijakan Privasi yang belum tersedia. CRUD, evaluasi, dan grafik memakai API nyata. Di luar V1: ekspor laporan, upload, pembayaran, mobile app, AI/rekomendasi naratif, editor soal, halaman riwayat evaluasi, lupa password, verifikasi email.

Omzet berupa rupiah tanpa klaim periode. Instrumen mempertahankan nilai modal bank/kredit dari brief; skor bukan diagnosis finansial tervalidasi. Threshold tetap selama V1. Perubahan makna soal dibuat sebagai soal baru, soal lama dinonaktifkan; histori tidak dihitung ulang otomatis. Desain mengikuti uraian PRD karena gambar referensi/PDF belum disediakan.

## Deployment

Petunjuk berikut untuk operator; pengembangan lokal tidak berarti server sudah dideploy. Document root ke `public`, HTTPS aktif, `APP_ENV=production`, `APP_DEBUG=false`, `APP_NAME=JoyUMKM`, APP_URL benar, database privat, APP_KEY stabil. Atur izin tulis terbatas `storage`/`bootstrap/cache`, backup database, dan jangan kirim `.env`/akun demo lokal ke produksi.

```sh
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Master seeder dijalankan terpisah setelah review operator; demo diblokir pada nonlocal. Hapus public/hot yang tersisa sebelum menyajikan build. Scheduler tidak dipasang otomatis. Token expired tetap ditolak walau barisnya belum dihapus; bila operator menyiapkan scheduler, jadwalkan `php artisan sanctum:prune-expired --hours=24` harian untuk token yang expired setidaknya 24 jam. Perubahan threshold/rekomputasi historis memerlukan kebijakan versi terlebih dahulu.
