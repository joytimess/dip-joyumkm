# PRD JoyUMKM V1 — UI-3: Page-Level Visual Refinement

**Document ID:** `PRD-JOYUMKM-UI-3`  
**Tanggal:** 21 November 2026 (Asia/Jakarta)  
**Status:** **DESIGN SPECIFICATION — READY FOR LOCAL IMPLEMENTATION**  
**Prioritas:** Setelah UI-0, UI-1, UI-2 (ketiganya dilaporkan PASS pada checkout lokal)  
**Repository:** https://github.com/joytimess/dip-joyumkm  
**Fase sesudahnya:** UI-4 — Full Visual QA & Regression  
**Kategori:** UI/UX refinement, presentational behavior, responsive/accessibility; **bukan** fitur bisnis baru.

> **Sumber keputusan:** diskusi dan persetujuan pengguna, screenshot awal `joyumkm-ui-screenshots.zip`, PRD UI-0/UI-1/UI-2, serta handoff UI-0/UI-1/UI-2 yang disampaikan pengguna. Handoff UI-2 melaporkan 12/12 gate PASS, UI-0/UI-1 regression PASS, 33 JS PASS, 164 PHP terarah PASS, dan 197/202 suite penuh dengan lima kegagalan seeder baseline identik. Itu **laporan hasil lokal, bukan hasil tes independen pada dokumen ini**.
>
> **Source-of-truth kode:** **checkout lokal terbaru** yang sudah memiliki UI-0/UI-1/UI-2, bukan asumsi dari `main` GitHub publik lama. Implementor wajib membaca status Git, route/page inventory, token, CSS, data contracts, dan hasil handoff lokal sebelum mengubah kode. Screenshot ZIP awal adalah **baseline diagnosis awal**, bukan bukti tampilan terkini setelah UI-2.

---

## 1. Ringkasan eksekutif

JoyUMKM sudah melewati tiga fase:

- **UI-0**: partial Blade navigation, persistent authenticated shell, session validation aman, skeleton loading per komponen, lifecycle abort/cleanup, browser history.
- **UI-1**: global **Poppins**, design tokens, icon SVG sprite lokal, primitive komponen, menu three-dots monokrom, semantic color discipline.
- **UI-2**: sidebar **Midnight Navy `#101E40`**, active **Electric Blue `#0207CA`**, menu berkelompok Bahasa Indonesia, search responsif, tablet/mobile drawer, table desktop + card list tablet/mobile, filter bottom sheet, breadcrumb dan URL state.

**UI-3 bertugas menyempurnakan isi masing-masing halaman**: hierarki informasi, page-specific spacing dan density, ukuran/legenda grafik, tata letak KPI, kenyamanan formulir/slider, empty/error/skeleton presentation, microcopy, dan responsivitas halaman publik. Konsistensi tersebut harus dilakukan **di atas fondasi yang sudah selesai**, bukan dengan membuat ulang design system atau mengganti arsitektur.

### Outcome wajib

1. Setiap halaman publik dan authenticated memiliki komposisi visual yang rapi, mudah dipindai, dan konsisten dengan brand serta Poppins.
2. Page heading, section, card, form, chart, feedback, dan CTA memiliki hierarki yang bermakna dan spacing terukur.
3. Mobile tidak mempunyai teks terpotong, kontrol terhalang, chart label bertabrakan, atau tindakan penting yang tersembunyi tanpa petunjuk.
4. Dua metode penilaian (**Kuesioner Tiga Dimensi** dan **Penilaian Enam Faktor**) dapat dibedakan secara visual, tanpa mengganti aturan/hasil masing-masing.
5. Perubahan UI tidak memengaruhi URL/query context, draft/final/batal, autosave, histori, ownership, API, scoring, atau session lifecycle.
6. Semua temuan baseline yang termasuk UI-3 diverifikasi melalui screenshot **sebelum dan sesudah** pada checkout lokal terkini, bukan sekadar klaim estetika.

---

## 2. Prinsip desain yang mengikat

| Aspek | Baseline wajib | Aturan UI-3 |
|---|---|---|
| Gaya | **Minimalist Professional SaaS Dashboard**, neutral-first, data-first | Permukaan netral dominan; warna bukan dekorasi tanpa tujuan |
| Primary | `#0207CA` | CTA, fokus/aksi utama, active navigation (dari UI-2) |
| Secondary | `#0297DE` | Seri data atau informasi pendukung seperlunya |
| Accent | `#FC734D` | Highlight khusus; pemakaian dibatasi |
| Navy | `#101E40` | Teks utama/sidebar UI-2; sidebar tidak di-redesign ulang |
| Surface | `#FFFFFF`, latar `#F4F7FC`, muted `#70819D` | Pakai token canonical UI-1; jangan buat palet paralel |
| Status | Warna semantik existing (mis. hijau/amber/merah) | Wajib disertai label, bukan warna sebagai satu-satunya penanda |
| Font | **Poppins global**, termasuk SVG-adjacent label dan chart text | Reuse bundel font lokal, tidak menambah font stack baru |
| Ikon | Satu sprite SVG lokal UI-1 | Reuse, size/aria konsisten; jangan membuat paket ikon lain |
| Komponen | Primitive CSS/UI UI-1 | Reuse button, form, card, alert, dialog, tooltip, skeleton |
| Shell | Persistent partial Blade navigation UI-0 + grouped nav UI-2 | Shell dan navigasi tetap; hanya presentasi area halaman yang dibenahi |

**Referensi ukuran, bukan aturan kaku:** page padding desktop `24–32px`, mobile `16px`; card padding desktop `20–24px`, mobile `16–20px`; section gap `24–32px`, mobile `20–24px`; label/input `8px`; skala kelipatan `4px`. Jangan mengulang definisi token yang sudah ada; gunakan/ekstensi token bersama bila benar-benar perlu.

---

## 3. Target pengguna, user stories, dan success definition

**Aktor:** pendamping/pengelola UMKM yang membaca dashboard, memilih usaha, mengisi kuesioner, melakukan penilaian enam faktor, meninjau hasil/analitik, dan memakai halaman publik.

