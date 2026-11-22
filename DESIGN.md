# DESIGN.md — JoyUMKM

Acuan dasar desain visual dan komponen JoyUMKM. Dokumen ini menggambarkan kondisi kode saat ini (`resources/css`, `resources/views`, `resources/js`) dan mengacu ke PRD UI-1 (`docs/prd/PRD_JoyUMKM_V1_UI-1_Design_System_Foundation.md`) serta audit token (`docs/evidence/ui-1/token-icon-font-audit.md`).

Jika ada perbedaan antara dokumen ini dan kode, **kode yang berlaku**. Perbaiki dokumen ini ketika token atau komponen berubah.

---

## 1. Prinsip

1. **Neutral-first.** Permukaan putih dan canvas terang, teks navy, garis tipis. Warna brand adalah aksen bermakna, bukan cat dekoratif.
2. **Hierarki sebelum dekorasi.** Ukuran, bobot, spasi, dan state lebih penting daripada gradient atau bayangan.
3. **Warna semantik hanya untuk makna.** Hijau, amber, dan merah dipakai untuk status, peringatan, dan aksi destruktif. Jangan dipakai sebagai hiasan.
4. **Status tidak hanya lewat warna.** Selalu sertakan teks atau ikon.
5. **Satu sistem ikon.** Gaya outline SVG dari sprite lokal, tanpa emoji atau icon font.
6. **Target sentuh minimal 44 px** untuk aksi utama. Kontrol padat di tabel boleh lebih kecil bila ada justifikasi.
7. **Aksesibel secara default.** Focus terlihat, kontras teks minimal WCAG AA, dan `prefers-reduced-motion` dihormati.

---

## 2. Dua konteks halaman

| Aspek | Publik (non-auth) | Authenticated (workspace) |
|---|---|---|
| Layout Blade | `resources/views/layouts/public.blade.php` (`body.public-body`) | `resources/views/layouts/app.blade.php` (`body[data-protected]`) |
| Halaman | Landing (`welcome`), Login, Register | Beranda, UMKM, Form Data, Enam Faktor, Factor Profile, Distribusi, Comparison, Detail UMKM |
| CSS utama | `public.css` (selector `.public-body …`) | `dashboard.css` (dibungkus `body[data-protected] { … }`) |
| Sifat | Ekspresif: gradient, ilustrasi, kartu editorial | Operasional: data-first, padat, netral |
| Lebar konten | `.container` maks. 1200 px | Sidebar 250 px + `.main-content` dengan padding 32/34 px |

Kedua konteks memakai token yang sama dari `resources/css/tokens.css` dan kelas dasar `.btn`, `.field`, `.card`, `.panel`, `.modal`, `.toast` dari `resources/css/app.css`.

---

## 3. Warna

### 3.1 Palet inti (token di `resources/css/tokens.css`)

| Peran | Hex | Token | Dipakai untuk |
|---|---|---|---|
| Primary | `#0207CA` | `--brand-primary` | Tombol utama, item sidebar aktif, fokus, tautan penting, fill chart utama |
| Primary hover | `#0006B0` | `--primary-hover` | Hover/pressed tombol primary |
| Secondary | `#0297DE` | `--brand-secondary` | Grafik kedua, progress pendukung, gradient, kicker landing |
| Accent | `#FC734D` | `--brand-accent` | Sorotan terbatas: titik eyebrow, garis bawah judul hero, `.btn-accent`, segmen kekuatan password |
| Teks utama / navy | `#101E40` | `--text-primary` | Heading, body, panel "instrument", navy sidebar |
| Teks sekunder | `#566781` | `--text-muted` | Metadata, label pendukung, body sekunder |
| Teks sekunder brand | `#70819D` | `--text-muted-brand` | Hanya teks tidak esensial dan besar (lihat catatan kontras) |
| Canvas | `#F4F7FC` | `--canvas` | Latar belakang workspace dan beberapa section publik |
| Surface | `#FFFFFF` | `--surface` | Card, input, menu, dialog, toast |
| Border | `#E8EDF6` | `--border` | Garis pemisah, border card |
| Border kuat | `#CBD4E2` | `--border-strong` | Border input, border dashed kosong |
| Hover neutral | `#EEF1F5` | `--surface-hover` | Hover tombol ghost/outline, hover menu |
| Pressed neutral | `#E3E8EF` | `--surface-pressed` | Pressed |
| Disabled surface | `#F3F5F8` | `--surface-disabled` | Input disabled dan readonly |
| Skeleton | `#E8ECF2` | `--skeleton-fill` | Placeholder loading |
| Soft blue | `#EAF6FF` | `--soft-blue` | Tint biru ringan |

### 3.2 Semantik status

| Status | Teks / ikon | Surface | Dipakai untuk |
|---|---|---|---|
| Good | `#147D5B` (`--status-good`) | `#E8F7F0` (`--status-good-surface`) | Final, selesai, skor baik |
| Warn | `#9B6508` (`--status-warn`) | `#FFF6E0` (`--status-warn-surface`) | Draft, belum disimpan, peringatan |
| Error | `#A52135` (`--status-error`) | `#FFF1F2` (`--status-error-surface`) | Validasi, error, aksi destruktif |
| Error hover | `#831A2B` (`--status-error-hover`) | — | Hover tombol destruktif |

Catatan: `#A52135` tidak tercantum di acuan palet yang ditempel sebelumnya, tetapi tersedia di `tokens.css`.

### 3.3 Warna fokus dan overlay

