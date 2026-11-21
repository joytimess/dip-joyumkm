# PRD JoyUMKM V1 — UI-1: Design System Foundation

**Document ID:** `PRD-JOYUMKM-UI-1`  
**Tanggal:** 20 November 2026  
**Status:** **APPROVED DESIGN BASELINE — READY FOR IMPLEMENTATION**; belum ada implementasi UI-1 dari dokumen ini  
**Prioritas:** P1, setelah UI-0  
**Repositori:** https://github.com/joytimess/dip-joyumkm  
**Fase roadmap:** UI-0 Navigation & Loading Architecture → **UI-1 Design System Foundation** → UI-2 Workspace & Responsive Navigation → UI-3 Page-Level Visual Refinement → UI-4 Visual QA & Regression

> **Source of truth:** Persetujuan desain pengguna dan handoff UI-0 pada 20 November 2026. Peninjauan remote `main` saat diagnosis UI-0 merujuk ke commit `804738b0a39f9dc2658e40e92e796077d131aca6` (4 November 2026). Checkout lokal UI-0 dan perkembangan setelah commit tersebut **lebih baru**; implementor **wajib membaca checkout aktual** sebelum mengedit, tidak boleh mengembalikan kode ke baseline remote atau menimpa hasil UI-0.

---

## 1. Ringkasan eksekutif

JoyUMKM mempunyai identitas merek Electric Blue (`#0207CA`), Sky Cyan (`#0297DE`), dan Cantaloupe Orange (`#FC734D`), namun pengguna menginginkan aplikasi yang **lebih minimalis, rapi, profesional, tenang, dan tidak warna-warni**. Masalah utama adalah penyebaran warna brand ke komponen operasional yang sebenarnya cukup menggunakan warna netral—khususnya ikon dan item menu dropdown three-dots—serta inkonsistensi tipografi, spacing, border, radius, shadow, dan komponen antara halaman publik dan dashboard.

**UI-1 membentuk satu fondasi design system reusable** tanpa melakukan redesign halaman, perubahan navigasi, atau perubahan business logic. Font global dipindahkan ke **Poppins**; warna merek asli dipertahankan namun penggunaannya ditertibkan; ikon menggunakan satu sistem SVG/ikon visual yang konsisten; komponen primitif (tombol, field, menu aksi, badge, modal, alert/toast, card, skeleton, dan state interaktif) distandardisasi. Seluruh perubahan harus mempertahankan **partial Blade navigation dan loading per komponen dari UI-0**.

**Hasil yang diharapkan:** visual JoyUMKM terasa sebagai satu produk SaaS profesional yang koheren; tidak terdapat menu aksi berwarna pelangi; komponen mudah digunakan dengan mouse, keyboard, dan sentuhan; tidak ada perubahan alur bisnis atau regresi navigasi.

## 2. Dasar keputusan & bukti

### 2.1 Keputusan yang sudah disetujui pengguna

| Area | Keputusan final | Implikasi UI-1 |
|---|---|---|
| Identitas merek | Pertahankan warna asli `#0207CA`, `#0297DE`, `#FC734D` | Perbaiki peran dan penggunaan; jangan mengganti kode warna brand |
| Filosofi visual | **Minimalist Professional SaaS**, clean, data-first, tidak terlalu berwarna | Mayoritas permukaan dan kontrol bersifat netral |
| Font | **Poppins di seluruh aplikasi** | Terapkan ke halaman publik, authenticated, form, tabel, modal, dan teks chart |
| Ikon | SVG konsisten; dapat mengambil dari sumber resmi Google Fonts/Material Symbols atau memakai SVG yang sudah tersedia | Pilih satu pendekatan konsisten; hindari campuran gaya |
| Menu three-dots | **Monokrom/netral**; pengecualian untuk tindakan destruktif | Tidak ada ikon, badge, atau latar warna berbeda untuk setiap aksi |
| Sidebar | Nantinya **Midnight Navy `#101E40`**, active state Electric Blue | UI-1 siapkan token; **implementasi sidebar + pengelompokan menu berada di UI-2** |
| Daftar UMKM mobile | Card list mobile; tabel desktop | **UI-2**, jangan diimplementasikan pada UI-1 |
| Landing/Login/Register | Pertahankan layout, refine visual | UI-1 hanya penerapan font/tokens/primitives; **page-level refinement di UI-3** |
| Navigasi & loading | UI-0 telah PASS berdasarkan handoff lokal | **Wajib dipertahankan**; jangan membuat ulang guard, shell, atau router |

### 2.2 Temuan source code (baseline remote lama; verifikasi ulang lokal)

