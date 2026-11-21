# PRD JoyUMKM V1 — UI-2: Workspace & Responsive Navigation

**Document ID:** `PRD-JOYUMKM-UI-2`  
**Tanggal:** 20 November 2026  
**Status:** **APPROVED DESIGN BASELINE — READY FOR IMPLEMENTATION** (dokumen spesifikasi; UI-2 belum dieksekusi)  
**Prioritas:** Setelah UI-0 dan UI-1  
**Repository:** https://github.com/joytimess/dip-joyumkm  
**Roadmap:** UI-0 Navigation & Loading Architecture (**PASS lokal**) → UI-1 Design System Foundation (**PASS lokal**) → **UI-2 Workspace & Responsive Navigation** → UI-3 Page-Level Visual Refinement → UI-4 Visual QA & Regression.

> **Source of truth:** keputusan pengguna dalam diskusi refinement JoyUMKM, audit screenshot sebelumnya, handoff lokal UI-0 dan UI-1 yang diberikan pengguna. Handoff UI-1 menyatakan 12/12 acceptance criteria PASS, 33 tes JS PASS, regresi browser workspace 13/13 dan Enam Faktor 13/13, PHP terarah 164 PASS, serta PHP penuh 197/202 dengan lima kegagalan seeder baseline yang sama. Angka-angka tersebut adalah **klaim handoff lokal, bukan verifikasi independen dokumen ini**. GitHub `main` yang ditinjau sebelumnya merupakan baseline lama (commit `804738b0a39f9dc2658e40e92e796077d131aca6`, 4 November 2026). **Jangan** menganggapnya identik dengan checkout lokal yang sudah menerapkan UI-0/UI-1; implementor wajib mengaudit checkout aktual sebelum mengubah apa pun.

---

## 1. Ringkasan eksekutif

UI-0 menyelesaikan perpindahan internal workspace tanpa full-screen spinner menggunakan **navigasi parsial Blade dan persistent application shell**. UI-1 menstandarkan **Poppins, token visual, SVG, komponen, menu three-dots netral, dan skeleton**. UI-2 melanjutkan fondasi tersebut dengan memperbaiki **arsitektur informasi navigasi, sidebar, topbar, pencarian, tata kelola daftar UMKM desktop/mobile, filter, serta konteks navigasi**.

Tujuan produk: workspace JoyUMKM terasa seperti **Minimalist Professional SaaS Dashboard**: profesional, ringan, mudah dipindai, mudah bernavigasi, dan tetap mempertahankan interaksi serta hak akses yang sudah ada. Tidak membuat fitur bisnis, API baru, route fiktif, atau rewrite SPA.

### Hasil wajib