- Focus ring: `--focus-ring` = `--brand-primary` (outline 2 px, offset 2 px). Di dalam sidebar, drawer, dan CTA gelap memakai `--focus-inverse` (putih).
- Overlay dialog: `rgb(16 30 64 / .47)` (`--overlay`).
- Shadow: `--card-shadow` `0 2px 8px rgb(16 30 64 / .04)` untuk card; `--overlay-shadow` `0 12px 32px rgb(16 30 64 / .15)` untuk dialog, menu, dan toast.

### 3.4 Dominasi warna per halaman (berdasarkan kode, bukan pengukuran piksel)

**Authenticated**
- Dominan: putih (`#FFFFFF`) untuk card dan `#F4F7FC` untuk canvas.
- Biru primary (`#0207CA`) pada sidebar aktif, tombol utama, dan avatar. Sidebar dirender sebagai `--sidebar-surface` (`#101E40`, navy) karena aturan di `dashboard.css:115–119` mengalahkan gradient di `app.css:103`.
- Teks navy dan abu-abu kebiruan (`#566781`) untuk data.
- Biru sekunder dan aksen oranye muncul pada chart dan sorotan data, bukan pada permukaan.
- Warna status hanya pada badge, pill, dan teks peringatan.

**Publik**
- Latar putih (`#FFFFFF`), hero dengan gradient putih ke `#F1F6FF`, dan section explainer `#F4F7FC`. Section workflow memakai `#F6F8FE`.
- Gradient brand penuh pada panel auth kiri (`#0207CA` → `#0A22D1` → `#0297DE`), CTA banner (`#0207CA` → `#2641E0` → `#0297DE`), dan kartu editorial (tone peach, blue, royal).
- Panel "instrument" memakai navy solid `#101E40`.
- Kicker memakai `#0297DE`; eyebrow memakai latar `#F0F3FF` dengan border `#DDE5FF`.

Tidak ada persentase rasio warna yang ditetapkan. Acuannya adalah penggunaan yang selektif.

### 3.5 Kontras (dihitung manual dengan rumus WCAG; verifikasi ulang dengan alat bila diperlukan)

| Pasangan | Rasio | Hasil untuk teks normal |
|---|---|---|
| `#101E40` di `#FFFFFF` | ≈ 16:1 | Lulus |
| `#0207CA` di `#FFFFFF` | ≈ 11:1 | Lulus |
| `#566781` di `#FFFFFF` | ≈ 5,7:1 | Lulus AA |
| `#566781` di `#F4F7FC` | ≈ 5,3:1 | Lulus AA |
| `#147D5B` di `#FFFFFF` | ≈ 5,1:1 | Lulus AA |
| `#9B6508` di `#FFFFFF` | ≈ 4,9:1 | Lulus AA |
| `#A52135` di `#FFFFFF` | ≈ 7,3:1 | Lulus AA |
| `#70819D` di `#FFFFFF` | ≈ 3,95:1 | **Gagal** untuk teks kecil; hanya teks besar atau non-esensial (sesuai audit) |
| `#0297DE` di `#FFFFFF` | ≈ 3,2:1 | **Gagal** untuk teks kecil; pakai untuk grafik, aksen, dan teks besar saja |
| `#FC734D` di `#FFFFFF` | ≈ 2,7:1 | **Gagal** sebagai teks; sebagai aksen non-teks saja |
| `#101E40` di `#FC734D` | ≈ 6:1 | Lulus (dipakai di `.btn-accent`) |

---

## 4. Tipografi

- **Font:** Poppins untuk seluruh UI dan teks chart, dimuat lokal lewat `@fontsource/poppins` (bobot 400, 500, 600, 700). Fallback: `ui-sans-serif, system-ui, sans-serif`. Token: `--font-sans`.
- **Jangan** memakai bobot 750 atau 800 (tidak dimuat). Jangan menebalkan semua judul, tombol, dan badge sekaligus.
- Chart ApexCharts membaca `--font-sans` dan warna teks dari token (`resources/js/charts.js`).

### 4.1 Skala

| Token | Nilai | Pemakaian |
|---|---|---|
| `--font-body` | 14 px (`.875rem`) | Body |
| `--font-control` | 13 px (`.8125rem`) | Label form, tombol, kontrol |
| `--font-caption` | 12 px (`.75rem`) | Caption, metadata, hint, error |
| `--font-heading` | `clamp(1.75rem, 2.3vw, 2rem)` | Heading halaman |
| `--font-section` | 20 px (1.25rem) | Heading section (token ada) |
| `--font-card` | 16 px (1rem) | Judul card dan section |
| `--leading-body` | 1.6 | Body |
| `--leading-control` | 1.4 | Kontrol |
| `--leading-heading` | 1.25 | Heading |

Heading yang sering dipakai:
- `h1` global 28 px (mobile 23 px). `.page-heading h1` memakai `margin-bottom: 8px`.
- `.section-title` 16 px, 600, letter-spacing `-.02em`.
- `.section-desc` 12 px, `--text-muted`, line-height 1.6.
- `.metric-label` / `.data-table th` / `.summary-label`: 10–11 px, uppercase, bobot 600–700, letter-spacing kecil.
- KPI value: 24–35 px, bobot 700, line-height 1.2.

---

## 5. Spasi, radius, ukuran

### 5.1 Spasi (skala 4 px)

`--space-1` 4 · `--space-2` 8 · `--space-3` 12 · `--space-4` 16 · `--space-5` 20 · `--space-6` 24 · `--space-8` 32 · `--space-10` 40 px.

Pola umum:
- Padding card: 24 px (`.panel`, `.card-pad`); mobile 18–19 px.
- Gap section: 24 px (`.section-block` margin-top 24 px).
- Label → kontrol: 8 px (`.field` gap).
- Ikon → label: 8–12 px.