- `resources/css/app.css` mengimpor `public.css` dan `dashboard.css`, menggunakan `Plus Jakarta Sans` melalui `@fontsource` serta `--font-sans`.
- `resources/js/charts.js` masih memuat `fontFamily: 'Plus Jakarta Sans, sans-serif'` pada opsi chart dalam baseline yang ditinjau.
- Warna dasar tercatat pada token CSS dashboard/public; beberapa UI memuat warna hard-coded dan gaya inline.
- Ikon saat baseline didistribusikan melalui komponen Blade `resources/views/components/icon.blade.php` dan penggunaan `<x-icon ... />`, serta beberapa ikon/grafik yang dibuat JavaScript.
- Ada komponen global yang sudah bekerja: button, modal/dialog, toasts, forms, panel/card, tabel, skeleton, state loading dan error. **Gunakan kembali**; jangan menggandakan sistem styling/behavior.

**Status evidence:** sumber di atas adalah inspeksi statis commit lama dan pengamatan screenshot, bukan pengukuran CSS runtime yang definitif. Handoff UI-0 menunjukkan source yang lebih baru pada checkout lokal. Implementor harus memeriksa dan mengunci baseline terbaru sebelum pengerjaan.

## 3. Target pengguna dan user stories

**Aktor:** pendamping UMKM yang memakai landing/auth dan workspace pada desktop, tablet, dan mobile.

- **US-01:** Sebagai pengguna, saya melihat font Poppins dengan ukuran, bobot, dan jarak baris yang nyaman di setiap halaman.
- **US-02:** Sebagai pengguna, saya dapat memahami tindakan utama dari tombol biru yang jelas, sementara aksi sekunder tidak bersaing secara visual.
- **US-03:** Sebagai pengguna, ketika membuka menu three-dots, saya melihat daftar tindakan netral yang mudah dipindai, bukan item berwarna-warni.
- **US-04:** Sebagai pengguna keyboard/mobile, saya dapat memfokuskan, memilih, menutup, dan menjalankan kontrol secara jelas tanpa kehilangan aksesibilitas.
- **US-05:** Sebagai pengguna yang berpindah halaman, perubahan visual tidak menghilangkan shell stabil ataupun skeleton lokal yang sudah dibangun pada UI-0.
- **US-06:** Sebagai pengguna dengan preferensi reduced motion, saya tidak dipaksa melihat animasi dekoratif berlebihan.

## 4. Sasaran & non-sasaran

### 4.1 Sasaran wajib

1. Menciptakan **satu sumber token visual** (brand, neutral, semantic, typography, spacing, radius, border, shadow, interactive states) yang dapat dipakai di public dan authenticated pages.
2. Menggunakan **Poppins** secara global tanpa font campuran yang tidak disengaja, termasuk chart-generated text.
3. Menetralisasi menu three-dots/dropdown, ikon dekoratif dalam kontrol standar, dan komponen yang tidak perlu aksen penuh warna.
4. Menormalisasi elemen visual dan state komponen inti secara reusable, tanpa mengubah event handler atau behavior bisnis.
5. Mengendalikan cascade dan deklarasi duplikat pada `app.css`, `public.css`, `dashboard.css`, template Blade dan renderer JS.
6. Menjaga UI-0 **PASS** dan menyediakan bukti visual, accessibility smoke, serta regresi otomatis.

### 4.2 Di luar cakupan UI-1 — jangan menyelinap ke fase ini

- **UI-0:** Mengganti ulang `workspace.js`, partial routing, lifecycle halaman, auth guard, `AbortSignal`, abort request, serta aturan skeleton/data loading (kecuali penyesuaian **skin** skeleton secara aman).
- **UI-2:** Mengubah sidebar ke navy pada UI aktual, active item, mengelompokkan menu Beranda/Manajemen/Penilaian/Analitik, topbar/search behavior, breadcrumb, filter mobile, **tabel UMKM desktop vs card list mobile**, atau perubahan alur navigasi.
- **UI-3:** Redesain komposisi landing/login/register; merombak layout dashboard, UMKM, kuesioner, enam faktor, distribusi, comparison; mengubah susunan KPI/chart/form/radar/slider atau memperbaiki microcopy dan angka bisnis spesifik halaman.
- **UI-4:** Audit lintas-browser dan regresi final menyeluruh; UI-1 tetap wajib menjalankan tes terarahnya sendiri.
- Migrasi Vue/React/Inertia, ganti arsitektur Blade, migrasi database, aturan API, status Draft/Final/Batal, histori, skoring, ownership, seeders, maupun fitur bisnis baru.
- Menambah fitur profil/bantuan/notifikasi yang belum aktif atau menyembunyikan fungsi yang ada karena alasan estetika.