1. Sidebar desktop berubah menjadi **Midnight Navy `#101E40`**, item aktif **Electric Blue `#0207CA`**, dengan ikon SVG konsisten dan kontras memadai.
2. Menu dikelompokkan jelas: **Beranda / Manajemen / Penilaian / Analitik**, dengan label berbahasa Indonesia dan semua tautan menuju route atau entry point yang nyata.
3. Topbar lebih ringkas; global search mobile tidak lagi berupa input sempit/terpotong, melainkan panel/overlay pencarian yang dapat dibuka dan ditutup.
4. Desktop menampilkan **tabel UMKM**; mobile menampilkan **card list UMKM** dengan status, skor, identitas, dan aksi penting tanpa horizontal-scroll tabel sebagai pola utama.
5. Filter mobile diringkas dalam panel/**bottom sheet**; pencarian, filter, sort, pagination, dan URL state sinkron pada seluruh presentasi.
6. Breadcrumb dan navigasi kontekstual membantu orientasi dari daftar UMKM ke detail, evaluasi, dan hasil, tanpa membuat jalur navigasi palsu.
7. Semua perubahan mempertahankan UI-0, UI-1, autentikasi Sanctum, ownership, operasi bisnis, aksesibilitas, dan fallback browser.

---

## 2. Konteks dan keputusan terkunci

| Topik | Keputusan final | Status untuk UI-2 |
|---|---|---|
| Identitas merek | Primary `#0207CA`, secondary `#0297DE`, accent `#FC734D`, tetap asli | Gunakan token UI-1; tidak ubah warna merek |
| Gaya visual | Minimalist Professional SaaS, neutral-first, tidak warna-warni | Mengikat seluruh komponen workspace |
| Tipografi | **Poppins global** | Gunakan implementasi UI-1, jangan buat font stack lain |
| Ikon | SVG konsisten/sprite lokal dari UI-1 | Reuse; jangan tambah library ikon paralel |
| Dropdown three-dots | Menu netral; aksi destruktif merah | Reuse UI-1 pada tabel/card |
| Sidebar | **Midnight Navy `#101E40`**, aktif **`#0207CA`** | Implementasi di UI-2 |
| Navigasi | Grup **Beranda / Manajemen / Penilaian / Analitik** | Implementasi di UI-2 |
| Daftar UMKM | **Desktop tabel**, **mobile card list** | Implementasi di UI-2 |
| Halaman publik | Pertahankan layout, refine visual | Bukan scope UI-2; lanjut UI-3 |
| Loading/navigasi | Partial Blade navigation, persistent shell, per-component skeleton | **Harus dipertahankan** dari UI-0 |
| Layout per halaman | Refine KPI, charts, radar, slider, landing/auth | **UI-3** |

### 2.1 Persetujuan dan keputusan pelengkap

- **Sudah disepakati eksplisit:** warna sidebar navy, grup menu, mobile card/desktop tabel, topbar/search mobile harus usable, dan design system UI-1 dipakai ulang.
- **Rekomendasi teknis UI-2 yang dirumuskan menjadi baseline implementasi:** tablet 768 px memakai card list agar data tetap dapat diakses tanpa horizontal scrolling tabel; filter mobile memakai bottom sheet/panel; breadcrumb disederhanakan pada layar sempit. Titik breakpoint tepat dapat disesuaikan menurut bukti QA, tetapi **390 px wajib card dan 1440 px wajib tabel**.
- **Tidak ada persetujuan menambah route baru** hanya untuk memuaskan struktur menu. Sesuaikan menu dengan route yang benar-benar ada pada checkout lokal.

---

## 3. Persona dan user stories

**Aktor utama:** pendamping/pengelola yang mengelola UMKM dan menjalankan penilaian/analitik pada desktop, tablet, dan ponsel.

- **US-01** — Saat membuka workspace desktop, saya memahami menu berdasarkan kelompok fungsi tanpa harus menghafal label teknis bahasa Inggris.
- **US-02** — Saat berpindah halaman, sidebar/topbar tetap stabil dan active item mengikuti halaman yang sedang saya buka tanpa spinner sesi layar penuh.
- **US-03** — Di ponsel, saya bisa mencari UMKM dari header tanpa input yang terpotong.
- **US-04** — Di ponsel, saya bisa membaca nama, informasi pokok, skor/status, serta membuka menu aksi UMKM tanpa menggeser tabel ke samping.
- **US-05** — Filter, sort, dan pagination memiliki perilaku yang sama di desktop dan mobile; state tidak hilang ketika saya kembali dari detail.
- **US-06** — Saat mengisi kuesioner/penilaian, saya paham konteks UMKM aktif dan tidak kehilangan jawaban yang belum tersimpan akibat perpindahan navigasi.
- **US-07** — Dengan keyboard, pembaca layar, atau layar sentuh, saya bisa membuka/menutup drawer, search, filter, dan menu aksi secara dapat diprediksi.

---

## 4. Sasaran, non-sasaran, dan aturan pengembangan

### 4.1 Sasaran wajib

1. Struktur navigasi konsisten pada desktop, tablet, mobile, termasuk state aktif, fokus, dan label.
2. Tampilan dan perilaku sidebar/topbar lebih tenang, responsif, dan fungsional.
3. Daftar UMKM menggunakan **satu state data** yang mendukung render tabel dan card secara konsisten.
4. URL, query, browser history, dan navigasi kontekstual tetap benar.
5. Semua overlay (drawer, global search, filter, action menu) dapat diakses lewat mouse, touch, keyboard, dan menutup dengan benar.
6. Tidak ada regresi UI-0/UI-1 atau operasi manajemen/penilaian UMKM.

### 4.2 Di luar cakupan (larangan scope creep)

- Mengubah business logic, scoring, keputusan kategori, Draft/Final/Batal, autosave, histori, kepemilikan UMKM, atau izin API.
- Menambah/mengganti endpoint API, schema/migration, seeder, auth model, token storage, atau mekanisme Sanctum.
- Menulis ulang aplikasi menjadi SPA Vue/React/Inertia; merombak `workspace.js` tanpa alasan langsung yang teruji untuk integrasi navigasi UI-2.
- Mendesain ulang landing/login/register; redesign KPI/dashboard, grafik distribusi, radar/factor-profile, comparison, form kuesioner, atau slider enam faktor secara page-level (**UI-3**).
- Menambah halaman Profil Saya, Pusat Bantuan, notifikasi, atau fitur bisnis lain yang belum fungsional. Hindari menampilkan navigasi yang berpura-pura aktif.
- Mengubah perilaku tindakan destruktif; istilah **Hapus/Arsip** dan hak akses harus mengikuti fitur checkout terkini, bukan asumsi dari screenshot atau commit remote lama.
- Menyelesaikan lima kegagalan seeder baseline dalam lingkup UI-2. Catat terpisah tanpa menyembunyikannya.
- Otomatis push, deploy, publish, commit, menghapus data, atau menjalankan migrasi destruktif.

### 4.3 Prinsip pelaksanaan

- **Audit dulu:** inventaris seluruh route, nav action, state URL, komponen UI-0/UI-1 dan sumber data pada checkout aktual.
- **Reuse first:** pakai `tokens.css`, Poppins, sprite SVG, shared primitives dan skeleton yang sudah dibangun; jangan buat sistem desain kedua.
- **Progressive enhancement:** URL biasa tetap dapat dibuka langsung dan mendukung open in new tab; internal navigation boleh diintersep oleh navigator parsial UI-0.
- **Ownership & security untouched:** semua API tetap membatasi data pengguna; jangan menyelundupkan data satu user ke tampilan user lain.
- **Neutral-first:** brand color untuk penekanan yang bermakna, bukan dekorasi ikon/dropdown.

---

## 5. Cakupan produk — Sidebar dan information architecture

### 5.1 Tampilan sidebar

| Atribut | Ketentuan |
|---|---|
| Background | Warna navy **`#101E40`** sebagai dasar; hindari gradient elektrik dominan lama |
| Active item | **`#0207CA`** sebagai background/penguat aktif, label dan ikon kontras; `aria-current="page"` untuk link aktif |
| Inactive item | Label/ikon monokrom terang, kontras jelas di navy |
| Hover | Permukaan terang transparan/subtle; tidak menggunakan warna berbeda untuk tiap item |
| Fokus | Visible focus ring sesuai token UI-1; bisa dibedakan dari hover dan active |
| Logo/brand | JoyUMKM dan deskripsi ringkas yang bersifat produk; hapus label internal “Design Concept ...” |
| Ikon | SVG reuse UI-1, optical size dan baseline konsisten |
| Separator / judul grup | Muted/low emphasis namun tetap terbaca |
| Footer utilities | Logout tetap jelas; Profile/Help hanya jika benar-benar mempunyai perilaku fungsional sesuai checkout |

**Jangan hardcode:** warna kebetulan yang mirip token atau font di luar Poppins. Active tidak boleh hanya diberi warna tanpa state/semantik teks.

### 5.2 Susunan kelompok (label Indonesia)

| Kelompok | Item tampilan | Route/entry point yang harus dipetakan |
|---|---|---|
| **Beranda** | Beranda | Route home aktual |
| **Manajemen** | Data UMKM | Route list/kelola UMKM aktual |
| **Penilaian** | Kuesioner Tiga Dimensi; Penilaian Enam Faktor | Route/entry point nyata yang mendukung pemilihan UMKM; boleh menuju konteks UMKM bila rute dinamis |
| **Analitik** | Profil Faktor; Distribusi Kesehatan; Perbandingan UMKM | Route chart/analitik aktual |
| **Utilitas** | Keluar; tindakan akun lain bila fungsional | Tetap sesuai perilaku existing |

**Persyaratan penting:** jangan menganggap setiap label otomatis mewakili route baru. Pemetaan route yang benar diverifikasi terhadap `routes/web.php`, nav existing dan halaman di checkout. Jika Penilaian Enam Faktor hanya tersedia melalui detail UMKM, arahkan pengguna lewat entry point/selection yang memang berjalan; jangan mengirim ke URL palsu atau kosong. Bila perlu menyederhanakan label sesuai route, pertahankan makna dan dokumentasikan mapping pada handoff.

### 5.3 Active state dan penutupan menu

- Saat URL/query berubah melalui partial navigation, sidebar desktop maupun drawer mobile mencerminkan route aktif secara konsisten.
- Satu item/group aktif pada saat yang sama, kecuali ada parent category indicator yang jelas.
- `aria-current="page"` hanya pada link yang benar-benar aktif.
- Klik menu internal menavigasi lewat mekanisme UI-0, tidak reload dokumen; `Ctrl/Cmd+click`, middle-click, target tab baru, dan open link in new tab tetap native.
- Link tidak fungsional tidak boleh diberi perilaku yang seolah menuju halaman siap pakai; tampilkan hanya jika ada tujuan yang sah.

---

## 6. Responsive shell — Desktop / tablet / mobile

### 6.1 Matriks perilaku

| Kondisi uji | Sidebar | Topbar | Daftar UMKM | Target |
|---|---|---|---|---|
| Desktop **1440×900** | Pinned/permanen | Pencarian inline | Tabel | Semua kontrol utama terjangkau |
| Tablet **768×1024** | Drawer dari tombol menu | Search ringkas/ikon bila perlu | **Card list sebagai default UI-2** | Tidak ada overflow dokumen |
| Mobile **390×844** | Drawer dari hamburger | Search lewat icon → overlay/panel | **Card list** | Tidak perlu geser tabel horizontal |

Breakpoint CSS/JS harus diselaraskan dengan layout aktual dan didokumentasikan, **bukan memilih nilai acak**. Tambahkan tes pada lebar transisi sekitar breakpoint, selain tiga viewport wajib. Tidak ada kebutuhan memperluas scope ke desain landscape khusus jika tidak bermasalah dalam smoke test.

### 6.2 Drawer mobile/tablet

- Terbuka dari hamburger dengan nama aksesibel (misalnya “Buka menu navigasi”).
- Memiliki heading/label konteks, close button, overlay/backdrop, dan `aria-expanded`/`aria-controls` yang benar.
- Fokus berpindah ke drawer saat dibuka; `Escape` dan tombol close menutup; fokus dikembalikan ke pemicu.
- Backdrop klik untuk menutup, dengan pencegahan click-through.
- Saat rute berubah berhasil, drawer menutup; jika navigasi dibatalkan karena unsaved changes, drawer tidak membuat form kehilangan data.
- Lock scroll halaman di belakang saat drawer terbuka; lepaskan lock saat close/unmount.
- Tidak ada event listener ganda setelah sejumlah navigasi; UI-0 persistent shell dipakai ulang.
- Pengujian mencakup perubahan ukuran viewport ketika drawer sedang terbuka agar tidak meninggalkan backdrop/scroll lock tersangkut.

### 6.3 Branding dan konteks

Topbar, logo, avatar, dan judul tetap mengikuti token serta Poppins UI-1. Jangan menduplikasi sidebar/header per halaman. Saat partial navigation shell tetap instance yang sama; hanya area konten diganti sesuai desain UI-0.

---

## 7. Topbar dan global search

### 7.1 Desktop

- Pencarian global bisa langsung dipakai, placeholder jelas dan tidak terpotong.
- Komposisi topbar memprioritaskan search, identitas/konteks pengguna, dan utilitas nyata. Elemen dekoratif atau placeholder belum fungsional jangan diberi penekanan visual yang mengganggu.
- Panjang nama pengguna dan ukuran layar sedang tidak boleh menutupi kontrol utama.

### 7.2 Mobile/tablet sempit

- **Jangan** memasukkan input teks desktop ke lebar topbar mobile sampai terpotong.
- Search icon membuka overlay/panel pencarian yang cukup lebar; input mendapat fokus, mempunyai label aksesibel dan tombol tutup.
- Tombol close, `Escape`, Back (bila relevan), dan klik di luar menutup tanpa menyisakan overlay/focus trap.
- Submit mengarahkan ke halaman UMKM dengan query `search` yang sudah dinormalisasi dan di-encode; URL tetap dapat di-share/bookmark serta bekerja dengan Back/Forward.
- Search dari halaman UMKM boleh memperbarui daftar tanpa full reload, memakai event/kontrak UI-0 yang ada.
- Debounce menghindari request berlebihan; respon pencarian lama tidak boleh menimpa hasil terakhir.
- Saat kembali ke halaman daftar, nilai search sesuai dengan URL state aktual.
- Jika token tidak valid, alur auth UI-0 tetap berlaku; search tidak boleh menampilkan data privat sesi sebelumnya.

### 7.3 Notifikasi, profil, bantuan

Jangan menciptakan halaman fiktif. Jika kontrol masih berupa toast placeholder, audit apakah layak ditempatkan sebagai utilitas yang tidak mendominasi UI atau sementara disembunyikan dari navigasi primer. Tidak boleh mengubah logout, membocorkan profil, atau menambah kontrak API baru.

---

## 8. Daftar UMKM: satu state, dua presentasi

### 8.1 Sumber data bersama

**Satu pipeline fetch + state management** untuk `search`, `filter`, `sort`, `direction`, `page`, `per_page`, loading, error, dan hasil API. Dua renderer (desktop table dan responsive cards) wajib membaca state yang sama, bukan memanggil API terpisah tanpa koordinasi.

- Pertahankan contract API, ownership, sort whitelist dan pagination existing.
- Cegah race request dengan lifecycle `AbortSignal`/sequence yang sudah dibangun di UI-0.
- Render state awal, loading skeleton, empty state, error dengan retry, dan data siap secara konsisten.
- Mutation create/edit/delete/archive (sesuai kemampuan aktual checkout) meng-update state dan pagination secara benar; tidak menggandakan handler.
- URL query tetap menjadi referensi untuk state yang memang shareable dan tidak kehilangan search/filter ketika kembali dari detail.

### 8.2 Desktop — tabel

- Pertahankan tabel sebagai tampilan utama di desktop.
- Kolom, sorting, pagination, filter, dan seluruh aksi yang telah tersedia tidak boleh hilang.
- Nama UMKM panjang membungkus dalam sel; detail dan dialog menampilkan nama lengkap.
- Kolom skor/status punya label dan kontras yang tepat; jangan membuat warna status satu-satunya penanda arti.
- Menu three-dots menggunakan komponen UI-1: neutral default, hover/focus halus, tindakan destruktif semantik.
- Jika masih diperlukan overflow horizontal untuk data tabel besar, area scroll jelas dan terlokalisasi pada tabel, bukan body halaman.
- Skeleton mengikuti struktur baris/kolom sehingga tidak mengakibatkan layout shift ekstrem.

### 8.3 Mobile dan tablet baseline — card list

Setiap card minimal menampilkan (jika API menyediakan):

1. **Nama UMKM** dengan wrapping natural tanpa pemotongan yang menghilangkan identitas.
2. **Sektor/kategori** dan/atau **lokasi** (informasi benar-benar tersedia; hindari label kosong).
3. **Skor kesehatan** dan penyebut `/100` hanya bila memiliki skor; bila belum, tampilkan label “Belum dinilai”/status faktual.
4. **Status/kategori evaluasi** jika tersedia, selalu dengan teks dan bukan warna saja.
5. **Menu three-dots** dengan akses ke tindakan existing yang sama seperti desktop, menghormati permission/status.

Ketentuan visual:

- Card putih/surface UI-1 dengan border, radius, spacing konsisten; **bukan** masing-masing card berwarna berbeda.
- Aksi utama mudah dijangkau, touch target sesuai baseline UI-1 (sekitar 44 px untuk pemicu sentuh).
- Tidak ada tabel desktop aktif/terlihat di viewport mobile. Jika dua container DOM ada, yang nonaktif tidak menerima fokus/tab dan disembunyikan dari aksesibilitas.
- Card skeleton sesuai proporsi card sebenarnya; status empty/error ditangani di area daftar.
- Pagination mobile tetap fungsional dan ringkas; jangan mengganti menjadi infinite scroll tanpa persetujuan.
- Nama panjang, skor kosong, label status panjang, data multi-baris, dan karakter Unicode harus diuji.

**Tablet 768 px:** gunakan card list sebagai default; jika QA membuktikan mode alternatif lebih tepat, keputusan harus didokumentasikan, tanpa melanggar ketentuan mobile 390 px atau desktop 1440 px.

---

## 9. Filter dan sorting responsif

### 9.1 Desktop

- Pertahankan filter yang sudah ada pada checkout (sektor, provinsi, kategori/status dan kombinasi terkait).
- Perjelas grouping field dan tindakan Terapkan/Reset tanpa mengubah arti filter.
- Sorting tabel dan pagination tidak boleh mengalami regresi atau reset tak sengaja.

### 9.2 Mobile

- Filter tidak lagi tampil memanjang permanen di atas daftar. Sediakan tombol **Filter** dengan jumlah filter aktif (jika ada).
- Tombol membuka **bottom sheet atau panel adaptif** yang memakai field existing dan label Indonesia yang jelas.
- Ada tindakan **Terapkan** dan **Reset**, serta close yang aman.
- Input sementara dalam panel tidak langsung mengubah data jika belum diterapkan (kecuali desain sudah eksplisit menerapkan filter live; pilih satu model konsisten, default **apply-on-submit**).
- Menutup panel tanpa menerapkan mempertahankan filter yang sebelumnya aktif.
- Setelah Terapkan, hasil, URL, chip/indikator filter aktif dan halaman pagination sinkron; perubahan kriteria mengembalikan pagination ke halaman valid.
- Tombol Reset menghapus hanya filter yang sesuai konteks, tidak membuang state lain tanpa alasan. Definisikan dan uji interaksi reset dengan `search` serta `sort` secara eksplisit agar tidak membingungkan.
- Filter panel mendukung keyboard focus management, Escape, scroll lock, touch, dan respons terhadap perubahan rute.

### 9.3 Query, history, navigasi

- URL query **ter-encode**, parameter kosong tidak menghasilkan URL rusak, dan nilai aktif pulih dari URL saat refresh/deep link.
- Browser Back/Forward mengembalikan state daftar, tidak menampilkan hasil stale.
- Menu aksi dan navigasi detail tidak merusak parameter daftar saat kembali.
- Tidak boleh terjadi dua request API yang saling menimpa akibat perubahan filter/sort atau pergantian renderer.

---

## 10. Breadcrumb dan navigasi kontekstual

- Tambahkan breadcrumb **hanya di halaman/alur yang benar-benar mempunyai hierarki konteks** (misalnya Data UMKM → Detail UMKM → Penilaian terkait UMKM).
- Gunakan nama dan ID dari data nyata, bukan label usaha contoh/hardcoded.
- Desktop dapat menampilkan breadcrumb ringkas di atas judul; mobile memakai parent link/tombol “Kembali” dan label konteks lebih pendek jika jalur panjang.
- Link breadcrumb menggunakan route nyata dan mempertahankan query state yang relevan.
- Jangan merubah page-specific business workflow di UI-3; UI-2 hanya memperjelas orientasi dan entry/exit-nya.
- Saat ada form/draft belum tersimpan, navigasi breadcrumb harus mematuhi confirmation guard UI-0.
- `aria-label` untuk breadcrumb, `aria-current="page"` pada bagian akhir non-link, dan fokus heading setelah navigasi.

---

## 11. Integrasi teknis dengan UI-0 dan UI-1

### 11.1 UI-0: persistent partial Blade navigation

- Workspace shell (`sidebar`, `topbar`, `drawer`, `toast region`, listener global) **tetap memiliki instance yang sama** pada klik route internal.
- Jangan memanggil `/api/auth/me` untuk setiap klik internal normal; cold entry/history revalidation tetap sesuai UI-0.
- Update active link/group state untuk URL baru tanpa reload, termasuk perubahan melalui browser history.
- Event handler yang dipasang pada elemen halaman diganti/unmount mengikuti lifecycle `resources/js/workspace.js`; listener global tidak terpasang berkali-kali.
- Navigasi cepat, pembatalan request, chart cleanup, form dirty confirmation, autosave dan abort terjaga.
- Perubahan drawer/search/filter tidak boleh memicu scroll lock atau overlay “hantu” setelah navigasi/resize.
- Klik link native pada tab baru dan no-JS fallback mengikuti perilaku browser; proteksi data privat tetap fail-closed.

### 11.2 UI-1: design tokens dan reusable primitives

- Reuse warna brand `#0207CA`, `#0297DE`, `#FC734D` dan navy `#101E40`; jangan memperkenalkan palet branding baru.
- Poppins global termasuk menu/card/tabel, satu sprite SVG lokal, focus style, dropdown netral, button/form primitives, skeleton and reduced motion.
- Jangan membuat salinan komponen dropdown untuk mobile; gunakan logic/styles bersama bila memungkinkan.
- Gunakan neutral backgrounds untuk filter/card/drawer/row default dan semantic color hanya untuk status atau aksi destruktif.

### 11.3 File yang perlu diaudit (bukan daftar perubahan wajib)

- `resources/views/layouts/app.blade.php`
- `resources/views/components/navigation.blade.php`
- `resources/views/umkms.blade.php`, view detail/penilaian terkait yang ada pada checkout
- `resources/js/app.js`, `resources/js/workspace.js`, `resources/js/auth.js`
- `resources/js/pages/umkms.js`, `resources/js/ui.js`, `resources/js/api.js`
- `resources/css/tokens.css`, `resources/css/app.css`, `resources/css/dashboard.css`
- `routes/web.php` dan tes browser regresi yang relevan

**Catatan:** daftar di atas adalah orientasi berdasarkan handoff dan struktur repo yang sudah pernah diinspeksi. Audit actual checkout diperlukan karena UI-0/UI-1 telah menambah atau mengubah file.

---

## 12. Aksesibilitas, ketahanan, dan pengalaman interaktif

1. Semua pemicu ikon memiliki `aria-label` jelas dalam bahasa Indonesia; dekorasi SVG `aria-hidden`.
2. Fokus keyboard terlihat pada menu, search, filter, pagination, table actions, card actions, dan breadcrumb.
3. Drawer/search/filter dialog memiliki fokus masuk/keluar yang benar; tidak ada keyboard trap atau scroll terkunci permanen.
4. Status dan skor bermakna melalui teks, bukan hanya warna; label dan kontras mengacu standar UI-1 (target WCAG AA).
5. Touch target kontrol utama memadai; tidak overlap di lebar 390 px.
6. `prefers-reduced-motion` dipatuhi; transisi tidak diperlukan untuk menyelesaikan tugas.
7. Saat data lama tergantikan, screen reader mendapat pengumuman ringkas melalui `aria-live` yang sesuai, tanpa spamming.
8. Loading skeleton per komponen dan retry lokal tetap berfungsi; navigasi gagal memiliki recovery yang jelas tanpa membocorkan data sesi lama.
9. Penggunaan warna dan jenis ikon konsisten serta tidak menambah dependensi remote font/icon baru.

---

## 13. Acceptance criteria — UI2-AC01 hingga UI2-AC12

**Semua gate wajib PASS dan disertai bukti; jangan klaim PASS dari screenshot saja jika butuh pengujian interaksi.**

| ID | Gate | Kriteria yang harus dibuktikan |
|---|---|---|
| **UI2-AC01** | Sidebar brand | Navy `#101E40`, active blue `#0207CA`, kontras/hover/focus benar, ikon Poppins/SVG reuse UI-1, tanpa label prototipe internal |
| **UI2-AC02** | IA & route mapping | Grup Beranda/Manajemen/Penilaian/Analitik; semua item ke route nyata; tidak ada dead link/route palsu; active state + `aria-current` akurat |
| **UI2-AC03** | Responsive shell | Desktop sidebar permanen; 768/390 drawer dengan open/close, backdrop, Escape, focus return, scroll lock dan resize cleanup benar |
| **UI2-AC04** | Search global | Search desktop dan overlay/panel mobile tidak clipped; query ke daftar UMKM benar; debounce, focus, clear, Back/Forward dan deep link berfungsi |
| **UI2-AC05** | Context & breadcrumb | Jalur kontekstual tepat pada alur existing; back link valid, state daftar pulih, dirty-form guard UI-0 tetap berlaku |
| **UI2-AC06** | Desktop table | Pada 1440 tabel tetap ada dengan seluruh kolom/fitur, sorting, actions, pagination; nama panjang dan tiga titik tetap berfungsi |
| **UI2-AC07** | Mobile/tablet cards | Pada 390 card list tanpa horizontal table scroll; 768 usable dengan default cards; status/skor/nama/aksi benar, termasuk empty/loading/error |
| **UI2-AC08** | Shared data state | Tabel dan cards berbagi data, filter/search/sort/page/mutation konsisten; tidak ada duplicate API, race overwrite atau action handler ganda |
| **UI2-AC09** | Filter UX | Mobile filter panel/bottom sheet; apply/reset/close, active count, URL query, reset & pagination rules bekerja; desktop filter tetap utuh |
| **UI2-AC10** | Accessibility | Keyboard/touch, focus, label, semantic statuses, modal overlay, WCAG AA smoke, no overflow 1440/768/390 + breakpoint transition |
| **UI2-AC11** | Regression | UI-0 workspace dan six-factor browser regressions PASS; UI-1 font/tokens/icon/menu unaffected; build, JS, PHP targeted PASS; full PHP compared to baseline per case |
| **UI2-AC12** | Handoff & evidence | Route map, responsive matrix, before/after screenshots, video/interactions bila berguna, tests + baseline diff, known gaps, changed files, final verdict lengkap |

### 13.1 Contoh skenario browser wajib

**Navigasi:**

- Cold direct entry lalu internal klik Home → Data UMKM → Analitik → Back → Forward tanpa full-screen spinner dan tanpa duplikasi `/auth/me`.
- Active state pada sidebar dan drawer mengikuti route meski URL diubah via history.
- Menu drawer dibuka, `Escape`, backdrop, tutup, keyboard activation; navigasi cepat; resize dengan drawer terbuka.
- Ctrl/Cmd+click/middle click dan reload/deep link tetap berjalan.
- Draft kuesioner belum tersimpan → klik breadcrumb/menu → batalkan; data tetap ada.

**Search/filter/list:**

- Search via mobile icon → ketik → submit → URL search benar → hasil card sesuai → Back/Forward memulihkan state.
- Filter 2 nilai → Terapkan → indikator jumlah aktif → buka detail → kembali → filter tetap.
- Filter edit tanpa Terapkan → tutup → state sebelumnya tetap, lalu Reset memulihkan sesuai definisi.
- Sort/pagination lalu pindah tampilan/lebar viewport → data, urutan, dan jumlah tetap konsisten.
- Nama UMKM sangat panjang, skor kosong, status panjang, data kosong, error API, retry, pending skeleton, menu three dots dan konfirmasi tindakan.
- Perubahan data CRUD yang aman dalam fixture isolated → list dirender ulang tanpa event ganda dan page out-of-range dikoreksi.

**Keamanan dan regresi:**

- User A → token diganti/logout di tab lain → shell/data user A dibersihkan sesuai UI-0.
- 401/403, request lambat, dan pergantian navigasi cepat tidak menghasilkan stale UI privat.
- Uji loading independent KPI/chart saat UI-2 styles aktif, tanpa spinner sesi global.
- Uji form penilaian enam faktor dan kuesioner masih menyimpan/menolak sesuai aturan existing.

### 13.2 Viewport untuk QA

| Target | Status |
|---|---|
| **1440×900** | Wajib: desktop sidebar + tabel |
| **768×1024** | Wajib: drawer tablet + default card |
| **390×844** | Wajib: drawer + mobile search/filter + card |
| 320–360 px | Smoke tambahan untuk perangkat sempit bila tooling tersedia |
| Lebar sekitar breakpoint | Wajib minimal satu uji sebelum dan sesudah breakpoint yang dipilih |

---

## 14. Strategi implementasi bertahap dan quality gates internal

Tahapan kerja di bawah adalah **subtask UI-2**, bukan fase roadmap baru; jangan menutup pekerjaan setelah subtask visual saja.

| Langkah | Cakupan | Exit criteria internal |
|---|---|---|
| **UI2-W1** | Audit checkout, route mapping, kontrak navigation/menu, baseline screenshots dan regression | Inventaris route, status UI-0/UI-1 dan baseline tervalidasi |
| **UI2-W2** | Sidebar navy, grouped menu, active state, desktop/drawer responsive | AC01–AC03 berfungsi dan QA keyboard lulus |
| **UI2-W3** | Topbar search responsive + breadcrumb kontekstual | AC04–AC05, query/history/dirty guard lulus |
| **UI2-W4** | Shared state renderer tabel/cards, filter mobile, skeleton/error | AC06–AC09 lulus, tidak ada operasi data berubah |
| **UI2-W5** | Accessibility, screenshot semua viewport, regression/handoff | AC10–AC12 lulus dan laporan final diserahkan |

Implementasi boleh memindahkan urutan subtask agar menjaga dependensi kode, tetapi semua acceptance criteria harus diselesaikan sebelum dinyatakan PASS.

---

## 15. Testing, data, dan evidence

### 15.1 Baseline regresi

Gunakan runner existing dari handoff UI-0 dan UI-1 pada checkout lokal. Handoff sebelum UI-2 melaporkan:

- `npm run build`, `php artisan view:cache`, `npm test` (**33 JS**).
- `tests/js/workspace-browser.mjs` (**13 browser**), `tests/js/six-factor-browser.mjs` (**13 browser**).
- `tests/js/ui-foundation-browser.mjs` (UI-1, tiga viewport dan sejumlah state).
- PHP targeted (**164 tests, 1.816 assertions**) sesuai test filter yang ada.
- PHP full (**197/202 tests PASS**) dengan lima kegagalan seeder baseline. Bandingkan **JUnit per kasus**, jangan sekadar angka total.

Jumlah tes dapat berubah bila ada pengujian baru. Jalankan tes aktual; jangan mengklaim angka lama sebagai hasil UI-2.

### 15.2 Environment pengujian

- Pakai MySQL **testing** yang terisolasi, bukan DB development berisi data penting atau produksi; verifikasi konfigurasi efektif sebelum fixture/cleanup.
- Browser automation dengan Playwright/Chrome lokal yang sudah tersedia; tes no-JS, back-forward, multi-tab, dan keyboard sesuai scope.
- Browser dan PHPUnit yang berbagi database testing dijalankan **bergantian** agar fixture tidak saling merusak.
- Jangan sertakan password, token, cookies, storage state, atau data pengguna sensitif dalam screenshot/log/evidence.
- Peringatan chunk ApexCharts ~938 kB dari handoff UI-1 adalah catatan existing; tidak otomatis menjadi blocker UI-2 kecuali ada regresi performa nyata.

### 15.3 Direktori evidence yang direkomendasikan

`docs/evidence/ui-2/`

```text
docs/evidence/ui-2/
├── handoff.md
├── route-navigation-map.md
├── responsive-matrix.md
├── test-results.md
├── baseline-regression-comparison.md
├── screenshot-manifest.md
├── screenshots/
│   ├── before/
│   └── after/
│       ├── desktop-1440/
│       ├── tablet-768/
│       ├── mobile-390/
│       └── interactive-states/
└── measurements/                  # bila diperlukan
```

Minimal evidence: screenshot sidebar/nav pada ketiga viewport, menu drawer terbuka, search overlay, desktop UMKM table, mobile UMKM cards, filter sheet aktif, long name, empty/loading/error, breadcrumb; serta hasil tes interaksi terkait.

---

## 16. Risiko, mitigasi, dan keputusan bila terhalang

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Tautan baru tidak kompatibel dengan partial navigation UI-0 | Full reload, spinner atau URL salah | Ikuti kontrak link dan route actual; browser regression internal/open-new-tab |
| Active state tidak sinkron saat history navigation | Navigasi menyesatkan | Sinkronisasi berbasis URL dan event navigator, test Back/Forward |
| Drawer dan search/filter memiliki handler/overlay ganda | Klik dobel, scroll terkunci, focus trap | Komponen reusable dan cleanup deterministik; tes multi-navigation/resize |
| Dua view tabel/card mem-fetch terpisah | Duplikasi, data tidak sinkron | Satu source state/controller, renderer berbeda |
| Mobile card menyembunyikan aksi/field | Kehilangan fitur manajemen | Feature parity checklist tiap aksi dan field utama |
| Filter dan search mengubah query semantik | Hasil yang tidak sesuai | Tetap gunakan request API existing; matrix combinatorial filter/sort/page |
| Route Penilaian Enam Faktor berbeda dari asumsi | Dead link, user tersesat | Audit checkout; arahkan ke entry point nyata tanpa route fiktif |
| Token berubah ketika halaman sedang memuat | Flash data lintas akun | Pertahankan fail-closed UI-0; test multi-tab dan stale async response |
| Scope melebar ke redesign UI-3 | Lama/risiko regresi | Lock scope pada workspace, nav, list responsive dan filter |
| Seeder baseline gagal | Salah klaim regresi baru | Simpan baseline per-case, laporkan secara eksplisit tanpa mengubah seeder |

Jika sebuah komponen tidak ada di checkout lokal, **jangan membangun fitur bisnis baru hanya untuk memenuhi ilustrasi PRD**. Dokumentasikan pemetaan aktual dan solusi navigasi minimal yang tetap memenuhi kebutuhan pengguna.

---

## 17. Definition of Done — GO / NO-GO

UI-2 boleh dinyatakan **PASS** hanya jika:

1. `UI2-AC01` sampai `UI2-AC12` semuanya **PASS**, memiliki bukti yang bisa diperiksa.
2. Desktop 1440 menampilkan sidebar navy dan tabel UMKM; mobile 390 menampilkan drawer, search/filter yang usable, serta card list tanpa horizontal-scroll tabel sebagai pola utama; tablet 768 usable.
3. Partial navigation UI-0, autentikasi/ownership, skeleton lokal, abort, loading/error/retry, deep link, Back/Forward dan dirty form tetap aman.
4. Font, tokens, SVG, dropdown neutral, dan shared components UI-1 tetap konsisten.
5. Build, JS test, browser regression, PHP targeted **PASS**. PHP full suite **dibandingkan dengan baseline per-case**; bila ada kegagalan baru akibat UI-2, status **NO-GO** sampai diperbaiki.
6. Tidak ada perubahan API/DB/bisnis yang tidak disetujui; tidak ada deploy/push otomatis.
7. Handoff merangkum perubahan file, keputusan breakpoint, matriks rute, screenshot sebelum/sesudah, browser tests, baseline compare, gap yang belum diuji, dan verdict akhir.

**Tidak boleh mengklaim PASS parsial** ketika gate penting belum diuji. Jika Firefox/Safari/screen reader nyata belum diuji, tulis **NOT RUN** untuk matriks ekstra tersebut; jangan menyamarkannya sebagai PASS. Baseline lima kegagalan seeder yang tidak berubah boleh dilaporkan sebagai *known baseline failures*, bukan regresi UI-2.

---

## 18. Handoff ke UI-3

Setelah UI-2 PASS, UI-3 boleh mengerjakan refinement tampilan masing-masing halaman: hero/KPI Dashboard, chart label dan radar, empty states/detail UMKM, slider Enam Faktor, form kuesioner, landing, login, register, dan microcopy yang perlu disempurnakan. UI-3 harus **memakai sidebar/topbar/list responsive UI-2** serta fondasi UI-0/UI-1; jangan mengulang implementasi arsitektur navigasi atau membuat design system ketiga.

**Instruksi implementasi:** selesaikan semua cakupan UI-2 sebagai satu fase dengan bukti verifikasi. Jangan berhenti pada sidebar yang tampak bagus tetapi gagal search/filter/card list, dan jangan mengambil pekerjaan UI-3 sebagai alasan memperluas scope.