### 5.2 Radius

| Token | Nilai | Pemakaian |
|---|---|---|
| `--radius-small` | 8 px | Item kecil, sub-card, ikon tile |
| `--input-radius` | 12 px | Input, tombol, toast, menu, dialog kecil |
| `--card-radius` | 16 px | Card, panel, dialog `.modal` |
| `--radius-pill` | 999 px | Badge, pill, chip |

Kartu publik editorial memakai 23 px dan panel hero 26 px. Ini khusus halaman publik.

### 5.3 Ukuran kontrol

- `--control-height`: **44 px**. Dipakai untuk input, select, tombol, dan icon-button.
- Tombol kecil `.btn-sm`: tetap 44 px di desktop (padding 4×12 px, font 12 px).
- Tombol ikon `.icon-button` dan `.umkm-icon-button`: 44×44 px, dan minimal 44 px untuk pointer kasar.
- Ikon: `--icon-size` 20 px (`.icon`). Di sidebar 18 px.
- Tinggi topbar 76 px (mobile 70 px). Sidebar 250 px (drawer 290 px di bawah ~1100 px).

---

## 6. Elevasi, border, dan motion

- Card: border 1 px `--border`, radius 16, shadow `--card-shadow`.
- Dialog, menu popover, dan toast: border 1 px, radius 12–16, shadow `--overlay-shadow`.
- Input: border 1 px `--border-strong`, berubah ke `--brand-primary` saat focus.
- Motion: `--motion-fast` 0,15 s untuk hover dan border. `.page` memakai fade-up 0,35 s. Landing memakai animasi float 0,72–0,85 s.
- `prefers-reduced-motion: reduce` mematikan animasi dan transisi di `app.css:433` dan `public.css:235`. Di landing juga dimatikan hover lift kartu.

---

## 7. Layout shell

### 7.1 Authenticated (`layouts/app.blade.php`)

```
┌────────────┬──────────────────────────────────────────────┐
│ Sidebar    │ Topbar 76px: [search inline] ···· [avatar]   │
│ 250px      ├──────────────────────────────────────────────┤
│ navy       │ main.main-content (padding 32/34 px)         │
│ nav-link   │   .page-heading (h1 + desc + heading-actions)│
│ aktif:     │   .card.filter-panel                         │
│ primary    │   .card.panel  (tabel / chart / list)        │
│            │   …                                          │
│            │ footer .site-footer                          │
└────────────┴──────────────────────────────────────────────┘
```

- Di bawah ~1100 px, sidebar disembunyikan dan diganti `navigation-drawer` (dialog) yang dibuka dari `#menu-button`.
- Pencarian global berupa form inline di topbar. Di mobile, pencarian memakai `#search-button` dan `#search-dialog`.
- `main.main-content` memakai atribut `data-navigation-page` dan `inert`. Ini bagian dari navigasi parsial UI-0 dan tidak boleh diubah.

### 7.2 Publik (`layouts/public.blade.php`)

- Header sticky 86 px (mobile 72–76 px), latar putih 93% dengan blur, border bawah `#E8EDF8`.
- Brand: `.brand-mark` 46 px, latar primary, titik accent di pojok kanan atas.
- Container 1200 px (mobile `calc(100% - 36px)`).
- Section standar: padding 104 px vertikal (mobile 69 px).
- Footer: latar putih, border atas `#E9EDF5`.

---

## 8. Komponen

### 8.1 Tombol

Kelas dasar: `.btn` atau `.button` (keduanya dipakai bersamaan di Blade, mis. `btn btn-primary button button-primary`).

| Varian | Kelas | Tampilan |
|---|---|---|
| Primary | `.btn-primary` / `.button-primary` | Latar `#0207CA`, teks putih. Hover/pressed `#0006B0` |
| Secondary / outline | `.btn-light` / `.btn-outline` / `.button-outline` | Latar putih, border `--border-strong`. Hover `--surface-hover` |
| Ghost / tertiary | `.button-ghost` / `.btn-ghost` | Transparan, tanpa border. Hover `--surface-hover` |
| Destructive | `.button-danger` (`.btn-orange` adalah alias lama) | Latar `#A52135`, teks putih |
| Link | `.text-link`, `.link`, `.table-link` | Teks `#0207CA`, garis bawah |
| Icon button | `.icon-button` | 44×44 px, warna teks muted, hover `--surface-hover` |
| Publik | `.btn-white` (di atas gradient), `.btn-accent` (teks navy), `.btn-outline` | Khusus `.public-body` |

Ukuran: `.btn` min-height 44 px, padding 8×16 px, radius 12 px, font 13 px / 500. `.btn-sm` untuk aksi kecil di kartu (padding 4×12 px, font 12 px). `.btn-full` lebar penuh.

State:
- Disabled: opacity 0,65, `cursor: not-allowed`.
- Busy: `aria-busy="true"` dengan `cursor: progress`. Label tetap terbaca (`setBusy` di `ui.js`).
- Pressed: `translateY(1px)`.
- Focus: outline 2 px primary.

Aturan pemakaian:
- Satu tombol primary per area aksi. Aksi sekunder memakai outline atau ghost.
- Tautan yang berperan sebagai navigasi tetap berupa `<a>` dan dibedakan dari tombol.
- Aksi yang tidak bisa dilakukan tampil disabled, bukan disembunyikan, kecuali ada alasan khusus.

### 8.2 Input teks, textarea, search