## 5. Prinsip visual yang mengikat

1. **Neutral-first:** surface putih/soft grey, teks navy, divider halus. Warna brand adalah aksen bermakna, bukan cat dekoratif pada setiap elemen.
2. **Identitas asli tetap:** warna brand persis, tidak dipindahkan ke variasi warna lain yang mendekati namun tidak sama.
3. **Hierarchy before decoration:** ukuran, weight, alignment, whitespace, dan state lebih penting dari gradient dan efek.
4. **Semantic colors only for semantics:** sukses/amber/error boleh berwarna sesuai peran; jangan mengubahnya menjadi indikator dekoratif.
5. **Consistent icons:** gaya, ukuran, stroke/optical weight, alignment, dan state seragam.
6. **Accessible defaults:** target kontras minimal WCAG AA untuk teks, focus terlihat, kontrol tidak bergantung pada warna saja.
7. **Preserve interactions:** perubahan CSS/SVG/font tidak boleh mengganti makna tombol, merusak click target, overlay, form validation, atau aplikasi loading.

## 6. Design tokens

### 6.1 Warna brand — nilai wajib persis

| Peran | Hex | Penggunaan di UI-1 |
|---|---|---|
| Primary | `#0207CA` | CTA utama, tautan/interaksi penting, focus/active bermakna |
| Secondary | `#0297DE` | Grafik/progress dan emphasis pendukung yang jelas |
| Accent | `#FC734D` | Sorotan insight tertentu, **terbatas**; bukan warna setiap ikon/menu |
| Navy/text utama | `#101E40` | Heading, body primer, token sidebar future UI-2 |
| Muted/text sekunder | `#70819D` | Metadata, supporting label, placeholder non-esensial dengan kontras yang cukup |
| Canvas | `#F4F7FC` | Background workspace |
| Surface | `#FFFFFF` | Card, input/menu/dialog surfaces |
| Semantic success | `#147D5B` | Status sukses yang bermakna |
| Semantic warning | `#9B6508` | Status peringatan yang bermakna |

- Nilai merah/error, border, hover-neutral, focus ring, disabled, dan shade pendukung dipilih setelah audit CSS lokal; tidak perlu menambah warna brand baru.
- Warna `secondary`/`accent` **jangan dipakai sebagai teks kecil di atas putih** tanpa uji kontras. Jika tidak lulus, gunakan navy untuk teks dan warna brand sebagai aksen non-teks, atau berikan treatment yang lulus kontras tanpa mengubah token brand asli.
- Status tidak boleh hanya dibedakan dengan warna: sertakan label/ikon/teks sesuai kebutuhan.
- Tidak menetapkan persentase distribusi warna yang dibuat-buat; acuan adalah penggunaan yang selektif dan fungsi semantik yang jelas.

### 6.2 Skala typography — target desain

| Elemen | Ukuran acuan | Weight | Line-height acuan |
|---|---|---|---|
| Page heading | 28–32 px | 600–700 | 1.20–1.30 |
| Section heading | 20–24 px | 600 | 1.30 |
| Card heading | 16–18 px | 600 | 1.35 |
| Body | 14–16 px | 400 | 1.50–1.60 |
| Form label/button | 13–14 px | 500 | 1.40 |
| Caption/metadata | 12–13 px | 400–500 | ≥1.40 |
| KPI figures | 28–36 px | 600–700 | 1.10–1.25 |

- Typeface **Poppins** untuk semua user-facing UI dan grafik. Gunakan weight yang **benar-benar dimuat**, bukan browser-synthesized weight 750/800 yang tidak tersedia. Direkomendasikan subset normal 400/500/600/700, tambah 300/800 hanya bila kebutuhan nyata telah terverifikasi.
- Font sebaiknya dimuat lewat mekanisme build lokal (misalnya `@fontsource/poppins`) agar tidak menggantungkan rendering pada request font eksternal saat runtime; sumber lisensi font harus sah. **Jangan menyertakan file font pada deliverable PRD.**
- Atur `font-display: swap`/perilaku setara dan fallback sans-serif agar rendering tetap terbaca.
- Poppins lebih lebar dibanding font lama; **wajib cek overflow/reflow** pada menu, tombol, tabel, caption, header, modal, mobile, dan nama UMKM panjang.
- Jangan memaksa semua judul, tombol, label, dan badge menjadi bold. Letter-spacing negatif ekstrem tidak dipakai untuk body.
- Di JS chart/ApexCharts, jangan meninggalkan font lama hard-coded: sinkronkan config dan label dengan token baru tanpa mengubah data, skala, maupun seri.

### 6.3 Spacing & sizing