| ID | User story |
|---|---|
| `US-01` | Di dashboard saya langsung tahu angka utama, status portfolio, dan langkah yang dapat dilakukan, tanpa scroll berlebihan untuk konten inti. |
| `US-02` | Di halaman detail UMKM saya menemukan data yang tersedia, penilaian yang relevan, dan navigasi kontekstual tanpa kebingungan antara dua metode. |
| `US-03` | Saat mengisi kuesioner saya mudah mengenali progres, status Draft/Final, tombol aksi, dan indikator data yang sedang dimuat/disimpan. |
| `US-04` | Saat menggunakan slider enam faktor saya bisa membedakan **belum dipilih** dari nilai minimum dan melihat feedback penyimpanan yang benar. |
| `US-05` | Di dashboard analitik saya dapat membaca chart, legenda, label, angka, dan state kosong di layar ponsel. |
| `US-06` | Di landing/login/register saya melihat identitas JoyUMKM yang sama dan form yang cepat diakses pada layar kecil. |
| `US-07` | Di layar sempit atau dengan keyboard/zoom saya tetap bisa membaca dan mengoperasikan semua komponen utama. |

**Definition of success:** semua acceptance criteria `UI3-AC01` s.d. `UI3-AC16` PASS dengan screenshot, DOM/browser assertions, serta regresi lintas UI-0/UI-2 dan API terkait. Hasil yang tidak dijalankan ditandai `NOT RUN`, bukan `PASS`.

---

## 4. Inventaris halaman dan discovery wajib

Implementor harus memetakan semua route dan semua state yang **benar-benar tersedia** pada checkout lokal; contoh rute dari handoff UI-2:

| Kelompok | Rute/entry point yang dilaporkan | Cakupan UI-3 |
|---|---|---|
| Publik | `/` | Landing, konten section, ilustrasi, CTA, footer |
| Publik | `/login` | Form login, validasi dan feedback, mobile |
| Publik | `/register` | Form registrasi, validasi/password toggle/strength, mobile |
| Workspace | `/home` | Hero/welcome, KPI, grafik, prioritas dan empty/loading |
| Manajemen | `/umkms` | Integrasi visual dengan card/table UI-2, empty/error, dialog data |
| Detail UMKM | Route detail real milik checkout; jangan tebak path | Metadata, aksi penilaian, panel kontekstual, ruang kosong |
| Kuesioner | `/form-data` | Picker, form 3 dimensi, progress, live calculation, Draft/Final |
| Enam Faktor | `/umkms/{id}/six-factor-evaluations` menurut handoff | Slider 6 faktor, progress/status, autosave, radar, histori/result |
| Analitik | `/factor-profile` | Chart profil faktor, ringkasan/perbandingan, label |
| Analitik | `/distribution` | Grafik distribusi, metrik, legenda |
| Analitik | `/comparison` | Pemilih/pembanding UMKM, grafik dan tabel ringkasan |

**Catatan discovery:** handoff UI-2 melaporkan **34 route aplikasi** secara keseluruhan, bukan 34 halaman yang semuanya membutuhkan screenshot unik. Daftar dan parameter tidak boleh diasumsikan statis; snapshot `php artisan route:list` dan route inventory harus dilampirkan. Tinjau pula halaman error, not-found, dialog riwayat, pengisian langkah 1/2/3, empty/search result, loading, feedback, dan validasi yang relevan.

**Scope integration:** Data UMKM desktop/tablet/mobile yang sudah dibangun UI-2 hanya boleh disempurnakan pada *page presentation* tanpa mengubah shared state, query, pagination, filter, mutation, atau struktur breakpoint. Jika perubahan yang diinginkan ternyata menyentuh komponen global UI-2, lakukan adaptasi minimal dan sertakan bukti regresinya.

---

## 5. Requirements umum lintas halaman (FR-01–FR-08)

### FR-01 — Hierarki informasi & page header [MUST]

- Maksimum satu `h1` semantik per halaman; judul, deskripsi, breadcrumb, section heading, CTA memiliki ritme visual yang konsisten.
- Breadcrumb mengikuti UI-2; tidak menggandakan header/nav atau menghapus query/list-context.
- Prioritaskan informasi dan aksi utama pada *first viewport*. Kurangi ruang kosong yang disebabkan panel tidak seimbang atau margin tidak relevan.
- Jangan memindahkan CTA yang krusial ke area tersembunyi di mobile tanpa alternatif akses jelas.
- Heading/label/angka tidak terpotong pada nama UMKM panjang, konten berbahasa Indonesia, atau zoom browser 200%.

### FR-02 — Spacing, density & alignment [MUST]

- Gunakan token UI-1 dan spacing scale yang sama: relasi section-to-section, label-to-control, card-to-card, sidebar-to-content, dan header-to-card dapat diprediksi.
- Hindari panel dengan whitespace kosong ekstrim yang tidak bermakna. Hindari kebalikan: card padat dengan font sangat kecil atau tombol saling berhimpitan.
- Grid boleh berubah responsif sesuai density konten; **tidak** wajib mengikuti grid 4 kolom desktop pada mobile.
- Komponen data yang saling terkait harus berada dalam satu kelompok visual; jangan menjadikan dekorasi lebih menonjol dari isi.

### FR-03 — Tipografi & microcopy [MUST]

- Poppins UI-1 sebagai canonical; heading/body/label/metadata/tooltip sesuai hierarki serta leading yang disepakati.
- Teks kecil pada chart, tabel, panduan, dan status harus terbaca; jangan memakai 9–11px sebagai default untuk informasi yang harus dibaca.
- Bahasa Indonesia konsisten; gunakan istilah domain dan status backend yang sudah berlaku. Jangan menamai ulang kategori kesehatan secara sepihak.
- Judul, deskripsi, legend, tooltips, angka `—`, `/100`, tanggal dan satuan harus jelas, tidak saling bertentangan, dan tidak mengklaim data yang tidak tersedia.

### FR-04 — Stateful presentation [MUST]

- Setiap area data utama menampilkan `loading`/`empty`/`error`/`success` dengan layout stabil. Skeleton proporsional terhadap komponen tujuan.
- Jangan mengganti kembali dengan full-screen spinner atau memblokir seluruh halaman karena satu komponen loading.
- Error lokal mempunyai pesan yang dipahami pengguna dan aksi retry untuk GET/read. Empty state harus menjelaskan langkah berikutnya yang benar.
- Animasi loading ringkas, menghormati `prefers-reduced-motion`, tidak menghasilkan layout jumping besar.

