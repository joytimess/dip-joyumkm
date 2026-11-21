# Handoff JoyUMKM V1 P2

20 November 2026 · **P2 PASS lokal** · Penilaian Enam Faktor per UMKM

## Implementasi

Nama UMKM di daftar membuka halaman detail. Dari detail, pemilik bisa membuka Penilaian Enam Faktor atau kuesioner V1. Kedua halaman baru menggunakan layout, navigasi, klien API dan sesi Sanctum V1. Akses data tetap diperiksa oleh API berdasarkan kepemilikan UMKM.

| Bagian | Lokasi |
|---|---|
| Route web dan tautan dari daftar | [routes/web.php](../../routes/web.php), [umkms.js](../../resources/js/pages/umkms.js), [umkms.blade.php](../../resources/views/umkms.blade.php) |
| Detail UMKM dan shell V1 | [umkm-detail.blade.php](../../resources/views/umkm-detail.blade.php), [umkm-detail.js](../../resources/js/pages/umkm-detail.js), [layouts/app.blade.php](../../resources/views/layouts/app.blade.php), [app.js](../../resources/js/app.js) |
| Draft, dialog, Final terbaru dan Histori Final | [six-factor-evaluation.blade.php](../../resources/views/six-factor-evaluation.blade.php), [six-factor-evaluation.js](../../resources/js/pages/six-factor-evaluation.js), [six-factor-draft.js](../../resources/js/six-factor-draft.js) |
| Komponen yang digunakan ulang | [api.js](../../resources/js/api.js), [auth.js](../../resources/js/auth.js), dialog native dan [ui.js](../../resources/js/ui.js), ApexCharts 8.7.0 dan [charts.js](../../resources/js/charts.js) |
| Harness | [tes JavaScript](../../tests/js/six-factor-draft.test.js), [Chrome melalui Playwright Core](../../tests/js/six-factor-browser.mjs), [tes HTTP P1](../../tests/Feature/SixFactorEvaluationApiTest.php), [tes konkurensi MySQL](../../tests/Feature/SixFactorEvaluationConcurrencyTest.php) |

Autosave menunggu 700 ms sejak perubahan terakhir dan mengirim faktor yang berubah sebagai integer 1–5 atau `null`. Tiap request mengakui revisi yang dikirimnya. Form menahan nilai yang gagal disimpan dan memberi aksi coba lagi. Finalisasi mengecek ulang ID dan enam skor di server. Batal menunggu request yang sudah dikirim dan menahan antrean yang belum dikirim. Sesudah aksi terminal, halaman membaca kembali E1, E5, dan E6. Perpindahan fokus juga menyinkronkan ketiganya.

## Kandidat dan lingkungan verifikasi

- Basis `main@804738b0a39f9dc2658e40e92e796077d131aca6`. Seluruh 20 hash P1 cocok sebelum dan sesudah perubahan. Manifest P1 `6b29523e414d95a3076269f32a2bc9412339ca5c54dea57e65c2e9d38fa6a250`. [Handoff P1](joyumkm-v1-p1-handoff.md)
- Manifest kandidat P2 `eec94f89ce8cac63d875386f4bee7d8d1d1f17a16a46a7daad8068fe4ce0bbe8`, 35 file. SHA-256 `public/build/manifest.json` adalah `8cfa3ac8852bf3d64455a18ad58bf4f4c7d97dc7401f4e92d8ad3e23855d5316`.
- PHP 8.3.36, Laravel 13.40.0, MySQL 8.4.3, Node 22.19.0, Chrome 158.0.8127.36, Playwright Core 1.65.1. Browser memakai Vite build, PHP server loopback, serta akun dan UMKM fixture yang dibuat lewat API.
- Sebelum browser menulis, preflight dengan konfigurasi yang diwarisi server memeriksa `SELECT DATABASE()` dan lingkungan aplikasi. Hasilnya `joyumkm_testing` dan `testing`. Browser dan PHPUnit berjalan berurutan pada database uji tersebut.

## Perintah verifikasi

```powershell
npm test
npm run build
php artisan view:cache --no-interaction
php artisan route:list --path=umkms --no-interaction
php vendor/bin/pint --dirty --format agent
php -l routes/web.php
git diff --check

$focused = Join-Path $env:TEMP 'joyumkm-p2-focused.xml'
$full = Join-Path $env:TEMP 'joyumkm-p2-full.xml'
php vendor/bin/phpunit --filter SixFactorEvaluation --log-junit $focused
php vendor/bin/phpunit --log-junit $full
```

