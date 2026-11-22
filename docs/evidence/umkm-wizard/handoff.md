# Handoff Wizard Tambah UMKM

Implementasi mengikuti [PRD Tambah UMKM Wizard OpenWA](../../prd/PRD_JoyUMKM_Tambah_UMKM_Wizard_OpenWA.md). Titik masuk tetap **Tambah Data** pada halaman UMKM. Dialog memuat Informasi Usaha, Lokasi Usaha, dan Kontak Usaha. Daftar memperbarui data setelah penyimpanan dengan konteks pencarian, filter, pengurutan, dan pagination pengguna.

## Cakupan dan keputusan implementasi

- [Dialog Blade](../../../resources/views/umkms.blade.php) dan [pengendali form](../../../resources/js/umkm-form.js) memakai pola [DESIGN.md](../../../DESIGN.md) lokal. Poppins, token warna dan spasi, dialog native, select existing, fokus, feedback inline, dan target aksi 44 px diterapkan.
- [Migrasi](../../../database/migrations/2026_11_22_064805_add_business_profile_to_umkms_table.php) menambah kolom nullable untuk profil dan periode omzet beserta status dan waktu cek. Periode omzet lama tetap null. Hubungan dengan penilaian V1 dan enam faktor dipertahankan.
- [Layanan OpenWA](../../../app/Services/OpenWaNumberCheckService.php) memakai HTTPS dengan key di konfigurasi Laravel dan batas tiga detik untuk tiap lookup. Endpoint preview dibatasi 10 permintaan per menit per pengguna. Create menentukan status dari hasil lookup server saat Simpan. Nomor valid tetap dapat disimpan saat tidak terdaftar atau hasil cek tidak tersedia.
- Format WA menerima awalan `08`, `628`, atau `+628` dengan pemisah spasi atau tanda hubung. NIB menerima 13 digit, tahun berdiri menerima 1800 sampai tahun berjalan, dan alamat serta deskripsi dibatasi 2.000 karakter. PATCH yang mengirim koordinat harus mengirim pasangan latitude dan longitude.
- UI Ubah menyediakan nama, provinsi, sektor, dan omzet. Metadata baru tersedia melalui GET detail dan PATCH. Saat nomor berubah melalui PATCH, status kembali ke `unchecked` dengan waktu cek null.
- Pengerjaan dilakukan di thread utama dengan Superpowers, Ponytail, Laravel Best Practices, Testing Best Practices, Tailwind CSS Development, Impeccable, dan Humanizer.

## Bukti verifikasi

MySQL 8.4.3 memakai skema khusus `joyumkm_testing`. Chrome 158.0.8127.36 menjalankan browser QA di loopback dengan fixture sintetis. Browser memakai server Laravel dan database uji untuk Create, Edit, dan pembacaan data. Respons preview terdaftar, tidak terdaftar, dan throttle disimulasikan di browser. PHPUnit memakai `Http::fake()` untuk respons OpenWA.