### FR-05 — Aksesibilitas [MUST]

- Fokus keyboard terlihat pada aksi utama, slider, picker, radio/input, dialog, tombol riwayat, dan grafik interaktif (bila ada).
- Target sentuh utama pada mobile setidaknya sekitar 44×44px; kontrol yang rapat tidak saling menimpa.
- Teks dan status penting memenuhi target kontras WCAG AA; jangan menyampaikan kondisi hanya lewat warna.
- Elemen dialog memiliki label yang bermakna, fokus masuk/kembali benar, Escape/backdrop sesuai UI-1/UI-2; komponen grafis punya teks/tabel pendamping yang setara bila penting.
- Periksa browser zoom 200% dan reduced motion; jika sesuatu tidak bisa dipastikan oleh automation, tandai sebagai keterbatasan.

### FR-06 — Data fidelity [MUST]

- Angka, status, chart, kategori, serta label hanya berasal dari respons API/state nyata; jangan hardcode data dekoratif di runtime.
- Render nilai `null`/undefined, 0, skor belum ada, draft, final, batal secara berbeda bila kontraknya membedakan.
- Jika menemukan mismatch label-vs-data (contoh baseline diagnosis: angka KPI total UMKM vs deskripsi, atau skor faktor vs aggregate ringkasan), **verifikasi sumber respons** dahulu. Perbaiki **mapping/label presentasi yang terbukti salah** tanpa mengubah model perhitungan. Bila akar masalah bisnis/API di luar UI-3, catat defect terpisah dan jangan memalsukan hasil.
- Chart and aggregate tidak boleh menyiratkan hasil baru atau metode penilaian yang belum dihitung.

### FR-07 — Resilience pada navigasi parsial [MUST]

- Semua page controllers mengikuti `mount/unmount/AbortSignal` UI-0. Jangan mengandalkan `DOMContentLoaded` sekali untuk fitur yang harus aktif saat navigasi parsial.
- Destroy chart, listener, debounce/autosave milik halaman saat exit. Komponen yang sudah dimuat tidak ditimpa response lama akibat pergantian route/picker.
- Back/Forward, reload, direct link, tab baru, cross-tab session, logout dan session error tetap aman.
- Aksi perubahan data harus melalui flow/guard existing; jangan menambah mutation untuk tujuan screenshot.

### FR-08 — Integritas design system [MUST]

- Reuse token, Poppins, ikon sprite SVG, modal, button, toast, skeleton, badge yang telah lulus UI-1.
- Reuse sidebar navy, active route, drawer, global search, card/table, filter bottom sheet, breadcrumb yang telah lulus UI-2.
- Warna spesifik per-chart/seri boleh digunakan untuk membedakan data **dengan alasan semantik**, tetapi dropdown, ikon aksi, card umum tidak menjadi warna-warni lagi.
- Setiap CSS override lokal harus minimal, terdokumentasi, dan tidak membuat utility/komponen global lain rusak.

---

## 6. Halaman publik: landing, login, register (FR-09)

### 6.1 Landing `/`

**Keputusan terkunci: pertahankan layout dan identitas ilustrasi yang ada; lakukan visual refinement, bukan redesign total.**

- Jaga urutan informasi, penempatan CTA, dan identitas hero yang sudah dikenali; perhalus typography, leading, spacing, margin, button, card, icon dan footer agar selaras dengan UI-1.
- Pastikan hero headline tidak menghasilkan pemenggalan kata buruk pada 390px/320px dan CTA utama tetap terlihat serta mudah disentuh.
- Ilustrasi/diagram mempertahankan karakter brand tanpa menjadi kumpulan warna dekoratif berlebihan; jangan menghilangkan informasi hanya untuk minimalisme.
- Revisi kontras caption/paragraph dan responsivitas section dua kolom agar tidak overflow pada 768px dan 390px.
- Tidak menambah halaman pemasaran, animasi berat, data klaim, atau call-to-action yang belum mempunyai route nyata.

### 6.2 Login `/login`

- Pertahankan komposisi split-layout pada desktop, tetapi perhalus form, lebar, jarak label/input, posisi CTA, password toggle, error inline dan navigasi ke register.
- **Masalah baseline:** panel dekoratif mobile mengambil ruang vertikal terlalu besar sehingga form utama terlalu jauh dari atas. Kurangi tinggi/density panel branding pada mobile **tanpa** menghilangkan identitasnya; pastikan input email dan password bisa segera ditemukan.
- State default, empty validation, 401, network error, loading saat submit, keyboard mobile, dan tampilan saat font fallback harus jelas.
- Autofill `autocomplete`/browser/password-manager behavior tidak boleh rusak; pengujian password manager nyata boleh tercatat NOT RUN jika tidak ada akses.

### 6.3 Register `/register`

- Terapkan prinsip layout/spacing/typography yang sama dengan login, tanpa mengubah daftar field atau validasi backend.
- Label, teks bantuan, password strength indicator, toggle visibility dan konfirmasi password harus terbaca di mobile, termasuk error multi-field.
- Submit, navigation link, dan feedback tidak boleh tenggelam di bawah panel dekoratif atau tertutup keyboard.

**Gate halaman publik:** screenshot full-page 1440×900, 768×1024, 390×844 (tambahan 320px untuk stress check), state validation, fokus, loading/error minimal, CTA aman; konten/flow tetap sama.

---

## 7. Dashboard `/home` (FR-10)

