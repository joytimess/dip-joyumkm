# PRODUCT REQUIREMENTS DOCUMENT (PRD)
# JoyUMKM — Dashboard Workspace Relayout V1

**Status:** Siap handoff implementasi UI (belum diintegrasikan ke repository produksi)  
**Tanggal:** 4 November 2026  
**Jenis pekerjaan:** Redesign/relayout frontend *existing*; bukan penggantian proses bisnis  
**Referensi utama:** `../mockup/index.html` dan `../previews/`  
**Referensi pembanding:** `../references/original-screenshots/`  
**Keputusan warna:** Primary `#0207CA`, Secondary `#0297DE`, Accent `#FC734D`  
**Batasan penting:** Gunakan business logic, skema data, izin, route, instrumen kuesioner, kalkulasi, dan API JoyUMKM yang **sudah ada** sebagai sumber kebenaran.

---

## 1. Ringkasan eksekutif

Dashboard JoyUMKM saat ini memiliki 6 area kerja: Home, Factor Profile, Distribution, Comparison, UMKM & Recommendations, dan Form Data. Pengguna menghendaki **relayout menyeluruh** agar konsisten dengan identitas visual baru JoyUMKM, lebih nyaman untuk kerja pendamping UMKM, dan responsif untuk desktop/tablet/mobile.

Perubahan harus fokus pada presentasi, hierarki informasi, pola navigasi, komponen reusable, states, dan usability. **Jangan menghapus atau menyederhanakan fitur yang sudah ada demi mengikuti mockup.** Mockup dalam ZIP adalah *visual/interaction reference*, bukan source code yang wajib ditempel mentah ke aplikasi maupun acuan formula bisnis.

### Sasaran hasil

1. Seluruh 6 halaman menggunakan design tokens yang sama, tanpa sisa warna ungu dominan dari desain lama.
2. Sidebar/topbar/kartu/grafik/tabel/filter/form punya tampilan dan interaksi konsisten.
3. Tersedia UX responsif dari lebar 320 px sampai desktop ultrawide.
4. Fungsi existing tetap berjalan: pencarian UMKM, filter, pemilihan, pengurutan, grafik, perbandingan, CRUD sesuai hak akses, kuesioner, draft, finalisasi sesuai rule, dan live calculation.
5. Tidak membuat API/DB/scoring baru tanpa bukti kebutuhan dari repository. Integrasi dilakukan terhadap kontrak yang ditemukan di codebase.

## 2. Scope dan prioritas

### In scope — P0

- Dashboard shell: sidebar, topbar, breadcrumbs/contextual heading, content container, footer, mobile drawer.
- Design system dan komponen UI reusable: tombol, cards, KPI, badges, inputs, selects, tabs, data table, pagination, empty/loading/error states, modal/dialog, toast, progress, tooltip/legend.
- Home, Factor Profile, Distribution, Comparison, UMKM & Recommendations, Form Data.
- Chart relayout dan legend adaptif; format lokal Indonesia untuk angka dan mata uang.
- Wiring UI ke state/API/logic existing, tanpa menjadikan data demo sebagai sumber kebenaran.
- Perbaikan aksesibilitas dan keyboard navigation pada elemen yang diubah.

### In scope — P1 bila tersedia di existing implementation

- Search global yang nyata (bukan sekadar input dekoratif), filter lanjutan, sticky form summary, role-gated actions, dan feedback proses simpan.
- Animasi mikro ringan (hover/fade-up/transisi panel, **bukan** animasi mengganggu), menghormati `prefers-reduced-motion`.
- Polesan titik pertemuan layout di tablet dan mobile, skeleton loading, dialog konfirmasi.

### Out of scope

- Rebuild backend, ubah schema migration, redefinisi faktor INS/ECT/OPS, ubah bobot/kategori skor, aturan final/draft, auth/role, atau hak akses.
- Redesign landing page/login/register yang telah dibahas pada scope terpisah.
- Memasukkan dummy data dari mockup ke database produksi.
- Menambah fitur backend baru hanya untuk mengejar komponen visual.
- QA agent, full regression suite, atau loop pengujian berat. Fokus implementasi; hanya smoke-check ringan secara proporsional bila diperlukan.

## 3. Persona dan kebutuhan