| Pemeriksaan | Hasil |
| --- | --- |
| PHPUnit UMKM, migrasi, evaluasi V1, dan enam faktor | PASS, 97 tes dan 1.341 assertion. |
| Suite PHPUnit penuh | FAIL, 227 dari 232 tes lulus dengan 2.336 assertion. Tiga failure dan dua error seeder cocok dengan [baseline UI-3](../ui-3/handoff.md#regresi-php-sebelum-dan-sesudah). |
| `npm test` | PASS, 37 tes. |
| `npm run build` | PASS. Peringatan ukuran chunk ApexCharts sudah ada pada build awal. |
| `php vendor/bin/pint --dirty --format agent` dan `git diff --check` | PASS. |
| `node tests/js/umkm-wizard-browser.mjs` | PASS pada 320×720, 390×844, 768×1024, dan 1440×900. |
| `node tests/js/workspace-browser.mjs` | FAIL pada selector desktop lama. Runner mengharapkan nama UMKM sebagai tautan, sementara baseline HEAD `9ec1418` menampilkannya sebagai teks. Alur daftar, detail, edit, dan navigasi parsial diperiksa oleh runner wizard. |
| OpenWA langsung, cPanel, Firefox, Safari, pembaca layar, dan perangkat fisik | NOT RUN. |

Runner wizard memakai server loopback pada port 8134 dengan `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=joyumkm_testing`, `DB_URL` kosong, dan `OPENWA_ENABLED=false`. Set `JOYUMKM_BROWSER_DB=joyumkm_testing` dan arahkan `JOYUMKM_PLAYWRIGHT` ke instalasi lokal Playwright Core. Set `JOYUMKM_WIZARD_EVIDENCE` ke lokasi screenshot jika diperlukan. Jalankan PHPUnit dan browser secara bergantian.

### Acceptance criteria

| ID | Hasil | Bukti |
| --- | --- | --- |
| UMKM-AC01 | PASS | Wizard dibuka dari Tambah Data tanpa perubahan URL. |
| UMKM-AC02 | PASS | Tahap A/B/C, validasi per tahap, dan isian tetap tersedia saat Kembali. |
| UMKM-AC03 | PASS | API menghasilkan 422 dan UI memfokuskan error, termasuk format WA, omzet negatif, dan koordinat tanpa pasangan. |
| UMKM-AC04 | PASS | HTTP fake `exists:true` menghasilkan `registered` di preview dan Create 201. Browser menampilkan indikator terdaftar. |
| UMKM-AC05 | PASS | HTTP fake `exists:false` menghasilkan `unregistered` dan Create 201. Browser tetap bisa menyimpan setelah peringatan. |
| UMKM-AC06 | PASS | Uji 503, koneksi gagal, respons tidak valid, respons nomor lain, dan sesi putus menghasilkan `unchecked` dan Create 201. Browser menyimpan saat penyedia dinonaktifkan, preview terkena 429, atau preview masih berjalan. |
| UMKM-AC07 | PASS | Respons lama yang terlambat tidak mengganti status nomor baru. Blur berulang pada nomor yang sama tidak mengirim cek tambahan. |
| UMKM-AC08 | PASS | 422 Create diuji di Laravel. Browser menerima simulasi 422 dan kembali ke tahap Lokasi, memfokuskan kabupaten/kota, serta mempertahankan isian Kontak. |
| UMKM-AC09 | PASS | Sanctum, throttle per pengguna, Create setelah throttle, serta key server-only diperiksa. Browser dipantau terhadap request OpenWA langsung dan header key. |
| UMKM-AC10 | PASS | Upgrade dan rollback pada MySQL uji mempertahankan UMKM lama, nominal omzet, dan kedua jenis penilaian. Periode historis tetap null sesudah upgrade. |
| UMKM-AC11 | PASS | PHPUnit memeriksa PATCH parsial legacy, ownership, ringkasan, filter dan pagination UMKM, serta penilaian. Runner wizard memeriksa tabel desktop, kartu mobile, detail parsial, dan edit. |
| UMKM-AC12 | PASS | Browser memeriksa satu POST saat Simpan, perubahan yang dipertahankan saat konfirmasi batal, Escape, Forward, logout, dan pengembalian fokus. |
| UMKM-AC13 | PASS | Screenshot dan pemeriksaan ukuran dialog, footer, target 44 px, scroll konten, dan navigasi keyboard di empat viewport Chrome. |
| UMKM-AC14 | PASS | Tes JS, tes PHP terarah, build, Pint, dan diff selesai. Lima kegagalan suite penuh cocok dengan baseline seeder. |

### Screenshot

- Desktop 1440 px. [Sebelum](screenshots/before-1440.png) · [Informasi](screenshots/after-1440-A.png) · [Lokasi](screenshots/after-1440-B.png) · [Kontak](screenshots/after-1440-C.png) · [Cek berjalan](screenshots/after-1440-pending.png)
- Mobile 390 px. [Sebelum](screenshots/before-390.png) · [Informasi](screenshots/after-390-A.png) · [Lokasi](screenshots/after-390-B.png) · [Kontak](screenshots/after-390-C.png)
- Mobile 320 px. [Informasi](screenshots/after-320-A.png) · [Kontak](screenshots/after-320-C.png)
- Tablet 768 px. [Informasi](screenshots/after-768-A.png) · [Kontak](screenshots/after-768-C.png)

## Aktivasi di lingkungan Laravel

1. Jalankan migrasi pada database lingkungan tujuan dan publikasikan aset hasil `npm run build`.
2. Untuk cek langsung, aktifkan `OPENWA_ENABLED` dan isi URL, session ID, serta key melalui konfigurasi server. Nama variabel tersedia di [`.env.example`](../../../.env.example). Perbarui cache konfigurasi sesuai proses rilis.
3. Jika sesi OpenWA di shared hosting terputus, hasil cek menjadi `unchecked`. Pengguna tetap dapat menyimpan data yang valid.