Global (`app.css:82–99`) untuk `input` (selain checkbox, radio, range), `select`, dan `textarea`:
- Tinggi 44 px, padding 8×12 px, radius 12 px, border 1 px `--border-strong`, latar putih, teks 13 px.
- Placeholder `--text-muted`.
- Focus: border `--brand-primary`, plus outline focus-visible.
- Invalid: `aria-invalid="true"` membuat border merah `--status-error`.
- Disabled atau readonly: latar `--surface-disabled`, teks muted.

Struktur field di workspace:

```html
<div class="field form-field">
  <label for="sector">Sektor</label>
  <input id="sector" aria-describedby="sector-help sector-error">
  <small id="sector-help">Bantuan singkat (caption 12 px)</small>
  <p class="field-error" id="sector-error"></p>
</div>
```

- `.field` / `.form-field`: flex column, gap 8 px, margin-bottom 16 px.
- Label: 13 px, 500, teks navy.
- `.field-error`: 12 px, merah. Kosong = tersembunyi (`:empty`).
- Pesan error form di atas: `.form-message` / `.notice` dengan latar merah muda.

Struktur field di halaman publik (`public.css:88–91`):
- `.field` dengan `.field-top` (label kiri, tautan kanan seperti "Lupa password?" 12 px bold primary).
- `.input-shell` membungkus input. Jika ada ikon di kanan (mata password), tambahkan `.has-trailing`.
- Tautan kecil di sisi kanan `.field-top` (`button`, 12 px bold primary) untuk aksi pendukung di samping label.
- `.eye-toggle` 36×36 px, ghost, di kanan.
- `.input-hint` 11 px muted dan `.strength-row` untuk kekuatan password (segmen 5 px; aktif accent → secondary → teal `#149C8B`).
- Tombol submit auth: `.auth-submit` lebar penuh, tinggi 54 px.

### 8.3 Select

- Native `<select>` memakai style yang sama dengan input. Tidak ada kelas `.control` di CSS; `class="control"` di Blade tidak berpengaruh.
- Select dengan pencarian dan pilihan banyak memakai `createSelectControl` (`resources/js/select-control.js`):
  - Trigger berupa `button.button.button-outline.province-filter-trigger` dengan chevron 8 px dari `::after`. Saat terbuka, border menjadi primary.
  - Panel `div.province-filter-panel` berisi input pencarian, lalu daftar `ul.province-filter-options` berisi tombol dengan `aria-pressed`.
  - Panel berbentuk popover. Di dalam dialog ia memakai popover `manual` dengan posisi fixed.
  - Panel: latar putih, border `--border-strong`, radius 12 px, shadow overlay. Daftar memiliki tinggi maksimum 5 baris (≈220 px) lalu scroll.
  - Opsi: tinggi minimum 44 px, radius 8 px. Hover dan focus-visible memakai `--surface-hover`.
  - Opsi terpilih (`aria-pressed="true"`): teks primary, bobot 600, latar `--surface-pressed`.
- Pilihan tunggal berukuran kecil (mis. jumlah baris per halaman) memakai `.pagination-controls select` dengan tinggi 36 px.

### 8.4 Checkbox, radio, dan pilihan skala

- Checkbox dan radio native memakai `accent-color: var(--brand-primary)`, ukuran 18 px.
- `.radio-pill`: chip pilihan minimal 44 px tinggi, border `#D9DEE9`, radius 8 px, font 11–13 px. Saat `:has(input:checked)`, border dan latar menjadi warna faktor (`--factor-color`) dengan tint 7%.
- Likert tabel (`.likert-table`): kolom skala dengan `.likert-option` minimal 36×44 px.
- Tombol skala 1–5 (`.rating-option`): ukuran berubah per breakpoint, minimal 36 px.
- Fokus pada pilihan: `:has(input:focus-visible)` memberi outline 2 px.

### 8.5 Card, panel, dan section

- `.card` / `.panel`: latar putih, border 1 px `--border`, radius 16 px, shadow tipis, `min-width: 0`.
- `.panel` padding 24 px. `.card-pad` juga 24 px. `.filter-panel` padding 20×24 px.
- `.section-top` / `.section-heading`: flex, `justify-content: space-between`, align center, margin-bottom 22 px. Berisi `.section-title` (judul kiri) dan aksi (kanan).
- `.section-desc` menjelaskan section di bawah judul.
- `.section-block` memberi jarak 24 px antar section.
- `.page-heading`: flex, `align-items: flex-end`, margin-bottom 24 px. Berisi `h1` + `p` deskripsi di kiri, `.heading-actions` (gap 9–10 px) di kanan. Di mobile menjadi kolom.
- `.note-card`: latar `#F0F7FF`, border kiri 3 px secondary, radius 13 px. Dipakai untuk catatan penjelas.
- `.action-row`: baris berisi ikon tile 40 px (`.action-icon`, latar `--surface-hover`), judul 12 px, dan deskripsi muted, dipisah border atas.
- `.card-inset`: latar `#F9FAFE`, border tipis, radius 12 px. Untuk blok di dalam card.
- `.empty-state`: pusat, padding 45×20 px, judul 18 px, deskripsi 12 px muted.

### 8.6 KPI dan metric card

- `.metric` atau `.home-*-kpi`: card dengan `.metric-label` (uppercase 10–11 px, muted), nilai `.summary-value` / `.home-*-kpi-value` (24–35 px, bobot 700), dan catatan (12 px muted).
- Angka memakai `.num` (`font-variant-numeric: tabular-nums`).
- Varian kritis: latar `#FFF0F1` dan label merah. Varian "total": latar navy `#30225C` dengan teks putih.
- Strip metric: `.metric-strip`.

### 8.7 Tabel

