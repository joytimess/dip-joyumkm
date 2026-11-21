# Laporan verifikasi JoyUMKM UI-1

Pengujian dilakukan pada Windows dengan Chrome 158.0.8127.36, Node 22, PHP 8.3, build produksi Vite dan MySQL lokal `joyumkm_testing`. Server QA di `127.0.0.1:8131` dijalankan dengan `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=joyumkm_testing`, dan `DB_URL` kosong. Konfigurasi efektif database diperiksa sebelum membuat fixture. Pengujian browser dan PHPUnit berjalan bergantian.

[Audit token, font, SVG](token-icon-font-audit.md) · [Perbandingan visual](visual-comparison.md)

## Perintah dan hasil

| Pemeriksaan | Perintah | Hasil |
| --- | --- | --- |
| Build aset | `npm run build` | PASS. Vite tetap memberi peringatan chunk ApexCharts sekitar 938 kB sebelum gzip. |
| Kompilasi Blade | `php artisan view:cache --no-interaction` | PASS |
| Rute | `php artisan route:list --except-vendor --no-interaction` | PASS, 34 rute |
| Unit JS | `npm test` | PASS, 33 tes |
| UI-1 browser | `node tests/js/ui-foundation-browser.mjs` | PASS. 1440×900, 768×1024, 390×844, serta sesi emulasi sentuh dan fallback font terpisah. |
| Workspace UI-0 | `tests/js/workspace-browser.mjs` | PASS, 13 skenario |
| Penilaian Enam Faktor | `tests/js/six-factor-browser.mjs` | PASS, 13 skenario, run `b96dcaad` |
| PHP terarah | `php artisan test --compact --filter='(AuthApiTest\|OwnershipApiTest\|UmkmApiTest\|UmkmSummaryApiTest\|EvaluationApiTest\|DashboardApiTest\|SixFactorEvaluation)'` | PASS, 164 tes, 1.816 assertion |
| PHP lengkap | `php artisan test --compact --log-junit <file>` | FAIL pada kedua run. 197/202 lulus dan 2.030 assertion. Seluruh 202 kasus JUnit cocok dan nol status kasus berubah. |
| Whitespace diff | `git diff --check` | PASS, peringatan normalisasi CRLF Windows pada file yang sudah memiliki perubahan UI-0 |

Untuk capture, set `JOYUMKM_BROWSER_DB=joyumkm_testing`, `JOYUMKM_PLAYWRIGHT` ke path absolut `playwright-core`, dan `JOYUMKM_UI1_EVIDENCE=docs/evidence/ui-1`. Mode `--baseline` dipakai sebelum implementasi. Kedua runner lama dijalankan dengan substitusi origin ke port QA 8131 saat Node memuat modul. Sumber runner lama tetap sama.

### Lima kegagalan baseline PHP

| Tes | Baseline dan sesudah |
| --- | --- |
| `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores` | FAIL, 4 akun saat tes mengharapkan 1 |
| `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` | FAIL, 4 akun saat tes mengharapkan 1 |
| `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` | FAIL, 4 evaluasi untuk profil berbatas 3 |
| `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` | ERROR, argumen tes kurang |
| `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` | ERROR, pesan assertion dipakai sebagai nama koneksi |

## Perubahan file UI-1

| Area | File |
| --- | --- |
| Font dan fondasi CSS | `package.json`, `package-lock.json`, `resources/css/{tokens,app,public,dashboard}.css` |
| SVG dan entry point Blade | `resources/views/components/{icon,icon-sprite}.blade.php`, penghapusan `public-icons.blade.php`, `resources/views/layouts/{app,public}.blade.php` |
| Penerapan gaya dan ikon | `resources/views/{login,register,home,form-data,umkms}.blade.php`, `resources/js/{charts,summary,ui}.js`, `resources/js/pages/{comparison,distribution,factor-profile,home,umkms}.js` |
| Regresi dan handoff | `tests/js/ui-foundation-browser.mjs`, `docs/evidence/ui-1/` |

## Acceptance criteria

| Gate | Status | Bukti |
| --- | --- | --- |
| UI1-AC01 | PASS | Nilai brand persis `#0207CA`, `#0297DE`, `#FC734D` pada token. |
| UI1-AC02 | PASS | Poppins termuat dan terukur di publik, autentikasi, workspace, form, tabel, menu, label dan tooltip grafik. Login 390 px tetap muat saat request font sengaja diblokir. |
| UI1-AC03 | PASS | Screenshot tiga ukuran menampilkan hierarki tipografi. Range DOM nama UMKM panjang berada di dalam sel dan nama lengkap terbaca di dialog. |
| UI1-AC04 | PASS | Kontrol dan ikon dekoratif workspace netral. Status tetap berlabel dan seri grafik mempertahankan warna data. |
| UI1-AC05 | PASS | Permukaan putih, baris normal transparan, hover/fokus netral, ikon 3, Hapus merah. Enter, Escape, klik luar, tombol Ubah, dan buka/batal konfirmasi hapus diuji. |
| UI1-AC06 | PASS | Satu sprite lokal. Pemeriksaan referensi SVG gagal bila simbol hilang. Nama aksesibel diperiksa lewat kontrol tab, mata sandi, notifikasi, menu dan toast yang diuji. |
| UI1-AC07 | PASS | Primitive memakai token bersama. Browser menguji form default, fokus, invalid, PATCH tertunda, simpan normal, toast dan penutupnya, serta dialog konfirmasi dan preview. |
| UI1-AC08 | PASS | Tidak ada overflow dokumen di ketiga viewport pada rute dan state yang diukur. Fokus terlihat, target pemicu 44 px pada sentuhan, reduced motion aktif, tujuh pasangan teks dan permukaan inti lulus 4,5:1. Lihat [smoke aksesibilitas](accessibility-smoke.md). |
| UI1-AC09 | PASS | Token, pemetaan legacy, strategi SVG dan lisensi Poppins tercatat di audit. Vite membundel aset font secara lokal. |
| UI1-AC10 | PASS | 13 skenario workspace lulus. Klik Home ke UMKM mencatat 0 request dokumen dan 0 validasi sesi tambahan. Mount, history, sesi, loading lokal, abort dan retry tercakup. |
| UI1-AC11 | PASS | Build, JS, browser dan PHP terarah lulus. Suite lengkap memiliki lima kegagalan baseline dengan status per kasus yang sama. |
| UI1-AC12 | PASS | Handoff, 57 screenshot baseline, 73 screenshot akhir, pengukuran dan hasil tes tersedia di direktori ini. |

## Batas fase

UI-2 mencakup sidebar navy, navigasi responsif, dan card list UMKM mobile. UI-3 mencakup refinement layout per halaman. Keduanya belum dikerjakan. Pemeriksaan lokal memakai Chrome. Firefox, Safari, pembaca layar fisik, dan pengisian oleh password manager belum diuji.