Gunakan skala **4 px** sebagai dasar: `4, 8, 12, 16, 20, 24, 32, 40` px (dapat berupa CSS custom properties/utility yang konsisten).

| Komponen | Acuan desktop | Acuan mobile | Catatan |
|---|---|---|---|
| Page horizontal padding | 24–32 px | 16 px | **Token untuk UI-2/UI-3**, jangan mengubah layout page secara massal di UI-1 |
| Card interior padding | 20–24 px | 16–20 px | Pertahankan kepadatan data jika ruang terbatas |
| Section gap | 24–32 px | 20–24 px | Sesuaikan konteks dan visual rhythm |
| Form label → control | ±8 px | ±8 px | Konsisten di public & authenticated |
| Control height | ~40–44 px | ~44 px | Target sentuh nyaman; pengecualian teruji untuk tabel padat |
| Icon + label gap | 8–12 px | 8–12 px | Tidak berubah acak antar komponen |

- Token radius, border, shadow didefinisikan terpusat berdasarkan audit kondisi lokal; **tidak** perlu semua card memiliki shadow kuat.
- Hindari `margin` dan `padding` inline yang baru. Migrasikan inline visual lama hanya di shared component yang masuk cakupan UI-1, tidak perlu sweeping seluruh halaman.
- Skeleton menggunakan palette neutral dan dimensi komponen yang stabil; non-blocking UI-0 tetap dijaga.

### 6.4 State tokens

Tentukan aturan visual untuk `default`, `hover`, `active`, `focus-visible`, `disabled`, `loading`, `invalid`, `success`, `warning`, dan `destructive` bila relevan. Gunakan satu pendekatan lintas komponen. Focus state cukup jelas tanpa mengubah posisi/ukuran layout.

## 7. Ikon dan aset SVG

### FR-01 — Satu sistem ikon visual **[MUST]**

- Audit dulu semua penggunaan `<x-icon>`, inline SVG, icon sprite, ikon yang dibuat oleh renderer JS, dan ikon di tombol aksi/dropdown. Identifikasi gaya campuran dan duplicate glyph.
- Pilihan default yang direkomendasikan: **pertahankan komponen SVG yang sudah ada jika konsisten dan lengkap**; gunakan aset resmi **Google Material Symbols (Outlined) versi SVG** hanya sebagai sumber pengganti/penambah ketika diperlukan. Jika memilih Material Symbols, semua ikon operasional relevan harus menggunakan gaya yang sama. **Jangan mengasumsikan Google Fonts otomatis menyediakan file SVG yang cocok untuk setiap penggunaan** tanpa mengecek sumber/lisensinya.
- Hindari kombinasi random dari emoji, filled icon, icon font, dan outline SVG untuk fungsi setara.
- Standar acuan: 18–20 px untuk menu dan kontrol, 20–24 px untuk ikon action; desain akhir mengikuti optical size, bukan sekadar width yang sama.
- SVG dekoratif memakai `aria-hidden="true"`; tombol ikon saja harus punya accessible name dan tooltip bila dibutuhkan.
- Ikon normal mewarisi currentColor/text neutral; warna status/destruktif hanya saat bermakna. Hindari inline `fill`/`stroke` warna berbeda per menu item.
- SVG boleh bundled melalui komponen Blade/sprite atau pipeline build; hindari dependency CDN/HTTP per ikon pada runtime jika memungkinkan.

### FR-02 — Dropdown/menu three-dots netral **[MUST]**

**Spesifikasi visual wajib**:

- Permukaan menu **putih**, border netral tipis, shadow halus, radius konsisten.
- Aksi biasa (`Lihat Detail`, `Edit`, `Penilaian`, dll.) menggunakan ikon dan teks **netral**. **Tidak ada** background biru/cyan/orange/hijau/ungu yang berbeda untuk setiap baris/ikon.
- Hover/keyboard-focus menggunakan highlight abu-abu/neutral ringan; bukan banner warna menyala.
- Aksi destruktif (`Hapus`) boleh teks/ikon merah, dipisahkan divider jika struktur menu membutuhkan.
- Spacing per baris konsisten, target klik cukup nyaman, label tidak terpotong pada mobile.
- Pertahankan behavior asli: open/close, click outside, Escape, keyboard/focus, click handlers, izin/disabled, dan konfirmasi aksi destruktif.
- Terapkan aturan ini juga pada menu aksi sejenis (popover/profile/filter) **jika komponen shared yang sama**; jangan merombak flow khusus halaman.

## 8. Komponen dasar — functional & visual requirements

### FR-03 — Button, link, icon button **[MUST]**