**Pendamping UMKM:** mengawasi portofolio, menemukan UMKM yang perlu perhatian, masuk ke detail dan evaluasi tanpa membuang waktu.  
**Pengelola/role lain jika ada di codebase:** hanya melihat aksi dan data sesuai policy existing; jangan mengasumsikan semua user dapat menghapus atau memfinalkan.

## 4. Identitas visual dan design tokens

| Token | Nilai | Pemakaian |
|---|---|---|
| `--brand-primary` | `#0207CA` | Sidebar gradient, navigasi aktif, CTA primer, fokus pada data utama |
| `--brand-secondary` | `#0297DE` | Perbandingan, progress/plot, ilustrasi sekunder, garis pembeda |
| `--brand-accent` | `#FC734D` | Sorotan tindakan/insight, dekorasi, urgensi non-destruktif |
| `--text-primary` | `#101E40` | Heading, angka skor, teks utama |
| `--text-muted` | `#70819D` | Label, metadata, penjelasan |
| `--canvas` | `#F4F7FC` | Background workspace |
| `--surface` | `#FFFFFF` | Card, popover, form |
| `--border` | `#E8EDF6` | Separator dan border dasar |
| `--soft-blue` | `#EAF6FF` | Tinted highlight sekunder |
| `--soft-orange` | `#FFF1EB` | Tinted highlight accent |

**Catatan penamaan:** user menyebut `#0207CA` sebagai *Midnight Black*; dalam implementasi tetap gunakan **hex persis**. Secara visual ini biru gelap jenuh, bukan warna hitam literal. Jangan menggantinya dengan warna hitam berdasarkan nama.

**Kontras:** hindari teks kecil berwarna `#0297DE` atau `#FC734D` langsung di latar putih jika kontras kurang; gunakan variasi lebih gelap untuk teks atau jadikan warna tersebut background/ornamen. Status baik/peringatan/kritis boleh memakai warna semantik tambahan, **jangan** memaksa status kritis berwarna biru hanya karena palet brand. Setiap status juga wajib punya label teks.

**Komponen:** radius kartu ~20 px, radius tombol/input ~11–12 px, shadow tipis, whitespace konsisten 8/12/16/24/32, heading tebal dengan kontras jelas, ikon stroke konsisten. Typeface gunakan font project yang sudah ada; prototype memakai fallback `DM Sans` dan `Plus Jakarta Sans` namun tidak boleh memerlukan font remote untuk bisa digunakan offline.

**Grafik:** seri UMKM menggunakan warna yang berbeda dan tetap dibedakan lewat legend/label; garis baseline portofolio disarankan dashed. Untuk lebih dari 3–5 seri, pastikan data tetap dapat dibaca tanpa bergantung pada warna.

## 5. Global application shell

- **Desktop >= 1050 px:** sidebar tetap kiri ~250 px; gradient primary; identitas JoyUMKM; section `Ruang Kerja`; item aktif putih / teks primary. Sidebar bawah untuk Profil, Help, Logout jika fitur tersebut memang tersedia.
- **Topbar:** tombol toggle, pencarian UMKM (jika tersedia global), user avatar/menu/notification berdasarkan fitur yang ada. Jangan tampilkan ikon dengan action kosong; jika belum tersedia, sembunyikan atau tampilkan sesuai pola disabled yang benar.
- **Body:** max-width nyaman, background canvas, heading halaman, konten modul dengan card surface putih.
- **Tablet < 1050 px:** sidebar menjadi drawer yang bisa dibuka/ditutup; scrim; Esc dan overlay dismiss.
- **Mobile < 780 px:** paddings dan gaps berkurang, dua kolom berubah menjadi satu, tombol/tabs dapat wrap atau scroll dengan petunjuk; topbar tetap usable.
- **Small mobile <= 520 px:** metrik maksimal dua kolom ketika cukup; paling sempit gunakan satu kolom bila tidak aman; grafik dan tabel tidak boleh memotong konten.
- **Tabel lebar:** gunakan horizontal scroll di container dengan indikasi, atau table-to-card bila project telah punya pola tersebut; **jangan hilangkan kolom aksi**.
- **Responsive:** semua elemen clickable memiliki target tap layak; gunakan text wrapping, `min-width:0`, overflow control.
- **Accessibility:** semantic heading, `label` input, focus-visible, aria-expanded untuk drawer, aria-current nav aktif, aria-label untuk ikon, ESC modal; warna bukan satu-satunya penyampai informasi.
- **Motion:** durasi sekitar 180–350ms untuk transisi kecil, 1x fade-up saat content masuk; hormati reduced motion; jangan pasang animasi chart berulang.