- Pembungkus `.data-table-wrap` / `.table-scroll` dengan `overflow-x: auto`.
- `th`: latar `#F6F8FC`, teks muted, 10 px, uppercase, bobot 700, padding 16×15 px. Sudut kiri dan kanan membulat.
- `td`: padding 18×15 px, border bawah `--border`, teks `#495C7B`. Nama tebal navy.
- Hover baris: `#FAFBFE`.
- Nama panjang: `overflow-wrap: anywhere` agar tidak melebar.
- Sel badge: `.sector-badge` (border dan latar abu tipis, uppercase 9 px) atau `.category-label`.
- Aksi per baris: tombol titik tiga (lihat 8.8).
- Footer tabel: `.table-footer` (border atas, padding 16 px 0 0) berisi teks jumlah dan `.pagination`.
- Desktop memakai tabel. Di mobile, daftar kartu `.umkm-card-list` menggantikan tabel (`dashboard.css:994–1012`).

Catatan: `.data-table` didefinisikan dua kali (`app.css:218–228` dan `dashboard.css:735–761`). Untuk halaman workspace, versi `dashboard.css` yang berlaku.

### 8.8 Pagination

- `.pagination` dan `#umkm-pagination` berisi `.page-button` (min 32×36 px, mobile 44 px).
- Hover: latar `#EAF6FF`, teks primary. Halaman aktif (`.is-current`): latar primary, teks putih.
- Disabled: opacity 0,5.
- Tombol panah memakai `.umkm-icon-button`.

### 8.9 Menu aksi titik tiga (three-dots)

Ini aturan wajib (FR-02 PRD UI-1).

- Pemicu: `button.page-button.umkm-icon-button` dengan ikon `dots-vertical`, `aria-label`, dan `popovertarget`.
- Menu: `div[popover=auto].umkm-action-menu`, latar **putih**, border 1 px `--border`, radius 12 px, padding 8 px, shadow overlay. Posisi `position-area` dengan fallback flip.
- Item: `a.row-action` atau `button.row-action`. Tinggi minimum 44 px, padding 8×12 px, font 13 px / 500, teks **navy**. Ikon mengikuti `currentColor`.
- Hover dan focus-visible: latar `--surface-hover`. Pressed: `--surface-pressed`.
- Pemisah: `hr` dengan warna `--border`.
- Aksi destruktif (mis. "Hapus"): `.row-action.danger`, teks `--status-error`. Dipisah dengan `hr`.
- **Tidak ada** warna latar atau ikon berbeda per item. Semua item netral.
- Behavior: buka/tutup dengan klik, klik di luar, dan Escape (popover auto). Konfirmasi lewat `confirmAction` untuk aksi destruktif.

### 8.10 Dialog / modal

Kelas dasar: `dialog.modal.dialog` (`app.css:137–153`).

- Lebar `min(530px, 100vw − 32px)`, max tinggi `100dvh − 40px`, scroll internal.
- Radius 16 px, border 1 px `--border`, shadow overlay, latar putih.
- Backdrop: `--overlay` (`rgb(16 30 64 / .47)`).
- Struktur:
  ```html
  <dialog class="modal dialog">
    <div class="dialog-head">   <!-- judul h2 + icon-button tutup -->
    <div class="dialog-body">   <!-- konten, padding 16–24 px -->
    <div class="dialog-footer"> <!-- tombol, rata kanan, border atas -->
  </dialog>
  ```
- Header: judul kiri (`h2` bobot 600), border bawah. Tombol tutup `.icon-button` dengan `aria-label` "Tutup …" dan atribut `data-close-dialog`.
- Footer: border atas, rata kanan, urutan tombol: batal (outline) lalu aksi utama (primary atau danger). Pada mobile, footer membungkus baris.
- Padding: `--dialog-inset` 24 px desktop, 16 px mobile.
- Varian ukuran:
  - Default 530 px.
  - `.preview-modal` 760 px (ringkasan draft).
  - `.six-factor-detail-dialog` 1120 px dengan `.dialog-body` maks. 72 vh dan scroll.
  - `.workspace-search-dialog` dibuka dari topbar, posisi dekat atas.
  - `.navigation-drawer`: dialog 290 px di sisi kiri untuk menu mobile.
- Perilaku (`ui.js`): `openDialog` memfokuskan elemen `[autofocus]` atau input, `bindDialog` menutup saat klik backdrop atau `data-close-dialog`, dan fokus kembali ke pemicu saat ditutup.

### 8.11 Dialog konfirmasi

API: `confirmAction({ title, message, confirmLabel, tone })` di `resources/js/ui.js`. Mengembalikan `Promise<boolean>`.

- Memakai dialog `.modal.dialog.confirm-dialog` yang dibuat sekali dan dipakai ulang.
- Struktur: `h2` judul, paragraf pesan (`.section-desc`), footer berisi tombol **Batal** (outline, fokus awal) dan tombol konfirmasi.
- `tone: 'primary'` memakai `btn-primary`. `tone: 'danger'` memakai `button-danger` (merah `#A52135`).
- Default: `confirmLabel` = "Lanjutkan", `tone` = `primary`.
- Pola kalimat yang dipakai:
  - Judul pertanyaan singkat: "Buang perubahan?", "Keluar dari akun?", "Kosongkan jawaban?", "Tinggalkan halaman?".
  - Pesan menjelaskan dampaknya: "Perubahan belum disimpan. Buang perubahan dan lanjutkan?".
  - Label tombol berupa kata kerja spesifik: "Buang perubahan", "Keluar", "Kosongkan", "Tinggalkan". Jangan memakai "OK" atau "Ya".
