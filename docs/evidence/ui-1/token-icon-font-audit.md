# Audit fondasi desain JoyUMKM UI-1

Baseline diambil dari checkout lokal pada HEAD `804738b0a39f9dc2658e40e92e796077d131aca6` beserta perubahan UI-0 dan P0–P2 di working tree. Snapshot browser awal menggunakan build produksi Vite dari checkout tersebut.

## Token dan pemetaan

[tokens.css](../../../resources/css/tokens.css) adalah sumber nilai visual. [app.css](../../../resources/css/app.css) mengimpor token dan menyediakan gaya komponen bersama. `public.css` dan `dashboard.css` memakai token yang sama untuk konteks halaman masing-masing.

| Area | Token dan aturan |
| --- | --- |
| Brand | `--brand-primary` `#0207CA` untuk aksi utama dan fokus, `--brand-secondary` `#0297DE` untuk visualisasi dan aksen pendukung, `--brand-accent` `#FC734D` untuk sorotan terbatas |
| Teks | `--text-primary` `#101E40` dan `--text-muted` `#566781` untuk teks sekunder. Nilai merek `#70819D` disimpan sebagai `--text-muted-brand`. Kontrasnya pada putih adalah 3,95:1, sehingga teks sekunder biasa memakai warna yang lebih gelap |
| Permukaan | `--canvas` `#F4F7FC`, `--surface` `#FFFFFF`, serta `--border`, `--border-strong`, dan permukaan hover, pressed, disabled, skeleton |
| Status | `--status-good` `#147D5B`, `--status-warn` `#9B6508`, `--status-error` `#A52135` dengan permukaan masing-masing. Label status, pesan validasi dan konfirmasi tetap berupa teks |
| Tipografi | Poppins dalam `--font-sans`, dengan token ukuran body, kontrol, caption, heading, section dan card, serta tiga tinggi baris |
| Jarak dan target | Skala 4, 8, 12, 16, 20, 24, 32, 40 px. Tombol dan field utama berbasis `--control-height` 44 px |
| Bentuk dan lapisan | Radius kecil 8 px, kontrol 12 px, card/dialog 16 px, badge pill. Card dan overlay punya elevasi sendiri |
| Fokus | Outline biru 2 px dengan offset 2 px, atau putih pada permukaan gelap. Radio yang inputnya transparan menampilkan ring pada label |

| Lama | Pemetaan |
| --- | --- |
| Public `--joy-primary/secondary/accent` dan dashboard `--brand-*` lokal | `--brand-primary/secondary/accent` dari root |
| `--joy-ink`, `--ink`, `--navy`, `--text-primary` lokal | `--text-primary` |
| `--joy-muted`, `--muted`, `--text-muted` lokal | `--text-muted` |
| `--joy-canvas`, `--ground`, `--canvas` lokal | `--canvas` |
| `--joy-surface/border/radius/shadow`, salinan literal pada panel | `--surface`, `--border`, `--card-radius`, `--card-shadow` |
| `--joy-cyan-tint`, `--joy-blue-tint` | `--soft-blue` untuk aksen pendukung, `--surface-hover` untuk kontrol netral |
| `--purple` | `--brand-primary` |
| Plus Jakarta Sans, Inter fallback publik, nama font eksplisit di ApexCharts | `--font-sans` |

Gradient branding publik dan sidebar lama tetap dipakai pada konteksnya. Navy siap dipakai dari `--text-primary` untuk desain sidebar UI-2. Warna seri tetap mengikuti representasi data yang sudah ada.

## Font dan SVG

- Vite membundel `@fontsource/poppins` 5.3.0 dari lockfile dengan bobot normal 400, 500, 600 dan 700. Fontsource memakai `font-display: swap`. Fallback berurutan adalah `ui-sans-serif`, `system-ui`, `sans-serif`. Paket menyediakan lisensi SIL OFL 1.1 di `node_modules/@fontsource/poppins/LICENSE`. Grafik membaca family dan warna teks dari token CSS.
- [Sprite Blade](../../../resources/views/components/icon-sprite.blade.php) menggabungkan glyph SVG outline lokal dari komponen JoyUMKM, termasuk ikon publik, ikon Blade, dan ikon metrik yang sebelumnya disalin ke dua renderer JS. Aplikasi mencantumkan MIT pada [composer.json](../../../composer.json). Glyph yang dipakai berasal dari checkout ini, tanpa mengimpor aset Material Symbols atau pustaka ikon lain.
- Kedua layout memuat sprite yang sama sekali per dokumen. Blade memakai `x-icon` dan renderer JS memakai `icon()` untuk mereferensikan simbol dengan nama yang sudah disediakan aplikasi. Kontrol, ikon penutup toast, mata sandi, dan ikon menu mewarisi `currentColor`. SVG dekoratif disembunyikan dari pembaca layar dan tombol ikon tetap diberi accessible name. Ilustrasi landing dan visual SVG yang dihasilkan ApexCharts tetap menggunakan aset dan mekanisme masing-masing.
- Menu UMKM memakai popover native dengan ikon outline, tiga aksi, dan divider sebelum Hapus. Target baris 44 px. Pemicu pada tabel desktop tetap ringkas agar lebar kolom aksi terjaga, dengan target minimal 44 px ketika pointer kasar.
- Badge kategori dan ikon dekoratif di workspace memakai permukaan netral. Aksen visualisasi data, status berlabel, dan aksi destruktif mempertahankan perannya.