## 6. Page requirements

### 6.1 HOME — Ringkasan & orientasi pendamping

**Komposisi:** welcome hero gradient primary-to-secondary, konteks portofolio, CTA `Kelola UMKM`; deretan KPI; sektor yang membutuhkan perhatian; ringkasan evaluasi/draft; daftar UMKM prioritas/shortcut pekerjaan.

**Data wajib mengikuti domain existing:** total responden/UMKM yang dapat diakses user, jumlah dinilai vs belum, rerata skor kesehatan berbasis aturan existing, jumlah draft/semua status yang relevan, sektor dengan rerata terendah jika bisa dihitung secara valid.

**Perhatian label:** screenshot lama memuat `Sektor Kritis` untuk *Grosir & Retail* dengan skor 91,7/100 dan keterangan `Terkendali`. Untuk redesign, gunakan label yang tidak menyesatkan seperti **`Sektor prioritas perhatian` / `Rerata sektor terendah`** saat kondisinya bukan kategori kritis. Jika memang produk memiliki label baku `Sektor Kritis`, pertahankan nomenklatur hanya dengan definisi bisnis existing yang terverifikasi.

**CTA:** `Kelola UMKM` menuju daftar existing; aktivitas pending menuju action relevan; angka bukan decorative fake.

**States:** 0 UMKM -> empty state dengan CTA yang diizinkan; data parsial/draft -> indikator jelas; error fetch -> retry action existing; no score -> `—` bukan 0 palsu.

### 6.2 FACTOR PROFILE — Profil faktor per UMKM

**Komposisi:** heading, pemilih UMKM searchable bila layanan mendukung, rangkaian ringkasan skor, radar chart INS/ECT/OPS vs baseline portofolio, legend, tabel skor numerik & status, shortcut ke Form Data.

**Perilaku:** memilih UMKM memperbarui seluruh visual; series target dan portfolio baseline dibedakan jelas; nilai kosong tidak diplot sebagai 0 tanpa aturan resmi; tooltip/legend terbaca; gunakan status `Sementara`/`Final` dari field existing.

**Rule khusus:** contoh lama menunjukkan `ayam geprek` menjawab **16/16** tetapi statusnya **Sementara**. Jadi **jumlah pertanyaan yang terjawab tidak boleh otomatis dianggap final**; harus mengikuti state machine existing.

**Fallback:** bila UMKM terpilih terhapus/tidak lagi terotorisasi, reset ke pilihan sah; tanpa faktor bernilai, tampilkan empty chart yang jujur.

### 6.3 DISTRIBUTION — Distribusi kesehatan dan sektor

**Komposisi:** donut kategori kesehatan disertai legend dan count, total responden; bar chart rerata skor per sektor; bagian penjelas jumlah sampel per sektor/kelengkapan evaluasi; adaptasi pada viewport sempit.

**Aturan data:** denominator dari daftar UMKM sesuai scope akses dan aturan status yang berlaku; hindari double-count; bar sektor dengan skor kosong memakai `Tidak ada skor`/placeholder, bukan 0 seolah buruk; baseline dihitung oleh service yang ada (atau fungsi frontend identik dengan aturan terverifikasi apabila sebelumnya memang begitu).

**UX:** donut center menunjukkan total; legend tidak hanya warna; ranking sektor diberi nama lengkap; chart kecil bisa scroll horizontal bila diperlukan.

### 6.4 COMPARISON — Bandingkan hingga lima UMKM

**Komposisi:** selector searchable, tombol tambah, pilih otomatis, kosongkan pilihan, chips pilihan yang dapat dihapus; grouped bar INS/ECT/OPS, dashed baseline portofolio, tabel numerik detail di bawah grafik.

**Batas:** maksimal **5 UMKM** (sesuai UI lama); tombol tambah disabled dan pesan informatif saat mencapai batas; tidak boleh duplikasi pilihan. `Pilih otomatis` menentukan daftar dari aturan UI existing jika ada, jika belum ada maka pilih secara deterministik dan dokumentasikan pada layer presentasi saja.