- **Welcome/hero:** proporsi lebih tenang, judul dan CTA tidak mengalahkan angka inti. Pertahankan fungsi greeting dan aksi Kelola UMKM. Kurangi dekorasi visual yang mengurangi fokus.
- **KPI summary:** perjelas empat metrik yang ada (contoh pada baseline: total responden/UMKM, rerata skor, sektor prioritas, Draft aktif) tanpa menambah indikator yang tidak memiliki sumber data. Label dan angka harus satu kesatuan yang mudah dibandingkan.
- **Mobile KPI:** jangan menumpuk card besar terlalu panjang jika dua kolom responsif masih memungkinkan tanpa clipping; gunakan 2 kolom pada ukuran yang cukup, 1 kolom bila diperlukan pada 320px/zoom atau nama panjang.
- **Chart & legend:** perbaiki label, ukuran grafik, posisi legenda, dan margin. Chart utama harus dapat dibaca tanpa perlu zoom manual; jangan memotong kategori atau tooltip.
- **Prioritas pendampingan:** tampilkan hierarki nama usaha, urgensi/kondisi, serta CTA tanpa dekorasi berlebihan; jika kosong tampilkan empty state nyata.
- **State independen:** KPI delayed sementara chart sudah siap, atau chart error sementara KPI siap, tetap mempertahankan bagian lain. Integrasi skeleton UI-0 wajib.
- **Data copy:** contoh mismatch awal “8 UMKM” vs “7 usaha dalam portofolio” harus diperiksa ke API: jika satu metrik adalah *total* dan satu lagi *yang sudah berskor*, teks harus mencerminkan definisi aktual, bukan sekadar menyeragamkan angkanya.

**Tidak dalam scope:** mengubah endpoint ringkasan, formula agregasi, kategori skor, strategi rekomendasi, atau urutan bisnis prioritas.

---

## 8. Data UMKM dan Detail UMKM (FR-11)

### 8.1 Data UMKM `/umkms`

- Tabel desktop dan card tablet/mobile hasil UI-2 **tetap menjadi sumber presentasi utama**. UI-3 hanya boleh merapikan heading, section padding/density, ringkasan, empty states, drawer/modal dan feedback.
- Tabel desktop: judul kolom, numeric alignment, status, hover, loading rows, pagination, nama panjang, menu aksi—tidak kehilangan keterbacaan.
- Card tablet/mobile: nama panjang wrap, detail penting terbaca, skor kosong vs 0 dibedakan, three-dots tetap netral, target sentuh aman.
- Modal create/edit/delete/archive (sesuai fitur checkout): field, validasi, tombol utama/sekunder, urutan fokus, konfirmasi risiko, dan ukuran pada 390px harus konsisten dengan UI-1. **Jangan mengubah arti tindakan Hapus/Arsip** atau menambah operasi yang tidak tersedia.

### 8.2 Detail UMKM

- **Masalah baseline:** desktop menampilkan sedikit informasi di satu card lalu menyisakan bidang kosong yang terlalu luas. Atur ulang informasi **yang sudah tersedia** menjadi sections ringkas: identitas/konteks, status penilaian, akses ke kuesioner/enam faktor, histori jika memang tersedia, serta state belum dinilai.
- Gunakan *progressive disclosure* dan section grouping; jangan mengarang widget baru, skor, histori, atau rekomendasi bila API tidak menyediakannya.
- Dua pilihan penilaian harus dibedakan lewat label/deskripsi singkat yang sesuai kontrak: **Kuesioner Tiga Dimensi** vs **Penilaian Enam Faktor**, dan tautan menuju entry point nyata dari UI-2.
- Breadcrumb membawa konteks daftar dan query `list` sebagaimana UI-2. Teks nama usaha panjang dibungkus tanpa menggeser tombol.
- State tanpa penilaian harus informatif dan menunjuk aksi yang benar; jangan mengisi ruang kosong dengan dekorasi saja.

---

## 9. Kuesioner Tiga Dimensi `/form-data` (FR-12)

- Picker UMKM harus terlihat sebagai langkah awal yang jelas. Jika UMKM belum dipilih, tampilkan **empty state terpusat dan informatif**; jangan sisakan panel kanan besar aktif bersanding dengan area kiri kosong tanpa konteks.
- Pada saat UMKM terpilih, komposisi halaman harus menyeimbangkan form aktif, panel perhitungan langsung, progress dan panduan; pada mobile panel pendukung mengikuti form, tidak memblokir input.
- Urutan tiga dimensi/step, pertanyaan, pilihan jawaban, progress, status terakhir, tombol aksi dan hasil dihormati seperti sekarang. Jangan mengubah jumlah pertanyaan, bobot skor, default input atau kontrak finalisasi.
- Perjelas visual **Draft / Final / Batal** dan perbedaan “terjawab”, “tersimpan”, “terfinalisasi”; jangan mengklaim auto-save kalau alur yang ada membutuhkan tombol simpan.
- Jika ada draft belum tersimpan, dirty-form navigation guard UI-0/UI-2 harus tetap aktif untuk menu, breadcrumb, Back/Forward dan direct link.
- Inline validation/error tidak menyebabkan seluruh form bergeser berlebihan atau CTA menghilang; fokus menuju field bermasalah.
- Loading picker, kuesioner, evaluasi terbaru, dan hasil yang berbeda tetap independen; skeleton tepat area dan pengujian network slow.
- Teks panduan (baseline sempat memakai font 10–11px pada panel kontras) harus dibesarkan/dirapikan agar mudah dibaca pada mobile.

---

## 10. Penilaian Enam Faktor (FR-13)

Rute kontekstual mengikuti checkout terbaru (handoff UI-2 melaporkan `/umkms/{id}/six-factor-evaluations`). Form memiliki enam faktor, skala/nilai, state Draft/Final/Batal, autosave (sesuai implementasi) serta histori/result. **Seluruh perilaku bisnis dipertahankan**.

### 10.1 Slider dan state yang tidak ambigu

- **Temuan baseline terpenting:** state `Belum dipilih` terlihat seperti thumb slider sudah berada pada nilai paling kiri/1. Pengguna tidak boleh mengira nilai minimum tersimpan sebelum memilih.
- Tampilkan **unset state yang secara visual berbeda** dari nilai sah pertama: misalnya rail/thumb netral dan label jelas `Belum dipilih` serta indikator “Pilih nilai” (implementor pilih solusi yang kompatibel dengan komponen existing dan akses keyboard). Jangan menetapkan nilai 1 secara diam-diam.
- Bila pengguna memilih, tunjukkan nilai numerik yang benar dan status saving/saved/error sesuai respons yang benar-benar ada. Jangan menampilkan “Tersimpan” jika request belum sukses.
- Kontrol `Kosongkan`/reset harus memiliki affordance jelas namun tidak merebut lebar slider pada mobile; boleh mengatur ulang placement tanpa mengubah efek operasi.
- Nilai minimum/maksimum, pengelompokan enam faktor, dan label harus utuh pada mobile/320px, tablet, desktop.
- Semantik form, label input, focus visible, keyboard arrow operations, touch dragging, dan `aria-valuenow`/label nilai harus benar; state unset membutuhkan representasi aksesibel yang tidak menyesatkan.