- Tidak boleh memakai `window.confirm` atau `alert` baru.

### 8.12 Toast

- Region tetap di kanan bawah, jarak 16 px dari tepi, lebar maks. 400 px (`min(400px, 100vw − 32px)`), `z-index` 300.
- Setiap toast: flex, border 1 px `--border`, radius 12 px, shadow overlay.
- Varian latar dan teks: `toast-success` (hijau), `toast-error` (merah), `toast-warning` (amber). Di luar itu latar putih.
- Tombol tutup `.icon-button` dengan `aria-label` "Tutup notifikasi".
- Aksesibilitas: error memakai `role="alert"`, lainnya `role="status"`. Region memakai `aria-live="polite"`.
- Pemanggilan: `toast(message, type)` di `ui.js`.

### 8.13 Alert, notice, dan error state

- `.notice`, `.form-message`: padding 12×16 px, radius 8 px, latar merah muda, teks merah 13 px.
- `.error-state`: `role="alert"`, grid rata kiri, padding 24 px, berisi pesan, tombol "Coba lagi" (outline), dan tautan kembali (`text-link`) bila 404.
- Peringatan non-error dipakai lewat `.live-badge[data-tone="warn"]` atau `.notice` dengan warna amber.

### 8.14 Badge, pill, dan chip

| Kelas | Tampilan | Dipakai untuk |
|---|---|---|
| `.pill` + `.pill-green` | Teks hijau, latar hijau muda | Status baik, final |
| `.pill-amber` / `.pill-orange` | Teks amber, latar amber muda | Peringatan |
| `.pill-blue` | Teks primary, latar `#E9ECFF` | Informasi, progres |
| `.pill-muted` | Teks muted, latar `--surface-hover` | Kategori netral, kapasitas (mis. "0 / 5 terisi") |
| `.draft-badge` | Pill amber, teks 12 px, weight 500 | Status draft |
| `.live-badge[data-tone]` | `good` hijau, `warn` amber, `draft` biru soft | Status evaluasi live |
| `.live-category[data-tone]` | `good`, `warn`, `danger` | Kategori hasil |
| `.sector-badge` | Border dan latar abu, uppercase 9 px, radius 5 px | Sektor usaha di tabel |
| `.home-three-badge-blue` | Pill biru tint 10% | Jumlah di daftar |

Aturan: badge kategori biasa netral. Warna semantik hanya untuk status yang bermakna.

### 8.15 Progress dan track

- `.progress` (dashboard): tinggi 8 px, track `#EEF2F8`, radius pill, `span` sebagai fill (transisi lebar 0,3 s).
- `.track` / `.fill` (publik): tinggi 9 px, track `#EBEEFA`, fill primary, varian `.secondary` dan `.accent`.
- `.home-three-track` / `.home-three-fill`: tinggi 8 px, fill primary.
- Progress native `<progress>` dipakai di `.summary-average` dengan `accent-color` mengikuti `--summary-color`.

### 8.16 Skeleton

- `.skeleton`: latar `--skeleton-fill` (`#E8ECF2`), radius 8 px. **Tanpa shimmer**. Kelas tinggi dan lebar utilitas: `h-3`, `h-4`, `h-10`, `w-1/3`, dsb. (Tailwind).
- Pola: `skeleton(...)` di `ui.js` membuat `div.grid.gap-3[aria-hidden]` dengan blok skeleton.
- Skeleton lokal per section, bukan overlay layar penuh. Ukuran dibuat stabil agar tidak ada layout shift.
- Container yang sedang memuat memakai `aria-busy="true"` dan `role="status"`.

### 8.17 Ikon

- Sprite SVG lokal di `components/icon-sprite.blade.php`, simbol bernama `joy-i-<nama>`. Contoh: `home`, `radar`, `distribution`, `business`, `form`, `user`, `info`, `logout`, `menu`, `close`, `search`, `bell`, `arrow`, `dots-vertical`, `plus`, `filter`, `trend`, `circle`, `clock`, `spark`, `check`, `edit`, `trash`, `eye`, `eye-off`, `save`, `reset`, `chart`, `globe`, `shield`, `users`, `comparison`.
- Gaya: outline, viewBox 24, `stroke="currentColor"`, stroke-width 1.7, linecap dan linejoin round.
- Blade: `<x-icon name="plus" />` → `svg.icon` 20 px, `aria-hidden="true"`.
- JS: `icon(name)` di `ui.js`.
- Ikon mewarisi warna teks. Ikon netral mengikuti `--text-muted` atau teks. Warna status hanya saat bermakna.
- Tombol berisi ikon saja wajib punya `aria-label`.
- Tidak boleh memakai emoji sebagai ikon fungsional. Pengecualian: panah teks (`→`, `←`, `↗`) pada beberapa tombol lama.

### 8.18 Chart dan visualisasi data

- Dirender dengan ApexCharts di `resources/js/charts.js`. Opsi dasar: tinggi 320 px, `toolbar` nonaktif, animasi nonaktif, `fontFamily` dari `--font-sans`.
- Warna seri: brand dan semantik (`--series-color`). Data tidak boleh hanya dibedakan dengan warna, jadi legend dan tabel pendamping tersedia.
- Wadah: `.chart-panel` dengan `.chart-heading` (judul + badge), `.chart-host` (min. tinggi 320 px), `.chart-caption`.
- Legend: `.legend-item` (swatch 10 px, label muted, nilai bold di kanan), `.legend-inline`, `.legend-swatch`.
- Tabel alternatif: `.chart-table-wrap` / `.chart-table` (angka rata kanan, `tabular-nums`) untuk membaca data tanpa grafik.
- Tooltip: `.chart-tooltip` dengan padding 8×12 px, teks caption, maks. 260 px.
- Garis baseline: `.baseline-key` garis putus-putus abu `#64748B`.
- Skeleton chart: `components/chart-skeleton.blade.php`.