**States:** 0 pilihan: empty state; 1–5 pilihan: grafik adaptif; baseline tetap independen dari item yang dipilih kecuali domain existing menyatakan lain; missing factor = `Belum dijawab` / `—`, jangan diisi nol palsu.

**Aksesibilitas:** skema multiwarna tetap dibantu label/legend; tabel sebagai pelengkap grafik; nama UMKM panjang terpotong hanya secara visual dan tersedia lengkap via title/tooltip yang semestinya.

### 6.5 UMKM & RECOMMENDATIONS — Manajemen portofolio

**Komposisi:** page heading dan `Tambah Data` bila berizin, filter pencarian/sektor/status, tabel dengan Nama, Lokasi, Sektor, Omzet, Skor Kesehatan, Faktor Utama, Rekomendasi/Status, Aksi; pagination; ringkasan KPI bawah (bila layak); modal create/edit/delete yang reuse form existing.

**Fitur wajib dipertahankan:** sorting skor, pencarian, filtering, pagination (server/client mengikuti project), tambah, ubah, hapus dengan konfirmasi dan policy, lihat profil faktor, isi/ubah kuesioner.

**Rekomendasi:** teks rekomendasi mengikuti algoritma/business rules existing; jangan mengganti rekomendasi dengan kategori skor umum tanpa persetujuan. Status draft/final dan rekomendasi dapat berbeda.

**Mobile:** horizontal scroll atau card-list dengan semua aksi penting; menu `⋯` boleh menampung aksi agar tidak berdesakan, tetapi aksi destructive tetap perlu konfirmasi.

**Safety:** hapus data hanya atas izin. Hindari double-submit, tampilkan loading/progress dan hasil/error API yang benar. Data di UI harus mengikuti perubahan terakhir dari server.

### 6.6 FORM DATA — Kuesioner dan evaluasi langsung

**Komposisi:** pemilih UMKM, status terkini, tabs INS/ECT/OPS, daftar pertanyaan sesuai instrumen project, jawaban interaktif dengan state terpilih, kontrol next/prev faktor, panel `Live Calculation` sticky desktop / inline mobile, draft preview, reset, simpan, panel panduan.

**Prioritas fundamental:** **pertanyaan, pilihan jawaban, bobot/normalisasi, denominator, jumlah soal, status draft/final, dan kategori adalah milik aplikasi existing**. Prototype punya 16 pertanyaan ilustratif dengan skor 1–5; **jangan menjadikannya formula resmi**.

**Perilaku:** memilih UMKM memuat jawaban existing; input mengupdate UI dan summary sesuai kalkulasi yang sah; penyimpanan draft tidak otomatis memfinalkan; finalisasi hanya lewat aksi dan validasi existing. Sediakan loading/disabled button dan error handling. `Reset` tidak boleh menghapus data server diam-diam; minta konfirmasi dan ikuti business semantics yang ada.

**Mobile:** sidebar summary pindah di bawah/atas secara logis; jangan sticky menutupi jawaban/tombol. Tabs nyaman untuk tap; progress terlihat.

**Keamanan:** hindari penyimpanan data sensitif di localStorage produksi bila tidak sudah menjadi aturan existing; bentuk request, CSRF/auth, dan error server mengikuti framework yang ditemukan.

## 7. Cross-page interactions dan state contract

| Interaksi | Dari → Ke | Harapan |
|---|---|---|
| Kelola UMKM | Home → UMKM & Recommendations | Navigasi tetap memakai router existing |
| Profil faktor | Table UMKM → Factor Profile | UMKM target terpilih terikat stable ID, bukan nama |
| Isi kuesioner | Table/Factor → Form Data | Membuka UMKM yang benar; data/answers dari backend |
| Search global | Topbar → Table UMKM | Search state jelas dan dapat dikosongkan |
| Compare | Comparison | 1–5 ID unik, chips singkron dengan grafik/tabel |
| Data changed | Form/Edit → Home/Chart/Table | Invalidasi cache/re-fetch/update state secara konsisten |
| Empty/loading/error | Semua | Tampilkan kondisi nyata, bukan skor/status default menyesatkan |

**Pemetaan yang wajib dilakukan engineer:** inventarisasi route aktual, controller/service/repository/API, data mapper, state store, template komponen dan otorisasi. Gunakan properti yang benar-benar ada. Bila nama endpoint/field berbeda, adaptasi UI dengan interface existing; jangan menciptakan endpoint fiktif dalam PRD ini.

