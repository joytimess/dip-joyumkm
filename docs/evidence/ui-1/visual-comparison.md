# Bukti visual UI-1 JoyUMKM

Pengambilan gambar menggunakan Chrome 158 dengan build produksi Vite, API Laravel dan MySQL lokal `joyumkm_testing`. Fixture menggunakan nama sintetis dan evaluasi yang dibuat lewat API. Nama panjang pada tabel dan dialog sama pada kedua pengambilan gambar. Artefak berisi tangkapan layar dan pengukuran visual.

## Sebelum dan sesudah

File dengan nama yang sama di [baseline](baseline/) dan [final](final/) menunjukkan viewport dan bagian yang sama. Nama berawalan `1440-`, `768-`, dan `390-` masing-masing menunjukkan desktop 1440×900, tablet 768×1024, dan mobile 390×844.

| Bagian | Baseline | UI-1 |
| --- | --- | --- |
| Landing, login, register | [Landing desktop](baseline/1440-landing.png), [login mobile](baseline/390-login.png), [register tablet](baseline/768-register.png) | [Landing desktop](final/1440-landing.png), [login mobile](final/390-login.png), [register tablet](final/768-register.png) |
| Home dan KPI | [Desktop](baseline/1440-home.png), [mobile](baseline/390-home.png) | [Desktop](final/1440-home.png), [mobile](final/390-home.png) |
| Tabel dan menu UMKM | [Fokus desktop](baseline/1440-umkm-menu-focus.png), [hover mobile](baseline/390-umkm-menu-hover.png) | [Fokus desktop](final/1440-umkm-menu-focus.png), [hover mobile](final/390-umkm-menu-hover.png) |
| Form dan dialog | [Invalid mobile](baseline/390-umkm-form-invalid.png), [hapus nama panjang](baseline/390-umkm-delete-long-name.png), [dialog kuesioner](baseline/390-questionnaire-dialog.png) | [Invalid mobile](final/390-umkm-form-invalid.png), [hapus nama panjang](final/390-umkm-delete-long-name.png), [dialog kuesioner](final/390-questionnaire-dialog.png) |
| Analitik | [Profil Faktor](baseline/1440-factor-profile.png), [Distribusi](baseline/1440-distribution.png), [Comparison](baseline/1440-comparison.png) | [Profil Faktor](final/1440-factor-profile.png), [Distribusi](final/1440-distribution.png), [Comparison](final/1440-comparison.png) |
| Loading lokal | [Home](baseline/1440-home-skeleton.png), [tabel](baseline/768-umkm-skeleton.png), [grafik](baseline/1440-chart-skeleton.png), [picker](baseline/390-picker-skeleton.png) | [Home](final/1440-home-skeleton.png), [tabel](final/768-umkm-skeleton.png), [grafik](final/1440-chart-skeleton.png), [picker](final/390-picker-skeleton.png) |

Capture rute analitik di tabel menampilkan posisi awal saat halaman dibuka. Pada viewport sempit, grafik berada di bawah picker atau kartu ringkasan. Capture akhir setelah scroll menunjukkan grafik, label dan tooltip pada [Profil Faktor 390 px](final/390-factor-profile-tooltip.png), [Distribusi 390 px](final/390-distribution-tooltip.png), [Comparison 390 px](final/390-comparison-tooltip.png), [Profil Faktor 768 px](final/768-factor-profile-tooltip.png), [Distribusi 768 px](final/768-distribution-tooltip.png) dan [Comparison 768 px](final/768-comparison-tooltip.png).

Pengambilan akhir juga mencakup [penyimpanan tertunda](final/768-umkm-form-saving.png), [toast sukses](final/768-umkm-success-toast.png), dan [login saat aset font diblokir](final/390-login-font-fallback.png).

## Hasil inspeksi

- Teks nama panjang membungkus di kolom miliknya. Tabel tetap dapat digeser di mobile. Dialog konfirmasi menampilkan nama lengkap dan kedua tombol tetap berada dalam viewport.
- Menu aksi menampilkan permukaan putih, teks/ikon navy, fokus biru dengan latar netral, dan pemisah sebelum aksi Hapus merah. Posisi popover mengikuti ruang yang tersedia di atas atau di bawah pemicu.
- Poppins terbaca di heading, input, tabel, label dan tooltip grafik. Komposisi publik dan struktur rute workspace mengikuti desain semula.
- [Pengukuran baseline](baseline/measurements.json), [pengukuran akhir](final/measurements.json), dan [rasio kontras](final/contrast.json) menyediakan data yang dipakai dalam laporan verifikasi.