### 8.19 Navigasi

**Sidebar (authenticated)**
- Latar `--sidebar-surface` (`#101E40`), lebar 250 px, fixed, scroll internal.
- Brand: `.sidebar-brand` dengan `.brand-mark` 44 px (latar putih tipis, border putih 37%). Teks putih.
- Label grup (`.nav-section-label`): 12 px, bobot 500, warna `--sidebar-muted` (`#B5C5E7`).
- `.nav-link`: tinggi minimum 44 px, padding 8×12 px, radius 8 px, font 13 px / 500, teks `--sidebar-text` (`#DFE8FF`), ikon 18 px, gap 12 px.
- Hover: latar `rgb(255 255 255 / .10)`, teks putih.
- Aktif (`.is-active`): latar `#0207CA`, teks putih, bobot 600.
- Logout: teks `#FFD6C9`.
- Catatan: gradient di `app.css:103` tidak berlaku (lihat bagian 12).

**Topbar**: tinggi 76 px, latar putih 96%, border bawah `#E8EDF6`. Pencarian global 395 px maks. Avatar `user-avatar` 38 px dengan gradient primary.

**Tautan dalam halaman** (`.link`, `.text-link`) memakai garis bawah dan warna primary.

**Navigasi publik**: header sticky. Tautan navigasi `#303B64` (hardcoded), hover primary.

### 8.20 Pola khusus halaman publik

- **Eyebrow**: pill 11 px, uppercase, letter-spacing 0,1 em, latar `#F0F3FF`, border `#DDE5FF`, titik accent 8 px.
- **Kicker**: teks 11–12 px uppercase, warna secondary, letter-spacing 0,13–0,16 em, dengan garis pemisah 33 px.
- **Heading editorial**: `h2` 36–58 px, bobot 700, letter-spacing −0,063 em, `.heading-accent` diberi warna primary.
- **Kartu editorial** (`.editorial-card`): min. tinggi 426 px, radius 23 px, padding 26 px, hover naik 7 px. Tiga tone:
  - `tone-peach`: gradient `#FFE6D7` → `#FFBEA6` → accent. Teks navy.
  - `tone-blue`: gradient secondary → `#2169E8` → primary. Teks putih.
  - `tone-royal`: gradient `#090BC1` → primary → `#06105E`. Teks putih.
  - Kartu punya ilustrasi (planet, orbit) dan tombol bundar 42 px di kanan bawah.
- **CTA banner**: radius 29 px, gradient primary → `#2641E0` → secondary, teks putih, tombol `.btn-white`.
- **Panel instrumen** (`.instrument-panel`): latar navy `#101E40`, radius 22 px, baris dengan titik faktor (`#ee787a`, `#66b7c2`, `#edaa64`).
- **Panel auth** (`.auth-side`): gradient primary → `#0A22D1` → secondary, dekorasi lingkaran, insight card putih miring −1 derajat.
- **Form auth** (`.auth-content`): lebar maks. 444 px. Judul 32–43 px. Kicker uppercase.
- **Tautan pindah halaman auth** (`.auth-switch`, `.auth-alternative`): teks muted 12–13 px dengan tautan bold primary.

### 8.21 Footer

- Publik: latar putih, border atas `#E9EDF5`, teks 12 px muted.
- Workspace: `.site-footer`, padding 24×32 px, teks 11 px muted, sub-teks 10 px.

---

## 9. Responsif

Breakpoint yang dipakai di kode:

| Lebar | Perubahan utama (dari `public.css` dan `app.css`) |
|---|---|
| ≤ 1120 px | Hero publik lebih rapat; ornamen float digeser |
| ≤ 1100 px | Sidebar disembunyikan dan diganti drawer; `.workspace` tanpa margin kiri; layout evaluasi satu kolom di ≤ 850 px |
| ≤ 960 px | Publik satu kolom; navigasi header disembunyikan; panel auth di atas form; topbar auth disembunyikan |
| ≤ 850 px | `.distribution-grid` dan `.evaluation-layout` satu kolom; ringkasan summary lebih rapat |
| ≤ 700 px | Container publik 100% − 36 px; tombol hero lebar penuh; section 69 px; mockup dashboard jadi grid dua kolom |
| ≤ 640 px | Topbar 70 px; padding main 20×16 px; `.modal` inset 16 px; `.page-heading` jadi kolom; footer tabel jadi kolom; picker satu kolom |
| ≤ 370 px | Grid `.dash-main` preview satu kolom; tombol header publik lebih kecil |

Aturan:
- Tidak boleh ada overflow horizontal dokumen. Tabel memakai scroll internal (`.table-scroll`).
- Teks panjang (nama UMKM) memakai `overflow-wrap: anywhere` atau ellipsis dengan teks lengkap tersedia.
- Uji minimum: 1440×900, 768×1024, 390×844. Tambahan 360 px bila perlu.

---

## 10. Aksesibilitas