**Nilai contoh pada screenshot (bukan seed produksi):** 4 UMKM bernama Kawalogy, Warjon, Upside Down, ayam geprek; headline rerata sekitar 92,7/100. Ini hanya snapshot referensi UI. Angka aktual harus selalu dari data live.

## 8. Komponen dan target file yang disarankan

> Struktur berikut **konseptual**, bukan perintah membuat file exact. Ikuti framework dan convention existing; jika project Blade/Livewire, Vue, React, Inertia, atau stack lain, gunakan komponen native stack tersebut.

- `UI tokens/theme` — canonical colors, backgrounds, radius, typography, shadows, animation.
- `DashboardShell` — responsive sidebar, topbar, layout, footer.
- `PageHeading`, `SummaryMetricCard`, `StatusBadge`, `SearchFilterBar`, `EmptyState`, `LoadingSkeleton`.
- `ChartCard`, `RadarFactorChart`, `HealthDonutChart`, `SectorAverageChart`, `ComparisonChart`.
- `UmkmDataTable`, `UmkmSelection`, `CompareChips`, `FactorTabs`, `EvaluationSummary`.
- `ResponsiveDialog`, `ConfirmAction`, `Toast`, `Pagination`.

Reuse komponen eksisting untuk data, auth, fetch, chart bila tidak membebani integrasi. Jangan tambah library hanya agar tampil serupa ketika CSS/SVG/chart library existing sudah cukup.

## 9. Integrasi teknis dan source-of-truth

### Fase A — Repository discovery (tanpa coding destruktif)

1. Baca dokumen aturan project dan design system existing.
2. Identifikasi stack frontend, package manager, routes, komponen 6 halaman, state, service, chart lib, API/contracts, status enum, policy, formulir.
3. Petakan perbedaan antara screenshot awal, mockup, dan implementasi aktual; dokumentasikan file yang akan disentuh.
4. Catat business logic yang **tidak boleh diubah**.

### Fase B — Design foundation

1. Implementasikan token warna dan shared layout/responsive shell.
2. Implementasikan UI primitives reusable di framework existing.
3. Hindari refactor logic yang tidak berkaitan.

### Fase C — Integrasi per halaman

1. Home; 2. Factor Profile; 3. Distribution; 4. Comparison; 5. UMKM & Recommendations; 6. Form Data.
2. Pada setiap halaman: pertahankan route/data/actions, ganti structure/presentational layer, tangani states.
3. Reuse data formatters, chart engines, dan perhitungan resmi existing.

### Fase D — Finishing & handoff

- Perbaiki responsivitas dan interaction semantics, hilangkan style lama yang tidak dipakai bila aman.
- Lakukan *minimal manual smoke inspection* pada screen yang disentuh bila dibutuhkan. **Tidak perlu menugaskan QA agent, full automated test suite, atau regression run** sebagai default instruksi user.
- Laporkan daftar file berubah, halaman selesai, dependensi baru jika ada, keterbatasan/fitur backend yang belum tersedia, dan perintah menjalankan aplikasi lokal yang benar.

## 10. Acceptance criteria (perlu dibuktikan melalui implementasi, bukan hanya gambar)

- AC-01: Semua enam menu mengarah ke screen yang sesuai, dan menu aktif mengikuti route.
- AC-02: Tiga kode brand dipakai tepat (`#0207CA`, `#0297DE`, `#FC734D`) pada theme UI.
- AC-03: Tidak ada dominasi ungu dari UI lama dalam komponen yang ditarget.
- AC-04: Layout nyaman di 1440/1024/768/390/320 px; tidak ada clipping konten esensial.
- AC-05: Sidebar drawer mobile dapat dibuka/ditutup tanpa menghalangi interaksi.
- AC-06: Semua KPI, grafik, tabel, dan badge memakai **data valid dari aplikasi**, bukan placeholder produksi.
- AC-07: Factor Profile menghormati nilai kosong dan membedakan individual vs baseline.
- AC-08: Distribution total dan partisi kategori konsisten dengan dataset terpilih.
- AC-09: Comparison mendukung 1–5 UMKM unik, hapus chip, pilih otomatis, kosongkan.
- AC-10: Table UMKM mempertahankan filter, sort, pagination, dan semua aksi berdasarkan izin.
- AC-11: Form Data mempertahankan instrumen dan scoring asli; preview/save/reset sesuai state existing.
- AC-12: Status `Sementara` bisa tetap demikian walaupun 16/16 terjawab jika memang demikian dari backend.
- AC-13: Loading, error, empty, disabled, dan success states ada pada titik interaksi utama.
- AC-14: Keyboard focus, label dan responsive chart legend dapat dipakai; reduced-motion dihormati.
- AC-15: Tidak ada migration/perubahan API/back-end yang tidak disetujui; tidak ada kerusakan kontrak lama.
- AC-16: Dokumentasi ringkas di repo menyebut file yang berubah dan cara menjalankan lokal.