### 10.2 Results, chart, histori

- Radar chart dan label faktor tidak saling menimpa; periksa baseline yang menunjukkan label atas terlalu dekat dengan sumbu/angka. Jika ruang sempit, atur padding, radius, label wrapping/abbreviation dengan legend penjelas, atau proporsi chart—**tanpa** mengubah nilai/seri.
- Pertahankan tampilan hasil terbaru, draft/final, histori, dan modal detail historis; perbaiki keterbacaan metadata, angka, tanggal, CTA, dan scroll dialog.
- Jika tidak ada penilaian atau evaluasi sudah dibatalkan, state menggambarkan keadaan server; jangan menampilkan chart sintetis.
- Autosave/race/abort, switching UMKM, finalize, cancel, reset, serta confirmation guard tetap mengikuti hasil UI-0/UI-2.

**Verifikasi wajib:** nilai unset tetap unset sampai input sah, nilai sah pertama tetap bisa dipilih, autosave state akurat, Draft/Final/Batal/riwayat dan hasil tidak berubah secara kontrak.

---

## 11. Halaman Analitik (FR-14)

### 11.1 Factor Profile `/factor-profile`

- Perjelas hierarki picker UMKM → ringkasan performa → grafik profil faktor → insight/legend yang benar-benar ada.
- Pastikan label, skala, tooltip, legend dan tabel pendamping dapat dibaca di 390px; angka/warna profil milik UMKM tidak tertukar dengan portofolio.
- **Temuan baseline:** skor faktor terlihat tersedia sementara suatu ringkasan menampilkan `—/100`. Periksa definisi dan response mapping. Jika agregat memang belum tersedia, tampilkan microcopy seperti “Belum tersedia” (berdasarkan real state); jika data ada tetapi salah dirender, perbaiki presentasi saja.
- Saat tidak ada UMKM dipilih, tampilkan arahan picker dan area kosong yang proporsional; tidak membuat chart kosong semu.

### 11.2 Distribution `/distribution`

- Perjelas urutan total/skor/kategori lalu grafik, legend dan konteks pengukuran. Penggunaan cyan/orange/hijau hanya untuk membedakan kategori atau semantik data.
- Label grafik, jumlah, persentase, dan total harus konsisten secara semantik; legend jangan terlalu kecil pada mobile.
- Atur visualisasi agar tidak clipping pada 390px maupun 768px, dan tidak memakan ruang berlebihan jika datanya sedikit.
- Empty data dan error lokal memberi penjelasan yang jujur tanpa angka/diagram dummy.

### 11.3 Comparison `/comparison`

- Alur pilih UMKM dan tambah/hapus pembanding harus mudah diikuti; tampilkan helper dan state minimal sebelum ada pilihan.
- Grafik, tabel ringkasan, legenda, warna seri, dan label panjang tidak saling menimpa; jangan menjadikan warna saja sebagai pembeda pembanding.
- Responsiveness: pada mobile, panel selector, daftar pembanding, chart, tabel dan aksi mempunyai urutan baca yang masuk akal. Jika chart memerlukan scroll horizontal terlokalisasi, sediakan indikasi dan alternatif teks/tabel; tidak boleh membuat seluruh `body` overflow.
- Hanya tampilkan data pembanding milik pengguna aktif dan hasil dari API; tidak menambah cara agregasi atau perubahan query semantics.

### Prinsip bersama grafik

- Pertahankan library ApexCharts dan opsi yang sudah dipakai selama tidak terbukti memblokir rendering/accessibility. **Jangan rewrite charting library** hanya untuk UI-3.
- Font chart tetap Poppins; tooltip, legend, sumbu, label dan table alternatif konsisten UI-1.
- Tinggi dan margin chart responsif terhadap ruang nyata; chart tetap dapat digunakan pada loading, no-data, partial data, error/retry.
- Hancurkan chart saat unmount UI-0; hindari observer/listener duplikat atau chart render pada container ukuran nol.

---

## 12. UX states & responsive matrix (FR-15)

### 12.1 Required states per component

| Komponen | Minimal state |
|---|---|
| KPI / ringkasan | loading skeleton, success, partial/no-data, error lokal |
| Picker UMKM | loading, pilihan valid, kosong, pencarian tidak cocok, error |
| Chart | loading, data lengkap, data parsial bila didukung, kosong, error/retry |
| Kuesioner | belum pilih UMKM, Draft, Final, validation/error, progress, dirty guard |
| Enam Faktor | unset, dipilih, autosaving, saved, save failure, Draft/Final/Batal, histori/result |
| Create/edit UMKM | default, invalid, submitting, success, server error, nama panjang |
| Login/register | default, invalid, submitting, auth/network error, font fallback |

### 12.2 Viewport minimum

| Viewport | Target verifikasi |
|---|---|
| **1440×900** | Desktop page structure, whitespace/density, dashboard/chart, dialog |
| **768×1024** | Tablet drawer UI-2, section stacking, charts, picker/form |
| **390×844** | Mobile-first readability, touch, navigation, dialog, chart labels |
| **320×~700** | Stress test clipping / wrap (wajib pada rute dan komponen kritis) |
| **1050/1051px** | Breakpoint continuity regression UI-2, tanpa layout jump/overflow |
| **200% zoom** | Keterbacaan dan kontrol tetap dapat diakses pada halaman utama; catat viewport efektif |

Setiap screenshot harus berasal dari aplikasi yang benar-benar berjalan. Gunakan data QA sintetis/terkontrol, tetapi tidak boleh menyamakan data *before* dan *after* jika fixture berbeda tanpa menyatakan perbedaannya. Bila perbandingan pixel diperlukan, fixture/viewport/font/rendering harus identik.

---

## 13. Non-goals dan larangan perubahan

