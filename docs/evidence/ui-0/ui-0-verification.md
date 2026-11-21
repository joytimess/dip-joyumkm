# Handoff UI-0 JoyUMKM

## Keputusan teknis

JoyUMKM menggunakan navigasi parsial Blade. Tautan workspace tetap berupa URL biasa. Untuk klik internal biasa, `resources/js/workspace.js` mengambil dokumen Blade tujuan dan memasang hanya isi `#main-content`. Sidebar, drawer, topbar, toast, dan listener shell tetap memakai instance yang sama. JavaScript memperbarui URL, judul, menu aktif, fokus dan scroll. Klik yang membuka tab baru, rute publik, dan akses langsung memakai perilaku browser. Browser tanpa JavaScript mendapatkan kerangka anonim dan petunjuk untuk mengaktifkan JavaScript.

Alternatif yang dipertimbangkan adalah navigasi dokumen penuh dengan loading lokal dan rewrite SPA. Navigasi dokumen penuh tetap membuang sidebar dan topbar pada setiap klik. SPA akan mengganti tumpukan yang sudah melayani delapan rute dan memerlukan pemindahan kontrak UI yang di luar cakupan UI-0.

Cold entry memvalidasi identitas lewat `GET /api/auth/me`. Konten dan pencarian bersifat inert sampai respons berhasil. Request halaman memakai token yang dipasangkan dengan lifecycle saat pemasangan. API tetap memberlakukan Sanctum dan ownership. Saat token hilang, tidak sah, diganti di tab lain, atau logout berhasil, aplikasi menyembunyikan shell lama, melepas halaman dan menghapus DOM privat sebelum transisi. Pemulihan dokumen dari cache history memvalidasi ulang. 403 dan gangguan jaringan dapat ditangani di bagian terkait.

Setiap pemasangan halaman memiliki `AbortSignal`. Pergantian rute membatalkan pekerjaan baca, melepas listener lintas dokumen, menutup dialog, menghentikan autosave dan debounce yang dimiliki halaman, serta menghancurkan chart. Pergantian picker juga memakai pembatalan atau nomor request untuk mengabaikan respons lama. Perubahan kuesioner dan Draft yang belum tersimpan tetap diperingatkan saat menavigasi. Retry UI memakai request baca yang terkait. Operasi tulis tetap mengikuti alur penyimpanan yang sudah ada.

## File utama

| Area | File |
| --- | --- |
| Shell dan autentikasi | `resources/js/{app,workspace,api,auth}.js`, `resources/views/layouts/app.blade.php`, `resources/views/components/navigation.blade.php`, `resources/css/app.css` |
| Komponen dan lifecycle | `resources/js/{charts,summary,umkm-picker,ui}.js`, delapan modul halaman di `resources/js/pages/`, template Blade dan skeleton terkait |
| Regresi browser | `tests/js/workspace-browser.mjs`, `tests/js/six-factor-browser.mjs` |

[Inventaris rute](route-inventory.md)

## Bukti

| Skenario | Artefak |
| --- | --- |
| Baseline, Home ke UMKM dengan validasi sesi tertunda | [Spinner sebelum perubahan](before-session-spinner.png) |
| Desktop 1440×900 | [KPI tertunda dan grafik telah siap](desktop-independent-skeleton.png), [kuesioner tertunda dan picker siap](desktop-form-skeleton.png), [error grafik dan retry](desktop-chart-error.png), [Penilaian Enam Faktor](after-workspace-six-factor.png), [video perpindahan rute](desktop-navigation.webm) |
| Tablet 768×1024 | [Skeleton tabel](768-table-skeleton.png), [tabel siap](768-table-ready.png) |
| Mobile 390×844 | [Skeleton tabel](390-table-skeleton.png), [tabel siap](390-table-ready.png) |
| Gagal validasi sesi pada cold entry | [Pesan dan aksi pemulihan](session-error.png) |
| Request dan waktu menuju tampilan judul | [Pengukuran browser](navigation-measurements.json) |

Home ke UMKM pada baseline memicu satu navigasi dokumen dan satu request `/api/auth/me`. Sesudah perubahan, masing-masing menjadi nol untuk klik internal yang sama. Screenshot baseline menahan respons auth selama 1.100 ms agar spinner tertangkap. Durasi menuju heading pada JSON berasal dari Playwright lokal dan mencakup biaya penggerak interaksi. Kondisi perlambatan dan kegagalan jaringan disimulasikan melalui intersepsi Playwright. Login, data bisnis, logout dan token revocation menggunakan API dan MySQL lokal. Bukti memakai akun sintetis dan media visual tanpa request log, token, atau penyimpanan sesi browser.

## Menjalankan verifikasi

```powershell
npm run build
npm test
php artisan view:cache --no-interaction
php artisan route:list --except-vendor --no-interaction
php artisan test --compact --filter='(AuthApiTest|OwnershipApiTest|UmkmApiTest|UmkmSummaryApiTest|EvaluationApiTest|DashboardApiTest|SixFactorEvaluation)'
php artisan test --compact
```