- Varian minimal: `primary` biru brand, `secondary` netral/outline, `ghost/tertiary` netral, `destructive` semantic red bila relevan.
- Samakan font Poppins, weight, padding, radius, icon alignment, hover, pressed, disabled, loading/busy.
- Tautan tetap terlihat sebagai tautan dan dibedakan dari tombol aksi; jangan menyamakan semua aksi menjadi tombol primary.
- Button yang memuat indikator busy tetap menjaga ukuran minimum dan label dapat dipahami.
- Jangan mengubah atribut `type`, submit behavior, loading debounce, event listeners atau label fungsi bisnis.

### FR-04 — Form controls **[MUST]**

- Berlaku untuk input, textarea, select, search field, checkbox/radio, dan variasi komponen UI yang sudah ada.
- Samakan border, height, padding, label gap, placeholder, focus, required, invalid, help text, error text, disabled, read-only.
- Error state memiliki pesan teks dan tidak bergantung pada warna border saja.
- Form tetap mendukung autofill, keyboard, validasi native/JS existing, dan aman di viewport 390 px.
- Jangan mengubah field schema, validasi, format input, atau business constraints.

### FR-05 — Card, panel, badge, alert/toast **[MUST]**

- Surface putih, border rendah kontras namun terlihat, shadow minimal dan radius konsisten.
- Card tidak diberi background berwarna hanya untuk memberi identitas; aksen warna dipakai bila memiliki arti yang nyata.
- Badge status **boleh** menggunakan semantic background/subtle text sesuai status; badge kategori biasa netral.
- Toast success/error/warning tetap mudah dikenali dan memiliki teks yang jelas.
- Tidak mengubah timing, queue, event names, atau logic toast UI-0.

### FR-06 — Modal, dialog, popover, menu & overlay **[MUST]**

- Samakan visual surface, spacing header/body/footer, tombol, border, shadow, dan layer/z-index.
- Pastikan isi panjang bisa scroll pada viewport kecil; tombol close tetap jelas; teks tidak terpotong akibat Poppins.
- Pertahankan focus trap, return focus, Escape, backdrop handling, confirm/cancel, dan lifecycle cleanup UI-0.
- Styling menu three-dots memenuhi FR-02.

### FR-07 — Skeleton & loading skin **[MUST]**

- **Tidak** mengubah kebijakan loading per komponen yang telah PASS pada UI-0.
- Sesuaikan warna neutral, radius, spacing, serta placeholder text shape supaya sejalan dengan sistem komponen baru.
- Skeleton tidak boleh menyebabkan layout shift saat data datang, tidak menutupi shell dan tidak mengganti loading section independen dengan full-screen overlay.
- Animasi (jika ada) halus dan dinonaktifkan pada `prefers-reduced-motion`.
- Jangan memaksa shimmer jika skeleton statis lebih stabil.

### FR-08 — Data visualization text/legend skin **[MUST, visual-only]**

- Selaraskan **font family dan typography labels/legend/tooltip** chart dengan Poppins dan warna netral.
- Warna series brand/semantic tetap diperbolehkan untuk membedakan data; pastikan label/legend dapat dimengerti tanpa mengandalkan warna saja.
- Tidak mengubah scoring, dataset, urutan seri, axis scale, chart type, dan layout spesifik chart (UI-3).

### FR-09 — Public & authenticated consistency **[MUST]**

- Landing, login, register, dan authenticated memakai font serta shared token yang sama.
- Layout publik/illustration/gradient yang sudah menjadi identitas **tetap dipertahankan** pada UI-1; refinement susunan responsive/spacing spesifik halaman masuk UI-3.
- Bedakan desain marketing yang boleh lebih ekspresif dari komponen aplikasi operasional yang minimalis; semua tetap terasa satu brand.

### FR-10 — Tidak ada regresi UI-0 **[MUST]**

- Partial navigation `resources/js/workspace.js`, persistent shell, mount/unmount, abort signals, browser Back/Forward, deep links, dan session validation tetap bekerja.
- CSS/DOM/icon tidak boleh memutus listener, selectors, `data-*` hooks, `aria-*`, element IDs, atau elemen fokus yang dipakai UI-0 dan modul halaman.
- Skeleton independen tetap dapat muncul saat data satu section lambat, sementara section lainnya tampil.

## 9. Aturan arsitektur CSS dan integrasi

