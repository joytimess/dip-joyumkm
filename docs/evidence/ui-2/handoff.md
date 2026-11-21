# JoyUMKM UI-2 — Workspace dan Navigasi Responsif

**Verdict lokal: PASS.** Seluruh 12 kriteria UI-2 lulus pada matriks wajib. Lima kegagalan seeder cocok dengan baseline per kasus.

Implementasi mengikuti PRD UI-2 dan checkout lokal yang sudah memuat UI-0/UI-1. Sidebar dan topbar tetap dipasang sekali. Tautan workspace memakai navigasi parsial Blade, URL langsung, serta perilaku tab baru bawaan browser. List dan mutasi tetap memakai endpoint dan aturan ownership yang sudah ada.

## Pemetaan navigasi

| Grup | Menu | Tujuan |
| --- | --- | --- |
| Beranda | Beranda | `/home` |
| Manajemen | Data UMKM | `/umkms` |
| Penilaian | Kuesioner Tiga Dimensi | `/form-data`, dengan pemilih UMKM |
| Penilaian | Penilaian Enam Faktor | `/umkms?mode=six-factor`, lalu pilih nama usaha untuk membuka `/umkms/{id}/six-factor-evaluations` |
| Analitik | Profil Faktor | `/factor-profile` |
| Analitik | Distribusi Kesehatan | `/distribution` |
| Analitik | Perbandingan UMKM | `/comparison` |
| Utilitas | Keluar | Aksi logout Sanctum yang sudah ada |

Menu saat ini ditandai dengan `aria-current="page"`. Data UMKM pada halaman detail dan Penilaian Enam Faktor pada halaman evaluasi per usaha memakai `aria-current="location"` untuk menunjukkan konteks menu. Pilihan Enam Faktor memanfaatkan daftar UMKM dan parameter tampilan `mode`, sehingga 34 rute aplikasi tetap berlaku. Kontrol placeholder Notifikasi, Profil Saya, dan Pusat Bantuan dihilangkan dari shell.

Breadcrumb menghubungkan Data UMKM, detail, kuesioner, Penilaian Enam Faktor, dan Profil Faktor sesuai konteks. Halaman kontekstual membawa query daftar dalam parameter `list`, termasuk pencarian, filter, sort, halaman, dan ukuran halaman. Nama usaha berasal dari respons milik pengguna aktif. Guard perubahan kuesioner dan penyimpanan Enam Faktor tetap menangani tautan breadcrumb.

## Perilaku responsif

| Ukuran | Navigasi | Pencarian | Data UMKM |
| --- | --- | --- | --- |
| 1440×900 | Sidebar tetap | Inline, debounce 300 ms | Tabel dan filter inline |
| 768×1024 | Drawer | Dialog, kirim dengan Enter atau Cari | Kartu, sortir, dan filter bottom sheet |
| 390×844 | Drawer | Dialog, kirim dengan Enter atau Cari | Kartu, sortir, dan filter bottom sheet |
| 1050 / 1051 px | Drawer / sidebar tetap | Dialog / inline | Kartu / tabel |

Breakpoint 1050 px mengikuti transisi sidebar yang sudah ada. Pencarian dan filter masing-masing memakai satu formulir yang dipindahkan antara slot inline dan dialog. Search yang dikirim dari daftar langsung memakai state daftar yang aktif. Filter menunggu Terapkan, dengan jumlah filter aktif di tombol. Reset menghapus sektor, provinsi, rekomendasi, dan status serta kembali ke halaman pertama sambil menjaga pencarian dan urutan. Mutasi yang mengosongkan halaman terakhir mengoreksi posisi pagination dengan mengganti entri history tersebut.

Dua presentasi daftar berbagi satu fetch, hasil, status permintaan, dan aksi. Perubahan lebar tidak memicu fetch ulang. Dialog mengelola fokus masuk dan kembali, Escape, backdrop, dan scroll lock. Pada layar kecil, breadcrumb hierarki tiga tingkat menampilkan tautan induk terdekat dan konteks halaman.