- Focus: `:focus-visible` outline 2 px primary, offset 2 px. Di permukaan gelap, outline putih.
- Radio dan checkbox dengan input transparan (`.radio-pill`) menampilkan ring di label.
- Ikon dekoratif: `aria-hidden="true"`. Tombol ikon saja: `aria-label`.
- Error form: `aria-invalid="true"` dan `aria-describedby` menunjuk ke `.field-error`. Error tidak hanya merah, karena ada teks.
- Live region: toast (`aria-live="polite"`), error state (`role="alert"`), status loading (`role="status"`).
- Dialog memakai `aria-labelledby` dan `aria-describedby`. Focus trap, Escape, dan return focus lewat elemen `<dialog>` native.
- Target sentuh: minimal 44 px untuk aksi utama. Tombol titik tiga dan icon-button ikut 44 px pada pointer kasar.
- Reduced motion: animasi dihentikan (`app.css:433`, `public.css:235`).
- Skip link: `.skip-link` ke `#main-content`.

---

## 11. Yang harus dihindari

- Warna brand berbeda per item menu, ikon, atau baris tabel. Gunakan netral.
- Gradient pada komponen operasional (tabel, form, menu, card data). Gradient hanya untuk halaman publik dan panel tertentu.
- Hex baru di Blade atau JS. Tambahkan token ke `tokens.css` lebih dulu.
- `style="…"` inline baru. Ada 10 kemunculan saat ini (lihat bagian 12). Jangan menambah.
- Icon font, emoji sebagai ikon fungsional, atau SVG dari CDN per ikon.
- Font selain Poppins (termasuk Plus Jakarta Sans, Inter).
- `window.confirm`, `alert`, atau dialog kustom baru untuk konfirmasi. Pakai `confirmAction`.
- Bobot 750 atau 800.
- Teks `#0297DE` atau `#FC734D` dengan ukuran kecil di atas putih.
- `!important` sebagai jalan pintas.

---

## 12. Utang desain dan inkonsistensi yang diketahui

| # | Temuan | Lokasi | Dampak |
|---|---|---|---|
| 1 | Hex hardcoded tersebar: sekitar 80 kemunculan di `public.css`, 42 di `dashboard.css`, dan beberapa di `app.css` | `public.css`, `dashboard.css`, `app.css:236–238` (`#6c7790`, `#EAF6FF`) | Sulit dirawat; bertentangan dengan token |
| 2 | `.data-table` didefinisikan dua kali dengan nilai berbeda (`#f5f6fa` 9 px vs `#F6F8FC` 10 px) | `app.css:218–228`, `dashboard.css:735–761` | Versi `dashboard.css` menang karena selector lebih spesifik dan unlayered |
| 3 | `dashboard.css` dan `public.css` tidak berada di `@layer`, sehingga menang atas `@layer components` di `app.css` | `app.css:6–8`, `app.css:15–316` | Urutan cascade sulit diprediksi |
| 4 | Gradient sidebar di `app.css:103` tidak berlaku karena dikalahkan `--sidebar-surface` di `dashboard.css:119` | `app.css:103`, `dashboard.css:115–119` | Kode mati; verifikasi di browser sebelum dihapus |
| 5 | Dua nama kelas untuk varian tombol yang sama (`btn-*` dan `button-*`) | Blade dan JS di seluruh `resources/views` | Duplikasi; pilih satu nama bila dirapikan |
| 6 | `btn-orange` adalah alias lama untuk destructive, bukan oranye | `app.css:64`, `umkms.blade.php:232` | Nama menyesatkan |
| 7 | `class="control"` pada select tidak punya aturan CSS | `home.blade.php:103,125,133`, `umkms.blade.php:52` | Kelas mati |
| 8 | `.guide-panel` memakai ungu `#4D3399`, `.summary-total` memakai `#30225C`. Keduanya di luar palet | `app.css:275`, `app.css:298` | Bertentangan dengan prinsip neutral-first |
| 9 | Tautan navigasi publik `#303B64` dan border `#E8EDF8` hardcoded | `public.css:11`, `public.css:9` | Sebaiknya memakai `--text-primary` dan `--border` |
| 10 | Teks sekunder `--text-muted-brand` (`#70819D`) tidak lulus AA pada putih | `tokens.css:10`, dipakai di `.live-label` (`dashboard.css:1137`) | Jangan dipakai untuk teks kecil |
| 11 | Ada 10 `style="…"` inline di Blade | `auth-brand-panel`, `factor-profile`, `form-data`, `umkms`, `welcome` | Pindahkan ke kelas |
| 12 | Tombol titik tiga memakai kelas `page-button umkm-icon-button`, bukan kelas menu khusus | `umkms.js:174`, `three-dimension-health.js:283` | Campuran antara pagination dan menu aksi |
| 13 | Ketidaksesuaian antara PRD UI-1 (status: "belum ada implementasi") dan audit token (menyebut token dan Poppins sudah dipasang) | `PRD_JoyUMKM_V1_UI-1…md` baris 5, `token-icon-font-audit.md` | Status sebenarnya perlu dikonfirmasi |

Catatan atas acuan palet yang ditempelkan sebelumnya:
- Path token tertulis `resources/css/dashboard.css:5`. Token sebenarnya ada di `resources/css/tokens.css`.
- Acuan menyebut `#70819D` untuk teks sekunder. Di kode, teks sekunder memakai `--text-muted` = `#566781`. `#70819D` hanya `--text-muted-brand`.
- Acuan menyebut sidebar memakai primary. Di kode, sidebar memakai navy `#101E40`, dan item aktif memakai primary.

---

## 13. Pemeliharaan dokumen

- Ubah token hanya di `resources/css/tokens.css`. Perbarui bagian 3 dan 4 setelahnya.
- Komponen baru harus mengikuti salah satu pola di bagian 8. Jika tidak ada yang cocok, tambahkan pola baru di sini dengan contoh markup.
- Setiap perubahan visual wajib diuji di 1440, 768, dan 390 px, serta di state focus, hover, disabled, dan invalid.