1. **Audit sebelum edit:** identifikasi struktur import, override, token yang eksis, scoped styles, kelas JS-generated, Blade components, chart options, dan dependensi font/ikon pada checkout lokal setelah UI-0.
2. **Satu canonical token layer:** tetapkan warna, typography, spacing, radius, elevation dalam lokasi terpusat yang dipakai public/dashboard. Hindari membuat token semantik duplikat yang saling bertentangan. Nama internal dapat disesuaikan dengan struktur existing; mapping harus didokumentasikan.
3. **Cascade terkendali:** hindari rentetan selector specificity tinggi dan `!important` sebagai jalan pintas. Jangan menambahkan CSS override akhir global yang diam-diam merusak halaman.
4. **Komponen reusable:** standardisasi komponen existing; jangan membuat library baru besar atau menggandakan JS behavior hanya untuk styling.
5. **Poppins self-hosted via build lebih disukai:** gunakan paket sesuai stack Vite bila cocok; audit `@fontsource/plus-jakarta-sans` yang ada, hapus sumber font lama hanya ketika tidak ada kebutuhan lain. Grafik JS harus ikut terupdate.
6. **CSP dan sanitasi:** SVG tidak boleh disuntik dari input tak terpercaya. CSS/JS baru tidak membuat injection surface.
7. **Build compatibility:** tetap kompatibel dengan Blade, Tailwind/Vite, ApexCharts, dan browser testing yang digunakan checkout.
8. **Jangan mengubah UI-0:** koordinasikan penyesuaian CSS untuk shell tanpa mengganti behavior partial navigation.

## 10. Responsiveness & accessibility requirements

- Minimum audit visual: desktop **1440×900**, tablet **768×1024**, mobile **390×844**; boleh tambah 360 px untuk kasus font wrapping yang terlihat.
- Tidak ada horizontal overflow dokumen akibat Poppins, ikon, modal, button, text label, atau dropdown.
- Text panjang, nama UMKM, dan label aksi mendukung wrapping atau truncation yang memiliki akses ke teks penuh jika relevan.
- **WCAG AA text contrast** ditargetkan: 4.5:1 untuk teks biasa, 3:1 untuk teks besar; kontrol penting punya focus-visible yang jelas. Gunakan contrast checker terhadap pasangan token **aktual**, bukan mengasumsikan semua warna brand lulus untuk semua ukuran teks.
- Interactive targets pada layar sentuh diupayakan minimal **44×44 CSS px** untuk aksi utama; apabila kontrol dense table berbeda, berikan justifikasi dan jangan sampai sulit dipakai.
- Icon-only button memiliki `aria-label`, status visual tidak berbasis warna saja, dan `prefers-reduced-motion` dihormati.
- Perubahan font dan CSS tidak memaksa konten hilang saat font asset gagal dimuat; fallback tetap memadai.

## 11. QA, regresi, dan evidence

### 11.1 Pemeriksaan awal

- Catat commit checkout lokal yang memuat UI-0; screenshot baseline beberapa shared component pada publik dan workspace.
- Inventaris kode: semua font yang dipakai, token warna, icon sources, shared button/dropdown/dialog, inline styles high-impact, serta grafik.
- Konfirmasi baseline hasil handoff UI-0 sebelum mengedit: **10/10 AC UI-0 PASS (dilaporkan), 13 skenario workspace browser, 13 skenario Enam Faktor, JS 33 tes, PHP terarah 164 tes**; lakukan pengujian ulang sesuai lingkungan aktual, jangan mengasumsikan angka ini otomatis berlaku pada checkout baru.
- Lima kegagalan baseline PHP full suite yang dilaporkan UI-0/P2 **bukan PASS**; bandingkan per test case, jangan mengklaim full suite hijau apabila tetap gagal.

### 11.2 Visual evidence minimum

Ambil screenshot sebelum/sesudah (atau side-by-side bila tooling tersedia) pada:

1. Landing, login, register — desktop dan mobile, terutama font/form/button.
2. Workspace Home — desktop dan mobile (KPI, heading, card, sidebar hanya sebagai pembanding).
3. UMKM listing — desktop dan mobile; open three-dots dropdown, hover/focus, action destructive.
4. Form/kuesioner — default, input fokus, invalid state, dialog/modal.
5. Factor Profile, Distribution, Comparison — font label, legend, tooltip visual; chart data tidak berubah.
6. Skeleton Home/UMKM/chart/picker pada kondisi artificial network slow.
7. At least one long-name UMKM + dialog pada mobile untuk membuktikan tidak overflow.

Hasil screenshot harus memberi nama file yang deskriptif dan tidak memuat token/kredensial.

### 11.3 Test matrix minimum