**UI-3 tidak mencakup:**

1. Rewrite ke React/Vue/Inertia/SPA; mengganti partial Blade navigation/persistent shell.
2. Mengubah Sanctum, token/auth model, session validation, ownership, route API, schema, migration, seeder, dan data production.
3. Mengubah scoring, kuesioner aktif, skala enam faktor, kategori, rumus, aturan Draft/Final/Batal, histori, atau autosave semantics.
4. Membuat KPI baru, halaman baru, metrik sintetis, fitur bantuan/notifikasi/profil yang belum ada, atau alur bisnis tambahan.
5. Mendesain ulang sidebar/drawer/filter/card-list yang sudah PASS UI-2 atau membuat sistem ikon/font/token kedua.
6. Total redesign landing/auth; hanya refinement visual komposisi yang sudah ada.
7. Menghapus, mengarsipkan, memfinalisasi, atau mereset data nyata demi screenshot; gunakan fixture di lingkungan testing.
8. Perbaikan lima tes seeder baseline atau optimasi chunk ApexCharts ~938kB bila tidak menjadi blocker spesifik dan terbukti untuk UI-3.
9. Auto commit, push, deploy, publish, atau menjalankan migrasi destruktif tanpa instruksi pengguna.

Jika ada masalah baru yang akar penyebabnya di API/business logic, **laporkan sebagai defect terpisah** dengan reproduksi, bukan menambal domain logic diam-diam dalam UI-3.

---

## 14. Acceptance criteria — UI3-AC01 hingga UI3-AC16

Setiap AC harus memiliki hasil aktual (`PASS`/`FAIL`/`BLOCKED`/`NOT RUN`) beserta bukti. Status fase **PASS** hanya bila semua **gate wajib** tercapai dan tidak ada regresi baru dari UI-3.

| ID | Gate wajib | Bukti minimum |
|---|---|---|
| **UI3-AC01** | **Consistency:** seluruh halaman pakai token UI-1, Poppins, SVG, neutral-first dan tidak ada variasi visual acak di komponen page-specific | CSS/DOM audit + screenshot route inventory |
| **UI3-AC02** | **Global hierarchy:** heading, section, card, spacing, leading, label/CTA mudah dipindai; tidak ada clipped text atau whitespace ekstrem yang tidak bermakna | Baseline vs after 1440/768/390, kritik berbasis screenshot |
| **UI3-AC03** | **Landing:** layout/identitas tetap, mobile hero & CTA responsif, kontras dan overflow memenuhi ketentuan | Screenshot full-page + CTA smoke |
| **UI3-AC04** | **Login/Register:** form cepat terlihat mobile; validasi, password toggle/strength, error dan fallback font tidak rusak | Screenshot default/invalid + browser interactions |
| **UI3-AC05** | **Dashboard:** KPI/hero/prioritas tersusun efektif; mobile density baik; angka/label konsisten data; skeleton independen UI-0 tetap | Screenshot desktop/mobile, API mapping inspection, delayed-request test |
| **UI3-AC06** | **Data UMKM:** table/card UI-2 tidak regresi; dialog CRUD dan long-name, empty/loading/error/pagination tampak konsisten | Browser CRUD-on-fixture + responsive screenshot |
| **UI3-AC07** | **Detail UMKM:** ruang terpakai secara informatif, kedua metode penilaian dibedakan, breadcrumb dan state belum dinilai jelas | Screenshot detail populated/empty + link/context test |
| **UI3-AC08** | **Kuesioner 3 dimensi:** empty state, hierarchy form, status/progress/saving, panel pendukung dan mobile ergonomics jelas; Draft/Final/dirty guard tidak berubah | Browser form E2E + screenshot state |
| **UI3-AC09** | **Six-factor slider:** unset ≠ nilai minimum; numeric label & saved-state benar; reset, keyboard/touch, autosave, guard tidak berubah | E2E unset/value first/saving/error, visual/DOM assertions |
| **UI3-AC10** | **Six-factor chart/histori:** radar label tidak overlap; histori/modal/readability desktop/mobile benar; status Draft/Final/Batal valid | Screenshot chart + historical dialog, browser six-factor regression |
| **UI3-AC11** | **Factor Profile:** picker→chart→ringkasan jelas; legend/angka benar; `—/100` vs faktor diselidiki dengan mapping API, tanpa ubah scoring | API/render comparison + responsive screenshots |
| **UI3-AC12** | **Distribution & Comparison:** grafik, legenda, tooltip, tabel dan kategori terbaca tanpa body overflow; data/warna seri akurat | Screenshot 1440/768/390 + chart interactions |
| **UI3-AC13** | **Loading/error/empty:** komponen data punya skeleton lokal, empty/error honest & retry; tidak ada fullscreen session spinner antarroute | Network throttle/500/403/timeout scenarios + screenshots |
| **UI3-AC14** | **Accessibility & responsive:** keyboard focus, target touch, labels/ARIA, contrast AA smoke, reduced motion, zoom 200%, 320/390/768/1440 & breakpoint UI-2 tanpa clipping | Browser checks + CSS/contrast audit; NOT RUN untuk device/screen reader yang belum diuji |
| **UI3-AC15** | **Regression:** UI-0 partial nav/auth/lifecycle, UI-1 fonts/components, UI-2 grouped navigation/card/filter/breadcrumb, API ownership dan evaluasi tetap PASS | Existing browser runners + JS/build/PHP targeted + per-case full PHP comparison |
| **UI3-AC16** | **Deliverables:** before/after route evidence, manifest, route-state matrix, hasil gate, perubahan file, known gaps, laporan QA, dan handoff lengkap | Artefak ada dan file path dapat dibuka |

### Penilaian gate

- **PASS:** tes dan bukti spesifik berhasil, memenuhi kriteria.
- **FAIL:** implementasi/tes nyata gagal; selesaikan penyebab sebelum verdict akhir.
- **BLOCKED:** tidak bisa diverifikasi karena dependency/lingkungan yang diidentifikasi; jangan klaim PASS.
- **NOT RUN:** pengujian tidak dijalankan; jangan diasumsikan lulus.
- **Known baseline:** lima kegagalan seeder historis bisa dicatat terpisah **hanya setelah** perbandingan JUnit per kasus menunjukkan tidak ada regresi baru.