## 11. Risiko dan mitigasi

| Risiko | Mitigasi |
|---|---|
| Prototype HTML berbeda dari framework aplikasi | Port layout/visual saja, tulis ulang dalam komponen native proyek |
| Status final dibangun dari progress pertanyaan | Selalu baca status existing, jangan infer dari count |
| `null` diperlakukan skor 0 | Render placeholder dan gunakan aturan rata-rata yang benar |
| “Sektor kritis” padahal skornya baik | Gunakan label akurat berdasarkan definisi dan kondisi data |
| Chart warna baru membingungkan | Legend, label, tabel pendamping, kontras |
| Data dummy/`localStorage` terbawa ke produk | Gunakan service/data layer resmi; singkirkan localStorage demo |
| Perubahan UI memutus CRUD/form action | Mapping event handler ke controller/use-case existing |
| Subagent berlebihan menghabiskan Codex usage | Batas 1–2 agent relevan, tanpa orchestrator rekursif / QA agent |

## 12. Petunjuk agents Codex (WAJIB disebut dalam handoff)

**Global agent directory (Windows):**

```text
C:\Users\itsba\.codex\agents
```

**Global user-supplied instructions path:**

```text
C:\Users\itsba\.codex\Agents.md
```

Instruksi Codex:

1. Baca `C:\Users\itsba\.codex\Agents.md` **jika file tersebut ada**, serta `AGENTS.md`/`Agents.md` di repo sesuai lokasi sebenarnya. Jangan menganggap path sudah terbaca dari lingkungan ini.
2. Inventarisasi `C:\Users\itsba\.codex\agents\` untuk menemukan nama agent dan konfigurasi yang **benar-benar tersedia**.
3. Gunakan **satu agent frontend/UI paling relevan** jika perlu. Gunakan satu agent backend tambahan **hanya bila** menemukan wiring data contract yang perlu dipetakan; tidak wajib.
4. Subagents bersifat opsional, bukan syarat. **Tidak boleh membuat 7 agent, orchestrator berlapis, atau delegasi berulang.** Jika tidak perlu, eksekusi langsung pada main agent.
5. **Jangan spawn QA/testing/regression agents** dan jangan lakukan full automated test/regression loops sebagai pekerjaan default; hanya cek fungsional ringan bila proporsional.
6. Hindari menulis ulang business logic, database, auth, dan API tanpa arahan eksplisit.

## 13. Artefak paket

```text
JOYUMKM_DASHBOARD_RELAYOUT_V1/
├─ README.md
├─ docs/
│  ├─ PRD_JoyUMKM_Dashboard_Relayout_V1.md
│  ├─ CODEX_PROMPT_SAKTI.md
│  ├─ IMPLEMENTATION_CHECKLIST.md
│  └─ DESIGN_TOKENS.css
├─ mockup/
│  └─ index.html
├─ previews/
│  ├─ joyumkm-desktop-preview.png
│  ├─ joyumkm-mobile-preview.png
│  ├─ joyumkm-factor-preview.png
│  ├─ joyumkm-distribution-preview.png
│  └─ joyumkm-form-preview.png
└─ references/original-screenshots/
   └─ 01-home.png … 10-form-data-bottom.png
```

## 14. Definition of done

Enam halaman memiliki layout visual yang mengikuti mockup dan tema, responsif, semua fitur lama tetap terhubung ke bisnis/API existing, dan Codex memberikan daftar file yang disentuh berikut catatan keterbatasan. **PRD ini tidak menyatakan bahwa implementasi repo sudah selesai**; implementasi hanya dimulai setelah Codex bekerja di repo lokal JoyUMKM.