| Area | Skenario |
|---|---|
| Build | `npm run build`, view compilation, console runtime clean |
| JS/unit | Tes JS yang relevan untuk components, icons, session/navigation jika tersedia |
| Auth & routing | Login, logout, cold entry, internal navigation, back/forward, refresh, direct URL, 401; **UI-0 tetap utuh** |
| UI dropdown | Open/close, outside click, Escape, keyboard focus, item action, destructive confirmation; menu netral di semua states |
| Form | Typing, validation, focus, autofill, submission normal; error state |
| Modal/toast | Open/close, focus restoration, pending UI, responsive scroll, z-index |
| Data & chart | Status/legend masih benar; chart/tooltips memakai Poppins; tidak mengubah angka |
| Responsive | 1440, 768, 390 (tambahan 360 jika diperlukan); text wrap, clipping, overflow |
| Accessibility smoke | Keyboard tab, aria labels, visible focus, text contrast, reduced-motion |
| Backend regression | Targeted Auth/ownership/UMKM/penilaian/dashboard + perbandingan baseline full suite |

**Aturan eksekusi:** Gunakan database testing terisolasi untuk regresi/Playwright yang mengubah data; jangan reset database pengguna atau memakai kredensial sensitif yang muncul di log/artefak. Handoff UI-0 mencatat bahwa browser tests dan PHPUnit yang sama-sama menggunakan MySQL `joyumkm_testing` harus dijalankan bergantian.

## 12. Acceptance criteria / quality gates

Semua acceptance criteria berikut wajib **PASS**, dengan bukti aktual dari checkout implementasi.

| ID | Gate | Bukti lulus minimum |
|---|---|---|
| **UI1-AC01** | Brand tokens tetap asli | Source audit menunjukkan primary `#0207CA`, secondary `#0297DE`, accent `#FC734D`; tidak ada brand re-color tak disetujui |
| **UI1-AC02** | Poppins global | Computed font Poppins di landing/auth/workspace, kontrol/form, menu, table dan grafik; fallback berhasil; tidak ada Plus Jakarta Sans tersisa secara tak sengaja pada user-visible text |
| **UI1-AC03** | Typography harmonis | Screenshot cross-device menunjukkan hierarchy jelas, leading nyaman, tidak ada clipping/overlapping setelah font change |
| **UI1-AC04** | Neutral-first & semantic discipline | Audit komponen membuktikan dekorasi warna berlebihan dihilangkan dari default icons/menu/controls; status semantic tetap bermakna |
| **UI1-AC05** | Three-dots dropdown netral | Screenshot open/hover/focus: aksi biasa monochrome, background menu putih, hover neutral; destructive action terpisah dan semantik; behavior bekerja |
| **UI1-AC06** | SVG icon consistency | Inventaris ikon/glyph konsisten, ukuran/alignment, aria name, dan tidak terjadi icon missing pada rute utama |
| **UI1-AC07** | Shared primitives konsisten | Button/input/select/card/badge/dialog/toast dan states utama menggunakan tokens yang konsisten; semua interaksi semula tetap berfungsi |
| **UI1-AC08** | Responsive & accessibility smoke | 1440/768/390 tanpa overflow baru; keyboard/focus; kontras target AA untuk teks penting; reduced motion dihormati |
| **UI1-AC09** | CSS architecture terkendali | Token layer canonical terdokumentasi; tidak ada duplicate design systems, override acak, atau dependency remote font/icon runtime yang tidak jelas |
| **UI1-AC10** | UI-0 preserved | Internal partial navigation tanpa full-screen session spinner; sidebar/topbar tetap instance yang sama; skeleton lokal, abort, retry, auth/back-forward tetap PASS |
| **UI1-AC11** | Functional regression | Build, JS/browser, PHP targeted PASS; PHP full suite dibanding baseline per-case, nol regresi baru yang disebabkan UI-1 |
| **UI1-AC12** | Handoff & visual evidence | Screenshots before/after, audit colors/fonts/icons, test matrix, known gaps, dan keputusan implementasi diserahkan lengkap |

**Verdict rules:**
- `PASS` hanya bila evidence menunjang kriteria.
- `PARTIAL` bila ada komponen/viewport/gate terlewat.
- `BLOCKED` bila environment atau data tidak memungkinkan verifikasi, disertai alasannya.
- `FAIL` bila ada regresi atau requirement tidak terpenuhi.
- Jangan menyebut seluruh suite PHP PASS jika baseline masih memiliki lima kasus lama.

## 13. Urutan implementasi yang direkomendasikan

1. **UI1-01 — Audit & snapshot baseline:** inspeksi checkout UI-0 terbaru, CSS/tokens/font/icons, screenshot baseline, tes dasar.
2. **UI1-02 — Tokens & Poppins:** canonical tokens, font package/import, komponen typography, chart typography.
3. **UI1-03 — Icon normalization:** pilih sistem SVG dan konsolidasi glyph/Blade/JS; pastikan functional hooks tidak berubah.
4. **UI1-04 — Shared component visual pass:** button, form, card, badges, dropdown three-dots, modal, alert/toast, skeleton skin.
5. **UI1-05 — Integration & QA:** public/auth consistency, viewport/focus/contrast/reduced-motion, regress UI-0, screenshots dan handoff.