Untuk browser, jalankan server dengan konfigurasi uji. Jalankan PHPUnit setelah browser selesai karena suite dapat mereset data uji.

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='joyumkm_testing'
$env:DB_URL=''
$env:SESSION_DRIVER='array'
$env:CACHE_STORE='array'
$env:BCRYPT_ROUNDS='4'
@'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db, VERSION() AS version');
if ($db->db !== 'joyumkm_testing' || !$app->environment('testing')) { exit(1); }
echo json_encode($db), PHP_EOL;
'@ | php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php artisan serve --host=127.0.0.1 --port=8129 --no-reload --no-interaction
```

Jalankan harness di terminal lain. Instalasi Playwright berada di folder sementara. Harness memakai Chrome lokal dan menulis dua tangkapan layar ke `%TEMP%`. [Dokumentasi Playwright untuk pengujian jaringan](https://playwright.dev/docs/network)

```powershell
$browserTools = Join-Path $env:TEMP 'joyumkm-p2-tools'
npm install --prefix $browserTools --no-save --ignore-scripts --no-audit playwright-core@1.65.1
$env:JOYUMKM_BROWSER_DB='joyumkm_testing'
$env:JOYUMKM_PLAYWRIGHT=Join-Path $browserTools 'node_modules/playwright-core'
$report=Join-Path $env:TEMP 'joyumkm-p2-browser.txt'
node tests/js/six-factor-browser.mjs | Tee-Object -FilePath $report
```

## Bukti penerimaan

Chrome menyelesaikan **13/13 skenario** pada run `19943264` tanpa error JavaScript di halaman yang dipantau. Pemeriksaan visual memakai `joyumkm-p2-desktop.png` dan `joyumkm-p2-mobile.png`. Delay respons, putus koneksi, dan 500 disimulasikan melalui intersepsi browser. Skenario konkurensi, validasi, histori, akses akun, dan perubahan status menggunakan API lokal dan MySQL.

| AC | Status | Bukti |
|---|---|---|
| 01 | PASS | Chrome membuka nama UMKM dari daftar, masuk dari detail ke penilaian milik UMKM itu, lalu kembali ke detail melalui tautan. |
| 02 | PASS | Browser memeriksa urutan label, opsi kosong dan 1–5, navigasi keyboard, pengosongan dengan Enter, serta tombol kosongkan dengan sentuhan emulasi Chrome. |
| 03 | PASS | Respons pertama ditahan sesudah API menerima 201. Tidak ada PATCH kedua selama respons ditahan. PATCH berikutnya mendapat 200 dan berisi revisi terakhir serta `null`. Offline mempertahankan input, memicu peringatan keluar dan logout, serta pulih lewat coba lagi. |
| 04 | PASS | E1 tetap null setelah halaman kosong melewati debounce. Browser mengisi sebagian, muat ulang, mengosongkan satu nilai, lalu muat ulang dengan nilai faktor lain tetap utuh. |
| 05 | PASS | Dengan enam nilai valid, Finalisasi tetap nonaktif selama debounce dan PATCH berjalan. E3 terkirim sesudah pengakuan, dengan ID sama dan body `{}`. Browser menampilkan total 24 dan 30. Tes HTTP P1 memeriksa seluruh batas 6, 12, 13, 18, 19, 24, 25 dan 30 beserta kategori dari server. |
| 06 | PASS | Escape mengembalikan fokus dan mempertahankan Draft. Konfirmasi menunggu PATCH berjalan, memakai ID benar dan `{}`, serta mengabaikan perubahan yang belum dikirim. E1 tetap null lebih dari 700 ms setelah E3 dan E4. |
| 07 | PASS | Chrome memeriksa histori kosong, dua Final dalam urutan terbaru, E6 sama dengan elemen pertama E5 dan pembatalan tidak muncul di histori. Tie-break timestamp dan ID, serta pengecualian Draft dan Cancelled, diverifikasi di tes HTTP P1. |
| 08 | PASS | Dialog menampilkan angka Final historis 4 pada enam faktor dan panel terbaru tetap memakai angka 5. Radar enam sumbu skala 1–5. Kuesioner V1 menyimpan Draft lewat browser. Radar legacy pada hasil lengkap menampilkan tiga sumbu dan skala 0–100. |
| 09 | PASS | E1/E5/E6 menampilkan error publik dan retry terpisah. Browser memeriksa respons API aktual untuk 422 field/body, Final parsial, 409 dan ID usang antartab. Timeout fetch terjadi setelah E3 berhasil di server dan pulih lewat histori. Respons E4 putus setelah server membatalkan dan halaman menyampaikan bahwa hasil aksi belum dapat dipastikan. |
| 10 | PASS | Desktop 1440 px dan ponsel emulasi 360 px tanpa overflow halaman. Enam label radar muat di SVG. Dialog Batal juga diuji pada lebar 320 px. Escape mengembalikan fokus, termasuk saat pembaruan histori mengganti tombol pemicu. Skor tersedia sebagai teks. |
| 11 | PASS | Login dua akun lewat UI, akses asing 404, evaluasi salah induk 404 di API aktual. Pencabutan token saat halaman aktif memicu 401, membersihkan sesi, dan menghentikan autosave. Alur legacy lulus di Chrome dan regresi PHP. |
| 12 | PASS | Chrome 13/13, JavaScript 33/33, dan fokus API/MySQL 104/104 dengan 1.232 assertion. Suite PHP penuh 197/202, dengan tiga kegagalan dan dua error yang cocok dengan baseline berdasarkan nama dan jenis. |
| 13 | PASS | Perubahan aplikasi mencakup route dan UI individual yang menggunakan E1–E6 serta API detail V1. Seluruh 20 file P1 cocok dengan hash awal. `git diff --check`, build Vite, cache Blade, Pint, daftar route dan lint PHP lulus. |
| 14 | PASS | Manifest 35 file dikonfirmasi secara independen. Review menemukan sinkronisasi histori antartab, peringatan saat logout dengan perubahan tertunda, dan pemulihan fokus setelah tombol histori terganti. Perbaikan diuji ulang pada run Chrome yang tercatat di atas. |

## Regresi PHP penuh

Suite menghasilkan 2.030 assertion dengan kode keluar 2. Perbandingan JUnit `joyumkm-p2-full.xml` cocok dengan status setiap kasus dalam `joyumkm-p1-verified-full.xml` dan `joyumkm-p2-start.xml`, serta 98 kasus yang ada di `joyumkm-p0-baseline.xml`.

| Jenis | Kasus baseline | Gejala |
|---|---|---|
| FAIL | `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores` | Empat akun ditemukan, ekspektasi satu. |
| FAIL | `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` | Empat akun ditemukan, ekspektasi satu. |
| FAIL | `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` | Empat evaluasi pada profil dengan batas tiga. |
| ERROR | `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` | Argumen tes tidak disediakan. |
| ERROR | `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` | Pesan assertion terbaca sebagai nama koneksi. |

## Batas verifikasi

Verifikasi web dilakukan di Chrome desktop dan emulasi viewport serta sentuhan ponsel. Staging dan perangkat fisik di luar pengujian ini. Kontrak E2 menggabungkan skor parsial tanpa versi kondisional sehingga edit faktor yang sama dari dua sesi mengikuti urutan request yang diterima server. Jika respons E4 terputus, E1 dan E5 dapat menunjukkan Draft menghilang tanpa membuktikan status Cancelled untuk ID itu. UI menampilkan ketidakpastian tersebut.

## File manifest

Satu baris `<sha256 huruf kecil>  <path relatif>` per file, diurutkan menurut path secara ordinal. Gunakan UTF-8 tanpa BOM dan LF termasuk pada akhir baris terakhir. Manifest mencakup file tracked yang berubah dan file untracked, dengan pengecualian dokumen handoff P2 ini. Pakai 20 baris dari manifest P1 dan tambahkan 15 baris berikut.

| File | SHA-256 |
|---|---|
| `docs/evidence/joyumkm-v1-p1-handoff.md` | `fc6135cac0bd51f647f0ed07e55e7f9cb730091618866c8fa30afe86d20b4a9e` |
| `docs/prd/JoyUMKM_V1_PRD_Brief_P2.md` | `303af3241b96406309cafcc310613c5eecca933a48f2b61ff10a02f5825c93b3` |
| `package.json` | `baa9ffd31716dad3c66088a2bc2e37a2869edc443e3c3012a993ced9d2e2f9bc` |
| `resources/js/app.js` | `7c8750790437749cb61bb34027589a002f02afbcf6eacd8687207c70af22f7a4` |
| `resources/js/pages/six-factor-evaluation.js` | `113bd05bc456ad5b7ffe3b61e325a39202051301ac7263ddda10a3268d85bbd3` |
| `resources/js/pages/umkm-detail.js` | `780f0e31c42c6a9cb932eda30572d028186bc46b0b173920acec65a4124bb70b` |
| `resources/js/pages/umkms.js` | `67690d44c393258162b3a73e9526549226bc00f82b22b6f3e8f4353b64239279` |
| `resources/js/six-factor-draft.js` | `21ba785b683c4aa2d0cb3b46502afc296413fec0cddc2a9243127a2b4bba7699` |
| `resources/views/layouts/app.blade.php` | `9c48ba014df50222e7607c11046ffd2583a76fe226e2f35889b64fc3d1453f6d` |
| `resources/views/six-factor-evaluation.blade.php` | `7b4018e0f66294d1621349cee37fba164195cdf73869b414b4c64029ba1edf6f` |
| `resources/views/umkm-detail.blade.php` | `4a5fbc52146e67ee497e4d8932c253cb73082191d3321c412ab49975ab695a7b` |
| `resources/views/umkms.blade.php` | `6b29054cfcbbdf009600360bbb9b13ebfa40cb8d99151ff945c884d978a51368` |
| `routes/web.php` | `19a094c026ca13ea8cb08fcc1c5fe421ba88a5085dfb4e5ce4563dfd78744e9f` |
| `tests/js/six-factor-browser.mjs` | `003108ec3e78abbc3b21fe276b9b14a45fe8abddc345be2530ea8ee58a49eaad` |
| `tests/js/six-factor-draft.test.js` | `ce1d5cf18cb4f2373aad4819638876f7a68f97fbbff181350ef2893fb5899378` |