## Jejak implementasi

| Area | File |
| --- | --- |
| Shell, warna, dan navigasi | `resources/views/layouts/app.blade.php`, `resources/views/components/navigation.blade.php`, `resources/css/{dashboard,tokens}.css`, `resources/js/{app,workspace}.js` |
| Daftar, bottom sheet, dan pagination | `resources/views/umkms.blade.php`, `resources/js/pages/umkms.js` |
| Breadcrumb dan konteks | `resources/views/components/umkm-breadcrumb.blade.php`, `resources/views/{umkm-detail,six-factor-evaluation,form-data,factor-profile}.blade.php`, `resources/js/umkm-navigation.js`, `resources/js/pages/{umkm-detail,form-data,factor-profile}.js` |
| Regresi browser | `tests/js/{ui-navigation-browser,workspace-browser,six-factor-browser,ui-foundation-browser}.mjs` |

## Bukti visual

Baseline diambil dari checkout lokal sebelum perubahan, memakai fixture QA UI-1. Hasil memakai fixture QA UI-2. Nama usaha dan isi portofolio berbeda karena fixture kedua run berbeda.

| Skenario | Sebelum | Sesudah |
| --- | --- | --- |
| 1440 px | [Workspace](screenshots/before/1440-home.png) · [Tabel](screenshots/before/1440-umkm-table.png) | [Sidebar dan tabel](screenshots/after/1440-workspace.png) · [Konteks Enam Faktor](screenshots/after/1440-six-factor-context.png) |
| 768 px | [Workspace](screenshots/before/768-home.png) · [Tabel](screenshots/before/768-umkm-table.png) | [Kartu](screenshots/after/768-workspace.png) · [Drawer](screenshots/after/768-drawer.png) |
| 390 px | [Workspace](screenshots/before/390-home.png) · [Tabel](screenshots/before/390-umkm-table.png) | [Kartu dan nama panjang](screenshots/after/390-cards-long-name.png) · [Drawer](screenshots/after/390-drawer.png) · [Pencarian](screenshots/after/390-search.png) · [Filter](screenshots/after/390-filter-sheet.png) |
| Status daftar | | [Loading](screenshots/after/390-card-skeleton.png) · [Kosong](screenshots/after/390-card-empty.png) · [Error](screenshots/after/390-card-error.png) |
| Konteks dan aksi | | [Breadcrumb](screenshots/after/390-breadcrumb.png) · [Konfirmasi hapus](screenshots/after/390-delete-long-name.png) |
| Batas lebar | | [320](screenshots/after/320-compact.png) · [1050](screenshots/after/1050-workspace.png) · [1051](screenshots/after/1051-workspace.png) |

Pengujian browser menggunakan Chrome 158.0.8127.36 dan Playwright, dengan simulasi sentuh serta keyboard. Aplikasi QA berjalan di loopback memakai konfigurasi MySQL `joyumkm_testing` yang diperiksa melalui Artisan sebelum fixture dibuat. Browser dan PHPUnit berjalan bergantian.

## Perintah verifikasi

- `npm run build`, `php artisan view:cache --no-interaction`, `php artisan route:list --except-vendor --no-interaction`, dan `npm test`.
- Jalankan `tests/js/ui-navigation-browser.mjs`, `tests/js/ui-foundation-browser.mjs`, `tests/js/workspace-browser.mjs`, dan `tests/js/six-factor-browser.mjs` dengan Node, satu per satu. Pakai server QA loopback pada port 8129, `JOYUMKM_BROWSER_DB=joyumkm_testing`, dan `JOYUMKM_PLAYWRIGHT` berisi path absolut modul Playwright Core. Set `JOYUMKM_UI1_ORIGIN=http://127.0.0.1:8129` untuk runner UI-1.
- `php artisan test --compact --filter='(AuthApiTest|OwnershipApiTest|UmkmApiTest|UmkmSummaryApiTest|EvaluationApiTest|DashboardApiTest|SixFactorEvaluation)'` untuk regresi API, ownership, dan penilaian.
- `php artisan test --compact --log-junit <file>` untuk perbandingan per kasus sebelum dan sesudah.