**Ketergantungan:** UI-1 dimulai dari checkout UI-0 yang lulus. UI-2 boleh dimulai setelah token/komponen dasar UI-1 PASS. Jangan menjalankan UI-2/3 secara terselubung di tengah UI-1.

## 14. Deliverables wajib

- Code changes di checkout lokal pada shared CSS/Blade/JS/components sesuai kebutuhan yang terverifikasi; **tanpa commit/push/deploy otomatis kecuali diperintahkan**.
- Dokumentasi token final, mapping legacy tokens, sistem SVG yang dipilih dan sumber lisensinya, font loading/fallback.
- Daftar changed files dan ringkasan alasan perubahan.
- Screenshot before/after desktop/tablet/mobile untuk public, authenticated, dropdown, forms, chart, dan loading.
- Test report dengan commands, counts, pass/fail per gate, baseline regressions, limitations.
- Handoff yang menyatakan secara eksplisit batas: **UI-2 sidebar/nav/card-list dan UI-3 page-level layout belum dikerjakan**.

### Suggested evidence structure (boleh disesuaikan dengan struktur lokal)

```text
docs/evidence/ui-1/
├── baseline/
├── final/
│   ├── public/
│   ├── authenticated/
│   ├── components/
│   └── loading/
├── token-icon-font-audit.md
├── accessibility-smoke.md
├── visual-comparison.md
└── verification-report.md
```

## 15. Risiko dan mitigasi

| Risiko | Mitigasi |
|---|---|
| Poppins lebih lebar menyebabkan overflow | Recheck computed fonts, long text, mobile, modal, table, chart labels sebelum PASS |
| Styling global memutus UI-0 routing/focus | Hindari perubahan DOM hooks; jalankan browser regression UI-0 lengkap |
| Mencampur Material Symbols, ikon lama, dan emoji | Pilih sistem ikon canonical dan audit seluruh usages; konsistenkan secara visual |
| Semantic colors dihilangkan demi netral | Pertahankan indikator status/destructive dengan teks/ikon dan kontras yang baik |
| Warna cyan/orange sulit dibaca di white | Jangan dipakai sebagai teks biasa tanpa kontras cukup; gunakan sebagai accent/visual data |
| CSS `dashboard.css` menimpa `public.css` atau sebaliknya | Canonical tokens dan source-order audit, hindari override global tanpa scope |
| Visual refinement melebar menjadi rewrite | Tegakkan batas UI-1 dan daftar non-goals, defer permintaan UI-2/UI-3 |
| Tes browser dan PHPUnit mengganggu database yang sama | Gunakan DB testing terisolasi dan jalankan serial seperti handoff UI-0 |
| Remote `main` tertinggal dari checkout lokal | Audit kerja pada checkout yang berisi UI-0 dan fitur terbaru; jangan melakukan reset/rebase paksa |

## 16. Definisi selesai (Definition of Done)

UI-1 hanya dapat dinyatakan **PASS** apabila:

1. `UI1-AC01` sampai `UI1-AC12` seluruhnya PASS.
2. **Poppins berlaku menyeluruh**; warna merek asli tetap; komponen operasional terutama three-dots menu benar-benar netral.
3. Tidak ada regresi navigasi parsial, sesi, skeleton per komponen, aksi CRUD, dan alur penilaian.
4. Screenshot dan pengujian cross-viewport telah dilakukan, termasuk state interactive dan accessibility smoke.
5. Temuan residual/baseline existing dilaporkan apa adanya, dan UI-2/UI-3 tidak diklaim telah selesai.

---

### Catatan khusus untuk implementor

PRD ini **tidak menginstruksikan untuk mengubah model scoring, query database, request API, nama route, atau status evaluasi**. Tugas ini adalah **design-system foundation**. Jika sebuah perubahan visual ternyata menuntut perubahan perilaku yang signifikan, dokumentasikan sebagai dependency UI-2/UI-3 atau bug terpisah alih-alih melakukan perluasan scope diam-diam.

**Dokumen acuan terkait:** `PRD_JoyUMKM_UI-0_Navigation_Loading_Architecture.md`, handoff UI-0 dari pengguna (20 November 2026), audit screenshot JoyUMKM, dan seluruh keputusan desain UI JoyUMKM V1 yang telah disepakati pada percakapan ini.
