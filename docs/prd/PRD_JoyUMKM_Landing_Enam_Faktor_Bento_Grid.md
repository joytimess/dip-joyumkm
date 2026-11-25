# PRD — JoyUMKM | Landing Page Enam Faktor (Bento Grid)

**Versi:** 1.0 · **Tanggal:** 25 November 2026  
**Status:** desain visual disetujui; siap implementasi  
**Prioritas:** P0 (satu scope UI)  
**Repository:** [joytimess/dip-joyumkm](https://github.com/joytimess/dip-joyumkm)  
**Baseline:** branch `main` yang dibaca saat pembuatan prototipe; verifikasi ulang HEAD sebelum perubahan.  
**Referensi visual utama:** `joyumkm-enam-faktor.html` (prototipe yang disetujui), `joyumkm-enam-faktor-preview.png`, `joyumkm-enam-faktor-mobile.png`.

## 1. Latar belakang dan tujuan

Landing page publik saat ini memperkenalkan alur pendampingan dan Tiga Dimensi (INS, ECT, OPS), tetapi belum memperkenalkan Penilaian Enam Faktor sebagai fitur tersendiri. Tambahkan satu section informatif agar pengunjung memahami enam aspek yang dinilai sebelum membuat akun atau menggunakan fitur penilaian. Desain harus menyatu dengan gaya gradient editorial yang sudah ada, bukan mendesain ulang landing page.

**User story:** Sebagai calon pendamping UMKM, saya ingin memahami enam faktor penilaian secara ringkas dan visual agar mengetahui aspek usaha yang dapat dievaluasi melalui JoyUMKM.

**Hasil yang diharapkan:** satu section publik berisi enam kartu yang sesuai prototipe, terintegrasi ke Laravel Blade, responsif, aksesibel, dan tidak mengubah perilaku fitur eksisting.

## 2. Sumber kebenaran dan urutan prioritas

| Sumber | Fungsi |
|---|---|
| `joyumkm-enam-faktor.html` | Referensi utama layout, ilustrasi SVG, konten, warna kartu, dan breakpoint baru. |
| [`resources/views/welcome.blade.php`](https://github.com/joytimess/dip-joyumkm/blob/main/resources/views/welcome.blade.php) | Struktur halaman dan pola markup editorial eksisting. |
| `resources/css/public.css` | Styling publik, container, heading, kartu, responsive dan motion. |
| `resources/css/tokens.css` | Token warna, tipografi, dan spacing global. |
| `resources/css/app.css`, `resources/views/layouts/public.blade.php` | Import Poppins, pipeline Vite, layout publik. |
| `app/Models/SixFactorEvaluation.php` (`FACTORS`) | Nama, key, dan urutan faktor yang sah. |
| `resources/js/six-factor-draft.js` | Label faktor berbahasa Indonesia. |

Apabila CSS prototipe mandiri bertabrakan dengan CSS repository, pertahankan perilaku halaman eksisting dan adaptasikan selector khusus section baru agar hasil visual tetap sesuai prototipe. Jangan menyalin global reset, `<html>`, `<head>`, `<body>`, `<main>`, maupun stylesheet mandiri dari prototipe ke dalam Blade.

## 3. Scope

### In scope

- Menambahkan section Enam Faktor pada landing page publik `welcome.blade.php`.
- Enam kartu informatif dengan asymmetric bento grid, ilustrasi SVG dekoratif, copy, warna dan hierarki visual yang disetujui.
- Styling terisolasi melalui stylesheet publik yang sudah diimpor oleh Vite.
- Responsiveness desktop, tablet, mobile; dukungan zoom dan reduced motion.
- Verifikasi visual landing page lengkap, build frontend, dan regresi yang relevan.

### Out of scope

- Perubahan pada Hero, Cara Kerja, Tiga Dimensi, CTA, footer, dan layout autentikasi selain penyesuaian integrasi yang benar-benar diperlukan.
- Redesign header atau penambahan menu navigasi (baseline PRD mempertahankan navigasi saat ini; tautan Enam Faktor tidak menjadi requirement).
- Perubahan kuesioner, scoring, mapping Enam Faktor terhadap Tiga Dimensi, dashboard, API, model, database, migration, route, authentication, atau authorization.
- Hermes, intelligence reporting, email, webhook, deployment production.
- Library UI/icon/animation baru, CDN eksternal, request runtime untuk konten kartu, carousel, tab, modal, CTA di setiap kartu atau interaksi klik semu.

## 4. Product requirements

### FR-01 — Struktur dan posisi section

Section dirender satu kali sebagai bagian dari halaman publik, sesudah `#dimensi` dan sebelum `.cta-section`.

```text
Header / Navigation
Hero
Cara Kerja (#cara-kerja)
Tiga Dimensi (#dimensi)
Enam Faktor (#enam-faktor)  ← BARU
CTA
Footer
```

Struktur semantik yang disarankan:

```html
<section id="enam-faktor"
         class="section editorial-section factors-section"
         aria-labelledby="factors-heading">
    <div class="container">
        <!-- Editorial heading: kicker, h2, intro -->
        <div class="editorial-grid factor-grid">
            <!-- Enam article: index, SVG, overline, h3, deskripsi -->
        </div>
        <!-- Editorial endnote -->
    </div>
</section>
```

Kelas `editorial-grid` boleh dipakai kembali bila override khusus faktor cukup kuat; kelas tersebut tidak wajib jika menimbulkan konflik cascade. ID unik, satu heading h2 untuk section, enam heading h3 untuk kartu. Kartu tidak memiliki target navigasi dan bukan tombol.

### FR-02 — Heading dan microcopy

- Kicker: `Enam faktor penilaian` + divider + `01 — 06`.
- Heading: `Kenali usaha dari` / `enam faktor utama.` (baris kedua menggunakan warna primary).
- Intro: `Pahami kondisi UMKM melalui enam faktor penilaian yang membantu pendamping melihat kekuatan, tantangan, dan area yang perlu dikembangkan.`
- Endnote: `Enam faktor membantu pendamping memahami kondisi usaha dan menentukan fokus evaluasi berikutnya.`

Gunakan bahasa Indonesia dan gaya editorial eksisting. Pada layar sempit, teks boleh membungkus alami agar tidak overflow.

### FR-03 — Konten enam faktor

Pertahankan urutan DOM dan tampilan berikut. Indeks berupa `01 / FAKTOR` sampai `06 / FAKTOR`; titik setelah judul mengikuti gaya landing page. Key hanya referensi domain, bukan field form baru.

| No. | Key | Judul | Overline | Deskripsi |
|---|---|---|---|---|
| 01 | `sumber_daya` | Sumber Daya | KAPASITAS INTERNAL USAHA | Melihat kesiapan sumber daya manusia, modal, peralatan, dan kemampuan yang dimiliki usaha untuk menjalankan kegiatan bisnis serta mendukung pengembangannya. |
| 02 | `kepemimpinan` | Kepemimpinan | ARAH & KEPUTUSAN | Melihat kemampuan mengarahkan, mengambil keputusan, dan membimbing usaha. |
| 03 | `lingkungan_kerja` | Lingkungan Kerja | KONDISI KERJA | Memahami suasana, kenyamanan, dan kondisi kerja sehari-hari. |
| 04 | `nilai_organisasi` | Nilai Organisasi | PRINSIP & BUDAYA | Melihat prinsip, kebiasaan, dan nilai yang membentuk cara usaha dijalankan. |
| 05 | `stabilitas` | Stabilitas | KETAHANAN USAHA | Menilai konsistensi dan kemampuan usaha menjaga kelangsungan kegiatan bisnis. |
| 06 | `kinerja` | Kinerja | HASIL & PENCAPAIAN | Memahami hasil kegiatan usaha dan pencapaian terhadap tujuan yang ditetapkan. |

Copy adalah pengantar fitur sebagaimana prototipe, bukan definisi klinis/finansial atau perubahan instrumen penilaian. Jangan menambah nilai ilustratif, bobot, formula, klaim validasi, atau menyatakan bahwa Sumber Daya memiliki bobot lebih besar. Ukuran kartu utama hanya untuk komposisi visual.

### FR-04 — Komposisi Bento Grid

**Desktop (> 960 px):** tiga kolom sama lebar, kartu 01 span dua kolom dan dua baris; kartu 02 dan 03 bertumpuk di kolom kanan; kartu 04–06 satu baris dengan ukuran setara.

```text
┌───────────────────────────────┬───────────────┐
│                               │ 02            │
│                               │ Kepemimpinan  │
│ 01 Sumber Daya                 ├───────────────┤
│ Media utama + deskripsi panjang│ 03            │
│                               │ Lingk. Kerja  │
├───────────────┬───────────────┼───────────────┤
│ 04            │ 05            │ 06            │
│ Nilai Org.    │ Stabilitas    │ Kinerja       │
└───────────────┴───────────────┴───────────────┘
```

Gunakan CSS Grid, `repeat(3, minmax(0, 1fr))` dan area bernama; jangan memakai absolute positioning untuk menempatkan keseluruhan kartu. Tinggi baris adalah minimum yang dapat bertambah sesuai isi; jangan memotong deskripsi demi tinggi tetap.

```css
grid-template-areas:
  "resources resources leadership"
  "resources resources workplace"
  "values    stability performance";
```

Pada desktop, kartu 01 memakai media besar di tengah dan copy di bagian bawah; kartu 02/03 memakai media di sisi kanan bawah dengan ruang yang cukup untuk teks; kartu 04–06 memakai media di bagian tengah dan copy bawah. Jangan menambahkan round-link yang terdapat pada section eksisting karena kartu ini bersifat informatif.

### FR-05 — Responsive layout

| Viewport | Tata letak | Detail |
|---|---|---|
| >1060 px | 3 kolom bento | Gap 19 px; padding kartu umum 26 px, compact 23 px; radius 23 px; minimum baris 202/202/285 px. |
| 961–1060 px | 3 kolom bento | Gap 14 px; padding 20 px; radius 21 px; baris terakhir min. 270 px; ilustrasi compact diperkecil. |
| 521–960 px | 2 kolom | Kartu 01 full width, 02/03 sejajar, 04/05 sejajar, 06 full width; heading bertumpuk; ilustrasi 06 berada di kanan. |
| ≤520 px | 1 kolom | Urutan 01–06; gap 14 px; kartu utama min. 385 px; kartu lainnya min. 205 px; media compact di kanan, teks di kiri; tinggi dapat bertambah. |
| ≤374 px | 1 kolom sempit | Padding 19 px; media compact sekitar 82 px; ruang teks disesuaikan; tidak ada overflow atau clipping. |

Breakpoint tablet:

```css
grid-template-areas:
  "resources resources"
  "leadership workplace"
  "values stability"
  "performance performance";
```

Container mengikuti `public.css`: maksimum 1200 px; lebar desktop `calc(100% - 64px)`, tablet sesuai container eksisting (maksimum 760 px), mobile dengan gutter 18 px atau 16 px pada layar sangat sempit. Jika ada perbedaan breakpoint global dan prototipe, jangan override `.container` global; koreksi layout lokal seperlunya. Uji khusus pada 521, 960 dan 961 px, karena peralihan kolom dapat mempersempit area copy. Pada zoom 200%, konten harus tetap dapat dibaca, mengikuti breakpoint reflow, tanpa scrolling horizontal halaman.

## 5. Design system dan ilustrasi

### 5.1 Token dan gaya visual

| Elemen | Spesifikasi |
|---|---|
| Primary | `var(--brand-primary)` / `#0207CA` |
| Secondary | `var(--brand-secondary)` / `#0297DE` |
| Accent | `var(--brand-accent)` / `#FC734D` |
| Teks utama | `var(--text-primary)` / `#101E40` |
| Teks sekunder | `var(--text-muted)` / `#566781` |
| Background section | `#F6F8FE`, konsisten dengan workflow editorial |
| Tipografi | Poppins 400/500/600/700 dari import Fontsource eksisting; tanpa Google Fonts CDN prototipe |
| Heading | Editorial existing: desktop `clamp(36px, 3.8vw, 58px)`, line-height 1.1; mobile mengikuti heading publik |
| Judul kartu utama | `clamp(32px, 3.5vw, 49px)` |
| Judul kartu lain | Sekitar 21–31 px, sesuai variasi ukuran kartu |
| Deskripsi | 14 px utama, sekitar 12–13 px lainnya; line-height sesuai prototipe |
| Spacing section | Desktop 108 px atas / 100 px bawah; tablet 85 px; mobile 67 px |
| Radius kartu | 23 px desktop dan mobile; 21 px pada desktop sempit/tablet sesuai prototipe |
| Shadow | `0 12px 34px rgba(12,23,78,.075)` |
| Detail | Index pill, overline uppercase, sparkle dekoratif, grain halus |

Gunakan token eksisting jika tersedia; nilai gradient spesifik kartu merupakan perluasan lokal dari gaya editorial, bukan token global baru. Referensi persis untuk gradient dan SVG adalah HTML yang disetujui.

### 5.2 Arah visual masing-masing kartu

| Faktor | Tone | Ilustrasi |
|---|---|---|
| Sumber Daya | Royal blue gelap dengan radial glow biru | Komposisi kartu/glassmorphism: manusia, aset, dan grafik |
| Kepemimpinan | Peach/coral gradient | Lencana bintang dan tanda centang |
| Lingkungan Kerja | Sky-blue gradient | Bangunan/tempat kerja dengan tanda centang |
| Nilai Organisasi | Light cyan gradient | Permata geometris dengan bintang |
| Stabilitas | Deep blue gradient | Perisai dan tanda centang |
| Kinerja | Royal-blue gradient dan glow | Grafik pertumbuhan/bar chart |

Porting ilustrasi inline SVG dari prototipe secara utuh. Jangan mengganti dengan emoji, stock photo, placeholder, atau library eksternal. Gunakan `viewBox`, ukuran responsif, dan prefix unik seperti `joy-factor-` pada ID gradient/defs beserta semua referensi `url(#...)` agar tidak bertabrakan dengan SVG lain di halaman. Ilustrasi, grain, dan sparkle dekoratif `aria-hidden="true"`, tidak focusable, tidak menangkap pointer event.

### 5.3 Motion dan aksesibilitas

- Hover pada perangkat dengan hover capability: kartu bergerak sekitar -4 px, shadow menguat, ilustrasi bergerak -4 px dan scale 1.025. Durasi/kurva mengikuti prototipe dan gaya editorial eksisting.
- `prefers-reduced-motion: reduce` menonaktifkan transform, transition, dan animasi section. Tidak ada auto-animation yang wajib atau efek yang menggeser layout.
- Jangan memakai cursor pointer pada kartu noninteraktif atau menambah `tabindex`/role button tanpa aksi.
- Teks judul, deskripsi dan label harus memenuhi WCAG AA (4.5:1 teks biasa, 3:1 teks besar) terhadap gradient pada area teks. Jika gradient prototipe tidak memenuhi, lakukan koreksi tonal minimal di area copy dengan tetap menjaga palet dan arah visual; catat delta dalam handoff.
- Deskripsi tidak boleh tertutup ilustrasi, terpotong, disingkat dengan ellipsis, atau disembunyikan pada viewport kecil. Jangan bergantung pada warna atau simbol untuk memahami faktor.
- Pastikan alur heading/reading order sesuai urutan 01–06, dan elemen navigasi lama tetap keyboard-accessible.

## 6. Spesifikasi teknis implementasi Laravel

### 6.1 File yang diperkirakan berubah

| File | Perubahan |
|---|---|
| `resources/views/welcome.blade.php` | Menyisipkan section (atau include partial) di lokasi yang ditentukan. |
| `resources/views/components/landing-six-factors.blade.php` (opsional) | Partial/component markup khusus apabila meningkatkan maintainability. Jika tidak diperlukan, gunakan markup inline di welcome. |
| `resources/css/public.css` | Menambah CSS khusus `.factors-section`/`.factor-grid`/`.factor-card`, beserta responsive override lokal. |
| Test browser/feature yang relevan | Menambah verifikasi keberadaan section dan urutan kartu; gunakan pola testing existing. |
| `docs/mockup/joyumkm-enam-faktor.html` (opsional) | Menyimpan salinan referensi yang disetujui jika tersedia di workspace. |

Jangan mengubah file lain tanpa kebutuhan teknis yang dapat dijelaskan. Tidak perlu migration, perubahan `.env`, controller, route, build config, package baru, atau JS runtime baru.

### 6.2 Integrasi dan isolasi CSS

1. Baca ulang `welcome.blade.php`, layout publik, `public.css`, `tokens.css`, dan `app.css` dari branch kerja. Cocokkan enam key dengan model existing.
2. Port hanya isi `<section id="enam-faktor">` dan SVG dari HTML. Jika memakai component, render sekali dari `welcome.blade.php`.
3. Reuse markup heading, class editorial, font, container, CTA, dan token global yang sudah ada. Scope rule baru pada `.public-body .factors-section ...` dan gunakan kelas faktor yang eksplisit.
4. Audit cascade existing secara khusus: `.editorial-grid .editorial-card` memberi min-height 426 px, padding, radius; `.editorial-card .card-copy` memakai padding-top 190–200 px serta padding-bottom 42 px; `.editorial-card p` membatasi max-width; beberapa media query mengubah last-child odd, art, hover dan tinggi kartu. Pastikan rule tersebut tidak mengganggu ukuran compact atau posisi SVG baru. Atur override lokal `min-height`, `padding`, `max-width`, grid, dan posisi media jika perlu. Jangan mengubah rule global untuk mengatasi konflik lokal.
5. Hindari menyalin reset `*`, font import eksternal, `<style>` besar, dan selector generik dari prototype. Gunakan stylesheet publik yang telah diimpor lewat `@vite`.
6. Section harus SSR melalui Blade dan tetap tampil dengan JavaScript dinonaktifkan. Tidak mengambil data UMKM privat atau menambah query DB; tidak membutuhkan sesi autentikasi.
7. Jalankan build production melalui skrip repo (`npm run build` jika terkonfirmasi pada `package.json`), bukan sekadar membuka file HTML. Uji halaman Laravel yang benar-benar dirender.

### 6.3 Non-functional requirements

- Tidak ada request pihak ketiga baru, dependency tambahan, API call atau fetch dari section.
- Tidak ada page error, console error, SVG ID collision, broken asset, atau layout shift yang berasal dari section baru.
- Tidak mengubah perilaku login/register, anchor Cara Kerja/Tiga Dimensi, CTA, maupun halaman dashboard/auth.
- Perubahan source terfokus, readable, dan mengikuti convention Laravel/Vite existing. Hindari refactor tidak terkait.

## 7. Acceptance criteria dan test plan

| Gate | Kriteria PASS | Evidence |
|---|---|---|
| EF-01 Struktur | GET landing publik sukses; section muncul sekali setelah Tiga Dimensi dan sebelum CTA. Enam article, h2/h3 semantik, ID unik. | Feature test atau DOM assertion pada halaman Laravel. |
| EF-02 Konten | Enam judul, urutan, indeks, overline, deskripsi, heading dan endnote sesuai FR-02/03. Tidak ada faktor tambahan/duplikat. | DOM assertion dan review. |
| EF-03 Desktop | Pada 1440, 1280, 1024 dan 961 px susunan 2×2 utama + dua compact + tiga bottom sesuai prototype; tidak ada tumpang tindih teks/media. | Screenshot dan pemeriksaan bounding box. |
| EF-04 Tablet | Pada 960, 768 dan 521 px susunan 2 kolom sesuai FR-05; kartu 01/06 full width, semua teks terbaca. | Screenshot dan pemeriksaan layout. |
| EF-05 Mobile | Pada 520, 390 dan 320 px enam kartu dalam satu kolom urut; semua konten utuh. | Screenshot, assertion overflow. |
| EF-06 Design parity | Poppins aktual termuat; palet, gradient, SVG, grain, index, radius, spacing, motion sesuai prototipe dan editorial existing. | Computed styles/font check, screenshot pembanding. |
| EF-07 Accessibility | Kontras AA pada copy, landmark/heading benar, SVG dekoratif tidak diumumkan, tidak ada tab stop palsu, zoom 200% dan reduced motion lulus. | Manual/automated accessibility checks sesuai tooling. |
| EF-08 Regresi | Hero, alur, dimensi, CTA, header/footer, login/register tetap normal; tidak ada perubahan visual tak disengaja. | Baseline dan after screenshot landing; smoke auth/CTA. |
| EF-09 Build | Build Vite dan test terarah yang relevan lulus; browser tanpa error/asset rusak akibat perubahan. | Log command, hasil test dan browser. |
| EF-10 Scope | Tidak ada perubahan schema, API, route, scoring, Hermes, dependency, atau konfigurasi production. | Git diff dan daftar file. |

**Aturan pengujian:**

- Ambil baseline landing page sebelum edit pada 1440, 768, 390, dan 320 px. Setelah implementasi, ambil screenshot full-page pada ukuran tersebut serta screenshot section terpisah. Tambahkan bukti pada breakpoint transisi 961/960 dan 521/520 px. Uji setidaknya Chromium (Playwright jika tersedia); gunakan browser lain bila tooling existing mendukung.
- Verifikasi `document.documentElement.scrollWidth <= window.innerWidth` pada semua viewport. Periksa pula scrollWidth dan bounding box setiap kartu, bukan hanya overflow halaman yang mungkin tersembunyi oleh `overflow:hidden`.
- Verifikasi heading dan enam card-copy tidak tertutup SVG; media dekoratif boleh terpotong secara estetis bila tidak menghilangkan makna/isi teks.
- Cek font aktual dengan `document.fonts.ready`, pastikan font Poppins berhasil dimuat sebelum screenshot final.
- Bandingkan DOM, screenshot dan interaksi section eksisting dengan baseline. Uji route CTA/register/login tanpa mengubah data akun.
- Jika ada kegagalan baseline, dokumentasikan sebelum/sesudah dan bedakan dari regresi baru. Test suite database penuh tidak diwajibkan untuk scope UI statis; gunakan environment test yang tersedia untuk feature test terkait tanpa menyentuh production. Jangan menyatakan PASS untuk pengujian yang tidak dijalankan.

## 8. Urutan pekerjaan

| Task | Pekerjaan | Output |
|---|---|---|
| P0-01 | Audit source HEAD, prototipe dan cascade; ambil baseline | Daftar file, baseline visual, identifikasi conflict CSS |
| P0-02 | Integrasikan markup Blade dan ilustrasi SVG | Section publik SSR, enam faktor sesuai copy |
| P0-03 | Implementasikan CSS bento dan breakpoint terisolasi | Parity visual desktop/tablet/mobile |
| P0-04 | Audit a11y, font, motion, overflow dan contrast | Perbaikan lokal + evidence |
| P0-05 | Build, browser QA dan regresi, review diff | Gate matrix + bukti dan handoff |

Selesaikan pekerjaan sebagai satu scope UI. Tidak perlu phase lanjutan atau perubahan arsitektur. Jangan melakukan deploy/push/commit tanpa instruksi yang sesuai workflow repository.

## 9. Definition of Done dan handoff

Deliverable: perubahan source Laravel yang dapat dijalankan, hasil build, bukti screenshot, dan laporan implementasi (disarankan `docs/audit/LANDING_ENAM_FAKTOR_FINAL_COMPLETION.md`).

Handoff minimal berisi:

- Verdict keseluruhan `PASS`, `PARTIAL`, atau `FAIL` disertai status EF-01–EF-10.
- Ringkasan implementasi dan file yang berubah.
- Baseline HEAD/branch, command build/test, hasil dan keterbatasan pengujian.
- Bukti screenshot desktop/tablet/mobile dan breakpoint kritis, hasil cek font/overflow/aksesibilitas.
- Delta terhadap prototype (jika ada), alasan dan hasil pemeriksaan regresi.
- Konfirmasi tidak ada perubahan backend/domain, dependency, atau deployment.

**PASS** hanya jika seluruh acceptance criteria wajib terverifikasi dan tidak ada regresi baru. **PARTIAL** jika implementasi tersedia tetapi gate belum terverifikasi atau ada blocker pengujian. **FAIL** jika requirement wajib gagal atau ditemukan regresi yang belum diperbaiki.

## 10. Keputusan dan batasan baseline PRD

- Desain yang disetujui adalah section-only HTML sebelumnya; posisi setelah Tiga Dimensi, pemilihan Sumber Daya sebagai kartu utama, copy, ilustrasi dan palet diteruskan tanpa eksplorasi desain ulang.
- PRD membatasi perubahan pada satu section; header tetap sama. Pemisahan Blade menjadi partial adalah pilihan teknis, bukan perubahan produk.
- Screenshot prototipe terdahulu diuji dengan font fallback; hasil Laravel wajib diverifikasi memakai Poppins aktual. Lolos overflow pada prototipe bukan bukti lulus implementasi Laravel.
- File HTML referensi harus tersedia untuk implementor di workspace. Jika belum tersedia, salin file yang telah disetujui; jangan merekonstruksi ilustrasi secara bebas dari wireframe saja.
- PRD ini bukan bukti bahwa perubahan sudah diterapkan atau diuji pada Laravel. Repository belum dimodifikasi dalam penyusunan PRD.