---

## 15. Test strategy dan quality gates

### 15.1 Prasyarat

1. Periksa `git status`, current branch, uncommitted changes, route inventory dan UI-2 evidence lokal. Jangan reset/clean paksa.
2. Ambil baseline screenshot **checkout pasca UI-2, pra UI-3** dengan fixture stabil pada viewport wajib.
3. Verifikasi environment QA terisolasi memakai `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=joyumkm_testing`, `DB_URL` kosong (atau nama database testing aktual yang diverifikasi), sebelum menjalankan seed/mutation fixture.
4. Jangan menjalankan browser test dan PHPUnit secara bersamaan pada database yang sama. Handoff UI-0/UI-2 mencatat keduanya menggunakan MySQL testing lokal.
5. Baca cara mount/unmount dari `workspace.js` dan dokumentasikan pengaruh UI-3 terhadap lifecycle jika ada perubahan JS.

### 15.2 Build dan test commands acuan

Perintah final disesuaikan pada checkout nyata; baseline handoff UI-2 memakai:

```powershell
npm run build
npm test
php artisan view:cache --no-interaction
php artisan route:list --except-vendor --no-interaction
php artisan test --compact --filter='(AuthApiTest|OwnershipApiTest|UmkmApiTest|UmkmSummaryApiTest|EvaluationApiTest|DashboardApiTest|SixFactorEvaluation)'
php artisan test --compact --log-junit <path-file-junit>
git diff --check
```

Browser runner yang sudah ada dan **harus dijalankan ulang** (menurut handoff UI-2):

```powershell
node tests/js/workspace-browser.mjs
node tests/js/ui-foundation-browser.mjs
node tests/js/ui-navigation-browser.mjs
node tests/js/six-factor-browser.mjs
```

Tambahkan runner **UI-3 page refinement** (nama bebas, contoh `tests/js/ui-page-refinement-browser.mjs`) untuk menguji acceptance criteria yang belum tercakup runner lama. Gunakan Playwright/Chrome lokal yang memang tersedia, jangan menyatakan browser lain PASS tanpa dijalankan. Browser QA harus menguji perilaku nyata, bukan hanya memeriksa screenshot hasil static mockup.

### 15.3 Browser cases minimum

1. Public landing 1440/768/390/320: hero, CTA, typography, no overflow.
2. Login/register 390: form above-the-fold yang lebih baik dari baseline, empty validation, toggle, error, keyboard/tab.
3. Dashboard 1440/390: hero/KPI/legend/prioritas, KPI slow + chart ready, lalu kondisi sebaliknya, no layout jumps besar.
4. UMKM 1440 table / 768 & 390 card: filter, sort, search, edit/create modal, long-name, empty/error, Back/Forward tanpa duplicate data.
5. Detail UMKM: populated vs no-score, kedua penilaian, breadcrumb `list` context.
6. Kuesioner: belum pilih UMKM → pilih → 3 dimensi → draft → final (fixture test) + confirmation saat keluar dengan unsaved change.
7. Six-factor: unset → pilih nilai pertama → save state → reset → Draft/Final/Batal → histori; race request dan navigation guard.
8. Radar: label/scale tidak overlap pada mobile; data legend benar, modal historical accessible.
9. Factor Profile & Distribution: picker/data ada/kosong, legend/sumbu, caption, status partial tidak membuat nilai palsu.
10. Comparison: 0/1/beberapa UMKM, nama panjang, chart/table, mobile reading order & local scroll.
11. Header/sidebar tetap sama instance pada internal navigation; 0 extra `/auth/me` untuk klik normal, expired logout tidak bocor.
12. Keyboard & reduced motion; viewport 320/390/768/1050/1051/1440; zoom browser; audit console/unhandled promise.

### 15.4 PHP baseline

Handoff UI-2 melaporkan 197/202 lulus dengan lima kasus seeder baseline yang sama:

- `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores`
- `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture`
- `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations`
- `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample`
- `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data`

**Wajib** bandingkan per-case JUnit sebelum/sesudah dan sebutkan jumlah/status/assertion aktual. Tidak ada legitimasi mengubah tes seeder untuk membuat UI-3 PASS, dan tidak ada legitimasi menyatakan seluruh suite PHP lulus jika tetap 197/202.

---

## 16. Evidence & handoff structure

Gunakan direktori contoh (adaptasi nama jika organisasi repo saat ini berbeda):

```text
docs/evidence/ui-3/
├── handoff.md
├── verification-report.md
├── route-state-inventory.md
├── before-after-index.md
├── visual-findings.md
├── screenshots/
│   ├── before/
│   │   ├── public/
│   │   └── authenticated/
│   ├── after/
│   │   ├── public/
│   │   ├── authenticated/
│   │   └── states/
│   └── comparisons/
├── browser-results.json
├── api-render-mapping.md
└── regression-baseline-comparison.md
```

### Evidence screenshot minimal

| Domain | Screens wajib |
|---|---|
| Publik | Landing/login/register × 1440, 768, 390; mobile validation login/register |
| Dashboard | 1440, 768, 390; KPI loading vs chart ready, empty/error bila relevan |
| UMKM | List desktop + card mobile/tablet, detail populated/unscored, create/edit/delete-or-archive modal |
| Kuesioner | belum pilih, tiap dimensi, selected, Draft/Final, dirty guard/validation, mobile layout |
| Six-factor | unset, minimum valid, autosave, radar, history modal, 390 + desktop |
| Analitik | factor profile, distribution, comparison masing-masing 1440/768/390 dan no-data/error saat relevan |
| Edge | 320 text wrap, 1050/1051 transition, zoom, long name, fallback font jika CSS berubah |

Sertakan route, query/fixture, state, viewport, nama file, timestamp, status dan expected-vs-observed di manifest. Hindari token, PII nyata, cookies/storage browser, API auth headers, screenshot alat developer berisi credentials. Jangan menyimpan session file ke repo.

### Handoff wajib