Jalankan browser dan PHPUnit bergantian karena keduanya memakai MySQL `joyumkm_testing`. Server browser berjalan di loopback dengan `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=joyumkm_testing` dan `DB_URL` kosong.

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='joyumkm_testing'
$env:DB_URL=''
php artisan serve --host=127.0.0.1 --port=8129 --no-reload --no-interaction
```

Pada terminal lain, atur variabel `JOYUMKM_BROWSER_DB=joyumkm_testing` dan `JOYUMKM_PLAYWRIGHT` ke lokasi Playwright Core. Video opsional menggunakan FFmpeg dari Playwright (`playwright-core install ffmpeg`) dan Chrome lokal.

```powershell
$env:JOYUMKM_UI0_EVIDENCE='docs/evidence/ui-0'
$env:JOYUMKM_UI0_VIDEO='1'
node tests/js/workspace-browser.mjs
node tests/js/six-factor-browser.mjs
```

## Hasil regresi

| Pemeriksaan | Hasil |
| --- | --- |
| Build Vite dan kompilasi Blade | PASS. Vite tetap melaporkan peringatan ukuran chunk ApexCharts sekitar 938 kB sebelum gzip. |
| Tes JavaScript | PASS, 33 tes. |
| Browser workspace UI-0 | PASS, 13 skenario pada Chrome 158, termasuk tab baru, Back/Forward, validasi sesi dan fallback tanpa JavaScript. |
| Tes PHP terarah | PASS, 164 tes dan 1.816 assertion. Mencakup autentikasi dan expiry, ownership, CRUD UMKM, ringkasan, evaluasi, analitik dan Penilaian Enam Faktor. |
| Regresi browser Penilaian Enam Faktor | PASS, 13 skenario pada Chrome 158, run `066c36c8`. Skenario keluar kini menguji konfirmasi internal dan konfirmasi native saat reload. |
| Suite PHP penuh | FAIL, 197 dari 202 tes lulus dan 2.030 assertion. Perbandingan JUnit per kasus terhadap run P2 mendapati nol perubahan status dari 202 kasus. |

Lima kasus baseline yang kembali muncul.

| Kelas | Kasus | Jenis |
| --- | --- | --- |
| `DemoSeederTest` | `test_local_demo_uses_real_answers_and_expected_scores` | FAIL, empat akun saat tes mengharapkan satu |
| `DemoSeederTest` | `test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` | FAIL, empat akun saat tes mengharapkan satu |
| `SampleDataSeederTest` | `test_each_umkm_has_at_most_max_evaluations_evaluations` | FAIL, empat evaluasi pada profil berbatas tiga |
| `SampleDataSeederTest` | `test_invalid_seed_password_does_not_create_sample` | ERROR, argumen kasus tes kurang |
| `SampleDataSeederTest` | `test_rollback_on_failure_leaves_no_partial_data` | ERROR, pesan assertion dipakai sebagai nama koneksi |

[Baseline P2](../joyumkm-v1-p2-handoff.md)

## Matriks penerimaan

| Kriteria | Status | Bukti |
| --- | --- | --- |
| UI0-AC01 | PASS | Delapan rute workspace tercakup. Video dan screenshot menunjukkan navigasi tanpa spinner sesi layar penuh. |
| UI0-AC02 | PASS | Identitas node sidebar dan topbar tetap sama. Pengukuran mencatat nol navigasi dokumen serta nol request `/api/auth/me` saat matriks klik internal. |
| UI0-AC03 | PASS | Grafik tampil sebelum KPI selesai. Picker dapat dipakai sebelum kuesioner selesai. Skeleton tabel diuji pada 768×1024 dan 390×844 tanpa overflow dokumen. |
| UI0-AC04 | PASS | Cold entry tanpa token, logout lintas tab, kembali dari history dan revocation token diuji di browser. Expiry diuji lewat API. |
| UI0-AC05 | PASS | Putus koneksi dan timeout saat cold entry pulih lewat retry. Grafik 500 dan tabel 403 pulih secara lokal. |
| UI0-AC06 | PASS | Judul, active menu, fokus heading, skip link, Back/Forward, reload, deep link, query pencarian, Ctrl+klik, klik tengah, dan tautan tanpa JavaScript diuji. |
| UI0-AC07 | PASS | Perpindahan cepat berakhir di rute yang dipilih. Konfirmasi meninggalkan form mempertahankan edit saat dibatalkan. Satu klik notifikasi menghasilkan satu toast. Tes browser yang dipantau selesai tanpa error JavaScript tidak tertangani. |
| UI0-AC08 | PASS | Pergantian bearer lintas tab menghapus konteks akun sebelumnya. Ownership API tetap lulus. |
| UI0-AC09 | PASS | Regresi API terarah dan 13 skenario Penilaian Enam Faktor lulus. Suite PHP penuh mengulang lima kasus baseline dengan jenis dan gejala yang sama. |
| UI0-AC10 | PASS | Hasil pemeriksaan, perbandingan baseline, daftar rute, video dan screenshot tercatat dalam handoff ini. |

Gate UI-0 lulus pada checkout lokal. QA memakai Chrome dan MySQL lokal. Pengukuran latensi produksi, Firefox, Safari, dan pembaca layar langsung belum dijalankan. UI-1 hingga UI-3 tetap mengikuti cakupan desain masing-masing.