| Pemeriksaan | Hasil |
| --- | --- |
| Build Vite, kompilasi Blade, dan daftar rute | PASS. 34 rute. Chunk ApexCharts tetap sekitar 938 kB sebelum gzip. |
| Tes JS | PASS, 33 dari 33. |
| Workspace UI-0 | PASS, 13 skenario. Perpindahan internal awal mencatat 0 reload dokumen dan 0 permintaan tambahan `/api/auth/me`. |
| Penilaian Enam Faktor | PASS, 13 skenario. Termasuk autosave, Draft/Final/Batal, histori, dan guard navigasi. |
| Fondasi UI-1 | PASS pada 1440×900, 768×1024, 390×844, serta fallback font dan simulasi sentuh. |
| Browser UI-2 | PASS, 6 skenario. Mencakup drawer, pencarian, filter, breadcrumb, transisi breakpoint, race permintaan, error/retry, dan penghapusan fixture. |
| PHP terarah | PASS, 164 tes dengan 1.816 assertion. |
| PHP lengkap | FAIL pada lima kasus seeder baseline. Perbandingan JUnit per kasus PASS. |
| Firefox, Safari, perangkat fisik, dan pembaca layar nyata | NOT RUN. |

## Perbandingan baseline PHP

Suite lengkap sebelum dan sesudah UI-2 menghasilkan 197 dari 202 tes lulus dan 2.030 assertion. Perbandingan JUnit mencakup identitas kelas dan metode, status, dan pesan awal kegagalan untuk seluruh 202 kasus. Hasil per kasus identik.

| Kasus | Status sebelum dan sesudah |
| --- | --- |
| `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores` | FAIL, 4 akun dibanding 1 yang diharapkan |
| `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` | FAIL, 4 akun dibanding 1 yang diharapkan |
| `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` | FAIL, 4 evaluasi dibanding batas 3 pada profil small |
| `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` | ERROR, argumen tes kurang |
| `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` | ERROR, pesan assertion dipakai sebagai nama koneksi |

## Bukti per kriteria

| Gate | Bukti |
| --- | --- |
| AC01 | Warna sidebar dan item aktif terukur, kontras teks melalui perhitungan CSS, Poppins dan ikon sprite lokal. |
| AC02 | Pemetaan rute di atas, status aktif pada drawer dan desktop, navigasi langsung ke Enam Faktor dari pemilih. |
| AC03 | Drawer diuji lewat keyboard, sentuh, backdrop, Escape, penutupan eksplisit, dan resize saat terbuka. |
| AC04 | Pencarian Unicode dengan spasi dan ampersand, submit, clear, debounce, fokus, Back/Forward, dan reload. |
| AC05 | Breadcrumb memulihkan pencarian dan filter. Regresi workspace dan Enam Faktor menguji guard perubahan yang belum tersimpan. |
| AC06 | Tabel desktop, nama panjang, sort, pagination, dan aksi three-dots. |
| AC07 | Kartu di 768 dan 390, nama panjang, skor belum tersedia, skeleton, kosong, error dan retry. |
| AC08 | Penghitung fetch saat berpindah presentasi, permintaan pencarian yang ditahan, mutasi nyata pada fixture, dan koreksi halaman terakhir. |
| AC09 | Bottom sheet dengan dua filter, jumlah aktif, Escape tanpa simpan, Reset yang mempertahankan pencarian dan sort, dan penutupan saat resize. |
| AC10 | Fokus buka/kembali, Escape, ukuran target sentuh, kontras minimal 4,5:1, dan tanpa overflow dokumen di 320, 390, 768, 1050, 1051, dan 1440 px. |
| AC11 | Tabel hasil pengujian dan perbandingan JUnit. |
| AC12 | Pemetaan rute, matriks responsif, daftar file, dan screenshot sebelum/sesudah di handoff ini. |