- Ringkasan tujuan dan keputusan implementasi yang benar-benar diambil.
- Matriks route/page/state dan status tiap AC.
- Daftar file berubah dengan alasan, visual before/after, screenshot index.
- Perintah test dan hasil aktual, jumlah tes/assertion serta perbandingan baseline PHP per kasus.
- Keamanan dan no-business-logic-change statement (diverifikasi dari diff dan tests, bukan sekadar deklarasi).
- Known issues, batas QA, browser/platform yang belum diuji, sumber dan prioritas follow-up untuk UI-4.
- Verdict final **PASS**, **FAIL**, atau **BLOCKED**—tidak boleh PASS bila acceptance criterion wajib belum terverifikasi.

---

## 17. Urutan pekerjaan internal (bukan fase roadmap baru)

| Workstream | Pekerjaan | Exit gate |
|---|---|---|
| `UI3-W1` | Audit checkout UI-2 + baseline visual + route-state inventory + API/render mapping penting | Baseline dan cakupan valid |
| `UI3-W2` | Refinement publik + Dashboard + Detail UMKM + list dialog states | AC01–AC07 progres dan QA lokal |
| `UI3-W3` | Refinement Kuesioner Tiga Dimensi + Enam Faktor (unset/autosave/chart/histori) | AC08–AC10 + domain regression |
| `UI3-W4` | Refinement Factor Profile, Distribution, Comparison + chart/text readability | AC11–AC12 + data fidelity |
| `UI3-W5` | State/error/loading, accessibility, Playwright responsive, regression, screenshot, handoff | AC13–AC16 PASS |

Implementor boleh menata ulang urutan bila dependencies mengharuskan, tetapi semua scope yang disepakati tetap wajib selesai tanpa meminta persetujuan mikro untuk pekerjaan rutin. Jika menemui bug di luar scope atau hambatan lingkungan, catat, lanjutkan area independen yang aman, dan jangan menyatakan PASS tanpa verifikasi.

---

## 18. Risks, mitigations, and guardrails

| Risiko | Mitigasi wajib |
|---|---|
| Mengubah UI-2 sambil merapikan halaman | Reuse shell/list/filter; regression runner UI-2 setelah perubahan |
| Spinner penuh muncul lagi | Tidak menambah guard/overlay global, pantau `/auth/me` pada internal navigation |
| Poppins/chart label clipping | Ukur bbox, cek mobile/zoom dan baseline screenshots; responsive chart configuration |
| Slider unset tidak jelas | State visual + semantik eksplisit, test nilai pertama dan reset tanpa auto-value |
| Microcopy mengubah arti data | Verifikasi payload/definition sebelum mengganti teks; jangan ubah formula |
| Layout “diisi” data fiktif | Tampilkan hanya data real atau empty state yang jujur |
| Skeleton berubah menjadi flicker | Ukuran skeleton mendekati konten, mount lifecycle UI-0, request race checks |
| Chart Reactivation/leak | Cleanup ApexCharts pada unmount, hindari observer/listener ganda |
| Mutasi pada database development/production | QA terisolasi; fixtures dan test account sintetis, verified DB sebelum run |
| Chunk ApexCharts besar | Catat sebagai tech debt; optimasi hanya jika ada kasus terukur/masuk scope |
| Baseline seeder test failures | Bandingkan JUnit per-case; jangan ubah tes atau mengklaim full suite PASS |
| Pekerjaan lintas platform belum diuji | Catat NOT RUN untuk Firefox/Safari/real device/screen reader; bawa ke UI-4 |

---

## 19. Definition of Done — GO / NO-GO

**UI-3 boleh dinyatakan PASS bila seluruh poin berikut terpenuhi:**

1. **`UI3-AC01` sampai `UI3-AC16` semuanya PASS**, dengan artefak aktual yang dapat diperiksa.
2. Seluruh halaman utama publik dan authenticated pada route inventory sudah mengalami visual QA dengan before/after (minimal desktop/tablet/mobile sesuai matriks), bukan hanya dashboard.
3. Kejelasan slider unset, chart/radar mobile, penyajian form, empty state Detail UMKM, KPI label, dan auth mobile sudah dibuktikan dengan test/screenshot.
4. UI-0 tetap PASS (partial navigation, auth, persistence, abort/skeleton, Back/Forward), UI-1 tetap PASS (font/tokens/SVG), UI-2 tetap PASS (nav/sidebar/drawer/search/filter/card list).
5. Tidak ada perubahan API/DB/scoring/ownership/workflow yang tak disetujui. Build, JS, browser runs, PHP targeted PASS, dan full PHP suite tidak memiliki regresi baru dari UI-3 dibanding baseline per-case.
6. Tidak ada overflow/clipping baru atau error JavaScript tidak tertangani pada viewport wajib; accessibility smoke lulus.
7. Semua evidence, halaman/fase yang belum diuji, dan laporan handoff tersedia. **Jangan** menyamarkan batas QA platform ekstra sebagai pengujian yang sudah dilakukan.

**NO-GO:** ada gate wajib FAIL/BLOCKED/NOT RUN, ada regresi keamanan/bisnis/navigasi, screenshot tidak tersedia, atau klaim perbaikan yang tidak didukung bukti. `PASS lokal` boleh dipakai bila kondisi di atas lengkap pada lingkungan lokal, dengan cakupan perangkat/browser dinyatakan terang.

---

## 20. Handoff ke UI-4 — Full Visual QA & Regression

UI-4 nantinya bertugas melakukan **audit akhir menyeluruh** lintas halaman/state dengan inventaris screenshot baru, regresi UX fungsional, perbandingan visual, aksesibilitas, dan jika tersedia cross-browser/physical device coverage. UI-3 harus menyerahkan route inventory, before/after screenshot, evaluasi setiap temuan, dan residual risk supaya UI-4 tidak perlu mengulang discovery dari nol.

**Instruksi terakhir:** selesaikan UI-3 sebagai satu fase refinement visual yang lengkap pada checkout lokal terkini. Jangan mengejar “selesai” hanya karena Poppins/card/sidebar sudah bagus; itu adalah hasil UI-1/UI-2. Bukti UI-3 harus menunjukkan halaman publik, Dashboard, Detail UMKM, Kuesioner, Enam Faktor, dan seluruh Analitik **semuanya** lebih rapi dan usable dengan fitur bisnis tetap aman.
