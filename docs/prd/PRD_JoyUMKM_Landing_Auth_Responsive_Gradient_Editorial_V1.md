# PRODUCT REQUIREMENTS DOCUMENT (PRD)
## JoyUMKM — Landing Page, Login & Register Redesign V1
### Responsive Editorial Gradient UI · Motion · Frontend Integration

**Status:** APPROVED FOR IMPLEMENTATION  
**Tanggal keputusan:** 4 November 2026  
**Pemilik produk:** JoyUMKM  
**Jenis pekerjaan:** Re-layout existing UI + frontend integration, bukan pembangunan aplikasi baru  
**Prioritas:** High  
**Referensi utama yang disetujui:** `JoyUMKM_Gradient_Editorial_Animated.html` (prototipe HTML terakhir di percakapan)  
**Batasan eksplisit:** **TANPA delegasi QA, TANPA automated/manual test task, TANPA test suite, TANPA regression testing.** Fokus pada implementasi fitur dan layout.

---

## 1. Executive Summary

Implementasikan desain final JoyUMKM untuk tiga halaman publik:

1. **Landing Page** — hero dua kolom, preview Ringkasan Kesehatan Usaha, dua floating badge, section Alur Pendampingan, section Tiga Sudut Pandang, CTA final, dan footer.
2. **Login** — split-screen auth layout, brand panel, form masuk, akses pendaftaran, kembali ke beranda, serta status form.
3. **Register** — split-screen auth layout, brand panel, form pendaftaran, pengukur kekuatan kata sandi, serta konfirmasi kata sandi.

Desain wajib mengikuti **prototipe final yang sudah disetujui**, dengan prinsip **modern SaaS, editorial, large gradient cards, clean whitespace, readable typography**. Seluruh halaman wajib adaptif dan responsif dari desktop besar sampai mobile kecil, tanpa horizontal overflow dan tanpa elemen penting yang hilang.

**Tidak diperkenankan** merancang ulang desain yang sudah disetujui secara bebas, mengganti urutan konten, mengubah business logic autentikasi, memigrasikan framework, atau membangun ulang backend.

## 2. Latar Belakang dan Problem Statement

Layout lama berkarakter datar, banyak whitespace tidak terarah, dan kartu informasi kurang kuat secara hierarki visual. Halaman login dan register belum konsisten dengan identitas visual landing. Perubahan yang disepakati:

- Mengganti palet lama ungu/navy dengan palet baru biru primer, electric blue, dan oranye.
- Mengganti kartu putih sederhana di Alur Pendampingan dan Tiga Sudut Pandang dengan **kartu editorial gradient** bergaya referensi SaaS premium.
- Menambah motion terukur pada preview hero dan badge.
- Menyatukan prinsip desain pada halaman publik dan auth.
- Memastikan layout terlihat profesional di laptop, desktop, tablet, dan ponsel.

## 3. Tujuan Produk

- Mengkomunikasikan value proposition JoyUMKM dalam satu screen pembuka.
- Memperjelas alur **Catat UMKM → Isi evaluasi → Pahami prioritas**.
- Memperjelas tiga dimensi evaluasi **INS / ECT / OPS** dengan visual yang mudah dibedakan.
- Mempermudah perpindahan landing → login → register dan sebaliknya.
- Menaikkan kualitas visual tanpa mengorbankan readability, accessibility, atau performa.
- Mempertahankan seluruh fungsi dan integrasi existing aplikasi.

## 4. Scope

### 4.1 In-scope

| Area | Cakupan |
|---|---|
| Global design foundation | Color tokens, typography, spacing, radius, elevation, responsive grid, focus states |
| Navigation | Header desktop/mobile, anchor ke sections, CTA auth, link kembali |
| Landing hero | Headline, description, 2 CTA, illustrative dashboard, badges, demo data label |
| Hero animations | Animate.style fadeInUp + badge entrance rotate |
| Alur Pendampingan | 3 editorial gradient cards, numbering, icons/abstract shapes, titles/descriptions |
| Tiga Sudut Pandang | 3 editorial gradient cards INS/ECT/OPS, differentiated palettes, copy, iconography |
| CTA/footer | Penutup dan footer mengikuti prototipe final |
| Login UI | Split-screen responsive form, password visibility, error/loading states |
| Register UI | Split-screen responsive form, password strength, confirm password, error/loading states |
| Integration | Adaptasi desain ke stack/component system/routing/auth API EXISTING |
| Accessibility | Semantic HTML, keyboard/focus, readable copy, reduced motion |

### 4.2 Out-of-scope

- Refactor/migrasi backend, perubahan skema database, perubahan model evaluasi INS/ECT/OPS.
- Fitur dashboard private, manajemen UMKM, kuesioner, skoring dan analitik internal selain yang sudah berjalan.
- Mengubah mekanisme autentikasi, otorisasi, dan session existing.
- Membuat alur forgot password baru bila belum ada.
- Menambahkan charting library, 3D library, asset raksasa, atau UI library baru hanya untuk dekorasi.
- Menampilkan skor prototipe seolah berasal dari data faktual.
- Menjalankan QA agent, unit/integration/E2E tests, regression suite, maupun task pengujian terpisah.

## 5. Sumber Kebenaran dan Strategi Implementasi

**Priority of truth**:

1. **Prototipe `JoyUMKM_Gradient_Editorial_Animated.html`** untuk komposisi, konten, gradient card, animasi, dan tampilan yang telah disetujui.
2. **Codebase JoyUMKM aktual** untuk framework, file/route naming, reusable components, data flow, API, dan logika bisnis.
3. **PRD ini** untuk acceptance requirements, responsivitas, keamanan UI, pembagian tugas, serta aturan integrasi.

Jika prototype HTML menggunakan hash route `#/home`, `#/login`, `#/register`, **jangan memaksakan hash routing ke aplikasi produksi**; gunakan router yang sudah ada. Jika prototype memakai validasi mock tanpa backend, **jangan membawa mock success ke produksi**; hubungkan ke handler autentikasi existing.

**Tahap pertama implementasi:** inspeksi struktur proyek aktif, identifikasi komponen/route landing-login-register, konfigurasi global styles, form handler existing, dan library animasi yang telah terpasang. Jangan meminta setup ulang kalau struktur sudah tersedia.

## 6. Design System

### 6.1 Palette dan semantic tokens

| Token | Nilai | Penggunaan utama |
|---|---|---|
| `--joy-primary` | `#0207CA` | Primary CTA, deep blue gradients, main brand mark, focus accents |
| `--joy-secondary` | `#0297DE` | Light gradients, section eyebrow, secondary emphasis, charts |
| `--joy-accent` | `#FC734D` | Orange editorial cards, feature accents, selective decorative elements |
| `--joy-ink` | `#151A35` | Headings / main body di background terang |
| `--joy-muted` | `#67718B` | Supporting text |
| `--joy-surface` | `#FFFFFF` | Form/card surface |
| `--joy-canvas` | `#F6F8FE` | Landing background |
| `--joy-border` | `#DFE5F2` | Input/card outlines |
| `--joy-blue-tint` | `#EEF0FF` | Pale blue contrast background |
| `--joy-cyan-tint` | `#EAF5FD` | Pale blue surfaces |

**Catatan:** label awal “Midnight Black” secara nama tidak sesuai tampilan heksadesimalnya, tetapi **gunakan nilai persis `#0207CA`** sebagai keputusan final. Jangan ganti ke hitam.

### 6.2 Gradient recipes

- **Primary / INS:** `linear-gradient(152deg, #0207CA 4%, #253FE5 57%, #0297DE 130%)`.
- **Secondary / ECT:** `linear-gradient(143deg, #DDF7FF 0%, #86D5F6 48%, #0297DE 120%)`.
- **Accent / OPS:** `linear-gradient(144deg, #FFF1E7 0%, #FFBFA7 42%, #FC734D 115%)`.
- **Workflow cards:** variasikan gradient biru/soft-cyan/orange yang konsisten dengan contoh HTML final dan hindari seluruh kartu menjadi warna identik.

Gradient merupakan **background editorial**, bukan alasan mengorbankan teks. Gunakan text color dark pada peach/cyan dan putih pada cobalt gelap sesuai kontras visual.

### 6.3 Typography

- Preferensi: `Inter` / `Plus Jakarta Sans` / font sans existing yang konsisten; fallback `system-ui`.
- Headlines berani, ketat, dominan, tetapi tidak terpotong.
- Eyebrow section uppercase, tracking sedang, font kecil.
- Body copy cukup lapang (`line-height` sekitar 1.5–1.7).
- Fluid headline menggunakan `clamp()`, hindari nilai fixed besar di mobile.
- Gunakan ikon SVG inline atau icon library existing; jangan menambah asset berlisensi tanpa kebutuhan.

### 6.4 Shape / elevation

- Cards radius target 20–28px (mengikuti prototype).
- Button radius 12–16px, state hover/focus/disabled.
- Shadow lembut dan konsisten; tidak perlu glow berlebihan.
- Decorative orb/abstract art dapat dibuat dengan CSS/SVG; harus berada **di belakang teks** dan `pointer-events: none`.

## 7. Functional Requirements — Landing Page

### LP-01. Global header

- Logo JoyUMKM + subline “UMKM Health Dashboard”.
- Desktop: anchor navigasi menuju **Cara Kerja** dan **Tiga Dimensi**, serta **Masuk**, **Daftar Akun**.
- Mobile: logo tetap, CTA utama tetap accessible; navigasi sekunder boleh collapse menjadi mekanisme yang ringkas dan accessible (sesuai struktur prototype). Jangan biarkan nav horizontal overflowing.
- Header sticky ringan dengan surface translucent bila cocok dengan implementasi existing.
- Anchor scroll tidak tertutup sticky header (`scroll-margin-top`).

### LP-02. Hero

**Headline:** “Kenali kondisi usaha. Tentukan langkah berikutnya.”  
**Deskripsi:** Memperkenalkan ruang kerja pendamping untuk mendata UMKM, mengamati tiga dimensi kondisi usaha, dan merumuskan prioritas.  
**CTA utama:** “Mulai Mendampingi” → flow register / entry yang existing.  
**CTA sekunder:** “Sudah punya akun? Masuk” → login.

**Desktop:** copy di kolom kiri, UI dashboard illustrative di kanan.  
**Mobile:** stacking vertikal: copy → CTA → illustrative preview, dengan margin/padding stabil.

### LP-03. Ringkasan Kesehatan Usaha

Preview berisi:

- Title “Ringkasan kesehatan usaha” + subline “Tiga dimensi, satu pandangan”.
- **Badge “Data ilustrasi”** harus terlihat jelas.
- Ring visual dengan **skor contoh 74**.
- Progress/metric bars **Sumber Daya 78**, **Eksternal 62**, **Operasional 86**.
- Insight callout yang menjelaskan pembacaan prioritas.
- Dua floating badges: **“Terstruktur”** dan **“Hasil mudah dipahami”**.

Nilai `74 / 78 / 62 / 86` adalah **mock/marketing data**, bukan skor aktual pengguna. Jika menghubungkan data nyata untuk pengguna authenticated di masa depan, diperlukan kontrak data terpisah. Landing publik default harus tetap melabeli data sebagai ilustrasi.

### LP-04. Alur Pendampingan (editorial gradient)

**Eyebrow:** ALUR PENDAMPINGAN  
**Headline:** “Pendampingan dimulai dari pemahaman.”  
**Body:** “Tidak perlu berpindah-pindah alat. Dari pencatatan UMKM sampai membaca hasil evaluasi, semuanya dalam satu alur kerja.”

Tiga kartu editorial **urutan konsisten**:

| Nomor | Label | Judul | Deskripsi |
|---|---|---|---|
| 01 | PENCATATAN | Catat UMKM | Buat data usaha yang Anda dampingi agar informasi dan riwayatnya tersusun rapi. |
| 02 | PENILAIAN | Isi evaluasi | Jawab kuesioner berdasarkan kondisi nyata bisnis dari tiga dimensi penilaian. |
| 03 | PEMAHAMAN | Pahami prioritas | Baca ringkasan dan visualisasi untuk menentukan fokus pendampingan berikutnya. |

**Visual:** tiga panel besar, bentuk dekoratif abstrak, nomor step, label kecil, title besar, copy terbaca, corner circle/decorative action motif. Jangan mengubah kartu menjadi plain white cards lagi. Dekorasi hanya visual bila tidak mempunyai fungsi nyata; jangan menampilkan panah sebagai link palsu. Jika menggunakan tautan CTA per card, arahkan ke rute yang memang tersedia atau CTA autentikasi yang sah.

### LP-05. Tiga Sudut Pandang (editorial gradient)

**Eyebrow:** TIGA SUDUT PANDANG  
**Headline:** “Satu usaha, dilihat lebih menyeluruh.”  
**Body:** “Ketiga dimensi memberi konteks yang saling melengkapi, bukan sekadar angka tanpa penjelasan.”

| Dimensi | Code | Label panjang | Deskripsi |
|---|---|---|---|
| Sumber Daya | INS | Institutional Resources | Melihat sumber daya dan kapasitas internal usaha yang mendukung keberlanjutan bisnis. |
| Eksternal | ECT | Economic Connectivity | Memahami hubungan usaha dengan pasar, pelanggan, dan lingkungan ekonominya. |
| Operasional | OPS | Operational Stability | Melihat stabilitas kegiatan operasional dalam menjalankan usaha sehari-hari. |

**Visual:** tiga panel besar yang serasi dengan workflow section namun identitas warna tiap dimensi kuat: INS deep primary, ECT cyan/blue, OPS peach/orange. Jangan mengubah nomenklatur dimensi atau definisi domain.

### LP-06. Closing CTA dan footer

- CTA closing: “Mulai pahami usaha yang Anda dampingi hari ini.”, tautan menuju registration/flow existing.
- Footer: copyright JoyUMKM, menu penunjang hanya ketika rutenya ada; placeholder “belum tersedia” jangan tampil sebagai link aktif menyesatkan.
- Tidak perlu menambahkan section/testimonial/analytics baru yang tidak ada pada final prototype.

## 8. Functional Requirements — Login

### AU-01. Layout

- Desktop: **split-screen**. Left panel: branded gradient/editorial surface + copy yang relevan. Right panel: clean form card/content, vertically centered, lebar nyaman.
- Login title: “Masuk ke JoyUMKM.”
- Deskripsi singkat tentang melanjutkan pendampingan.
- Back-to-home link, switching link ke Register.
- Mobile: satu kolom, branding ringkas di atas, form terlihat tanpa scroll horizontal, tombol penuh.

### AU-02. Login form

- Field: `Alamat email`, `Kata sandi`.
- Toggle show/hide password dengan `type="button"`, accessible label, state `aria-pressed`.
- Autofill/autocomplete yang sesuai (`email`, `current-password`).
- Inline validation dan server error existing, state loading dan disabled submit untuk mencegah accidental double submit.
- **Integrasi harus memakai auth handler dan endpoint existing**, bukan form dummy/notification mock sukses.
- Jika feature Forgot Password **sudah ada**, tampilkan navigasi ke rute existing. Jika belum, jangan membuat tautan buntu atau menambah fitur backend baru pada scope ini.
- Setelah sukses login, pertahankan redirect/session behavior existing.

### AU-03. Account status/error

- Tetap tampilkan pesan server secara aman (invalid credentials, network error, account disabled bila server ada) tanpa menampilkan detail sensitif.
- Pertahankan server-driven validation dan protections existing.

## 9. Functional Requirements — Register

### AU-04. Layout

- Konsisten dengan Login: split-screen desktop, satu kolom mobile.
- Right form tidak terlalu lebar. Jaga jarak antar label, input, hint dan CTA.
- Heading “Mulai pendampingan.” atau redaksi sesuai final HTML.
- Back-to-home dan link “Sudah punya akun? Masuk”.

### AU-05. Form

- `Nama lengkap`
- `Alamat email`
- `Kata sandi`
- `Konfirmasi kata sandi`
- Indikator kekuatan password yang bersifat **informasi UX**, bukan pengganti validasi server.
- Rule UI mengacu prototype: minimal 8 karakter, maksimum 72 karakter **hanya jika cocok dengan backend existing**. Bila aturan berbeda, ikuti kontrak backend dan samakan petunjuk di UI.
- Password visibility toggle opsional mengikuti komponen yang sudah tersedia dan mempertahankan konsistensi dengan login.
- Confirmation mismatch diberi pesan spesifik; jangan kirim jika field belum valid.
- Submit register memakai service existing dan hasil server existing. Pertahankan verifikasi email, onboarding, dan redirect yang sudah diterapkan oleh proyek.
- Jangan memalsukan pesan “akun berhasil dibuat” tanpa konfirmasi dari server.

## 10. Motion & Micro-Interactions

| Target | Motion | Detail |
|---|---|---|
| Ringkasan Kesehatan Usaha | Animate.style / Animate.css `animate__fadeInUp` | Durasi ±850ms, ease-out, start ketika viewport masuk atau halaman dibuka |
| Floating badge Hasil mudah dipahami | CSS rotate entrance | Dari `-9deg` ke `0deg`, ±720ms, delay ±520ms |
| Floating badge Terstruktur | CSS rotate entrance | Dari `+9deg` ke `0deg`, ±720ms, delay ±760ms |
| Gradient editorial card | Hover lift sangat ringan | Hanya saat `hover: hover`, jangan menggeser konten/menyebabkan layout shift |
| Button/focus | Transition halus | 150–250ms, bukan perpetual animation |

**Motion policy:**

- Animasi hero dimainkan **sekali ketika masuk viewport** (IntersectionObserver) dan dapat dipicu ulang saat user kembali ke halaman landing dalam pola routing yang sesuai.
- Badge rotate adalah **entrance rotation**, **bukan muter tanpa henti**.
- Animasikan opacity/transform, hindari animasi layout properties mahal.
- Implementasikan `prefers-reduced-motion: reduce` dengan transform/animation dimatikan tanpa menyembunyikan konten.
- Jika Animate.css sudah tersedia, gunakan yang ada; jika belum, impor sekali dari dependency yang sudah direstui atau adaptasi keyframes kompatibel. Hindari double-loading library.
- Animasi tidak boleh mencegah screen reader / keyboard / tap interaction.

## 11. Responsive Specification (WAJIB)

**Prinsip:** fluid first, content-based responsive, jangan bergantung hanya pada width screens preset. Width halaman tidak boleh melebihi viewport; maksimal lebar content desktop ±1200px (sesuai referensi), menggunakan centered container.

| Viewport | Layout utama | Cards | Auth | Navigation |
|---|---|---|---|---|
| ≥ 1440px | Hero 2 kolom seimbang; breathable whitespace; max-width 1200px | 3 kolom, tinggi seimbang secara visual | Left brand panel + right form panel | Desktop full |
| 1024–1439px | 2 kolom pada ruang yang memadai, responsive gap | 3 kolom jika legible; boleh adapt di ~1060px sesuai reference | Split-screen | Desktop |
| 761–1023px | Hero dapat stack jika konten sempit; preview tetap terlihat penuh | 2 kolom atau 1 kolom pada breakpoint prototype; tidak dipaksa 3 sempit | Split atau stack mengikuti available width | Compact |
| 521–760px | Hero stack, CTA wrap/full-width bila perlu | 1 kolom | Single column / compact brand | Mobile |
| 375–520px | Heading `clamp()`; safe area padding 16–20px | 1 kolom, artwork lebih kecil dan tidak menutupi teks | Form full width; input min touch-height | Minimal |
| 320–374px | Tidak ada overflow, ring chart menyesuaikan atau disederhanakan dengan konteks tetap terbaca | 1 kolom | Form tidak terpotong, font tetap legible | Minimal |

### 11.1 Hero responsive

- Desktop: editorial copy dan dashboard preview bersebelahan, visual art tetap contained.
- Tablet/mobile: preview menjadi block bawah, floating badges reposition ke area aman.
- **Jangan sembunyikan badge “Terstruktur” atau “Hasil mudah dipahami” hanya karena lebar kecil**; atur ulang posisinya supaya tidak menimpa konten. Jika perlu, posisikan seperti chips di bawah preview.
- Ring dan bars adaptif. Pada sangat sempit, ring boleh menjadi simplified display tetapi angka dan konteks tetap terlihat.

### 11.2 Editorial cards responsive

- Desktop: 3 across, spacing setara dan card height minimum harmonis.
- Tablet: 2+1 atau 1 column tergantung ruang dan hasil paling terbaca; **jangan paksa 3 kartu sempit**.
- Mobile: stack 01→02→03 dan INS→ECT→OPS, **tanpa carousel wajib atau horizontal scrolling**.
- Header/number/paragraph tidak clipping; art `overflow:hidden` dibatasi di card dan di belakang copy.
- Card min-height adaptif berdasarkan konten, bukan fixed height yang memotong deskripsi.
- Gradient dan text contrast dipertahankan di semua breakpoint.

### 11.3 Auth responsive

- Desktop panel kiri bisa cukup dominan untuk branding, kanan untuk task form.
- Tablet: panel kiri diperkecil / dipindah ke header visual.
- Mobile: form ditempatkan sebelum dekorasi non-esensial, field serta submit full width.
- Dengan keyboard mobile terbuka, content tetap scrollable dan input aktif tidak tertutup.
- Atur `autocomplete`, `inputmode="email"`, dan safe-area bottom bila perlu.

### 11.4 UI stability

- `box-sizing: border-box`, gambar `max-width:100%`, teks panjang `overflow-wrap` bila perlu.
- Hindari absolute positioning untuk teks utama; absolute positioning boleh hanya artwork.
- Jangan menetapkan `overflow-x: hidden` untuk menutupi masalah layout utama; perbaiki penyebab overflow-nya.
- Mobile touch targets sekitar 44×44px untuk icon buttons / CTA yang actionable.

## 12. Interaksi / Navigasi

| Action | Behavior yang diharapkan |
|---|---|
| Logo JoyUMKM | Buka landing |
| Cara Kerja | Scroll ke Alur Pendampingan |
| Tiga Dimensi | Scroll ke Tiga Sudut Pandang |
| Masuk | Buka login existing |
| Daftar Akun / Mulai Mendampingi | Buka register/entry existing |
| Kembali ke beranda | Buka landing |
| Toggle password | Show / hide field tanpa submit |
| Submit login/register | Handler existing, loading + server validation + redirect sesuai implementasi aktif |
| Decorative card arrows | Visual only atau tautan valid; **bukan tombol buntu** |

**Routing:** gunakan route actual proyek, bukan memaksakan referensi `#/...` yang hanya digunakan pada demo HTML standalone.

## 13. Accessibility, Privacy, dan Konten

- Struktur heading semantik, urutan fokus logis, input labels terkait field, feedback terbaca untuk screen reader (`aria-live` bila diperlukan).
- Pastikan kontras teks gradient memadai, terutama putih di area biru dan dark di area cyan/orange.
- Focus ring jelas; jangan mengandalkan hover untuk informasi esensial.
- `prefers-reduced-motion` selalu didukung.
- Data demo harus jelas sebagai ilustrasi. Jangan menampilkan identitas atau skor pengguna nyata di landing publik.
- Password tidak tercatat ke console, analytics event, atau log frontend; pertahankan existing CSRF/session strategy.
- Nomenklatur tiga dimensi harus utuh dan konsisten (INS, ECT, OPS).

## 14. Komponen yang Disarankan

Gunakan pola naming dan framework actual. Ini **logical boundaries**, bukan instruksi wajib membuat file baru:

- `BrandLogo`
- `PublicHeader` / `PublicFooter`
- `LandingHero`
- `HealthSummaryPreview`
- `FloatingInfoBadge`
- `SectionHeading`
- `EditorialGradientCard`
- `WorkflowSection`
- `DimensionsSection`
- `FinalCTA`
- `AuthBrandPanel`
- `AuthLayout`
- `PasswordField`
- `PasswordStrengthIndicator`
- `LoginForm`
- `RegisterForm`

Avoid overengineering. Bila proyek existing hanya perlu memecah 3–5 reusable components, gunakan seperlunya. Jangan menduplikasi CSS/data across login & register. Design tokens di satu tempat.

## 15. Implementation Plan (langsung eksekusi)

### Phase A — Repository mapping (ringan)

- Temukan route/pages/partials landing-login-register serta global stylesheet/design tokens.
- Identifikasi framework, UI library, auth service dan route navigasi existing.
- Identifikasi dependensi Animate.css existing, dan lokasi penempatan style untuk public pages.
- Lakukan mapping saja; **jangan refactor area di luar scope**.

### Phase B — Design foundation

- Pasang semantic palette dan reusable gradient recipes.
- Terapkan typography, container, spacing, radius, button/input states.
- Jaga brand consistency antartiga halaman.

### Phase C — Landing implementation

- Ganti hero sesuai final prototype; pertahankan demo label.
- Pasang fadeInUp dan badge rotate.
- Re-layout Alur Pendampingan dan Tiga Sudut Pandang menjadi six gradient editorial cards.
- Terapkan closing CTA/footer dan seluruh rute nav.

### Phase D — Login & Register implementation

- Terapkan split-screen auth layout dan responsivitas.
- Gunakan auth handlers existing tanpa mengganti login/register business logic.
- Pasang password visibility, strengths, field validation UI, error/loading states.

### Phase E — Responsive polish & delivery

- Sesuaikan CSS/layout rules untuk desktop/tablet/mobile/ponsel kecil.
- Rapikan konten bila form error muncul, orientasi berubah, atau teks terjemahan lebih panjang.
- Serahkan rangkuman file berubah, routes yang terdampak, dan batasan integrasi bila ada.
- **Tidak membuat/memanggil tahap QA, TEST, regression, atau benchmark.**

## 16. Agent Orchestration (hemat pemakaian)

**Prinsip utama: minimal agents, satu kali delegasi per workstream, tidak bolak-balik menjalankan orchestrator.**

| Agent | Kapan dipakai | Peran |
|---|---|---|
| `master_orchestrator` / “ponytails” | Sekali di awal, jika tersedia | Membaca PRD + melihat struktur project; mengalokasi eksekusi minimum dan menutup delegasi setelah selesai |
| `frontend_engineer` atau agent spesialis frontend yang tersedia | Agent utama | Implementasi layout, semantic tokens, CSS/SVG gradient cards, motion, routing UI, accessibility, responsivitas |
| `backend_engineer` | **Hanya bila benar-benar perlu** menyesuaikan binding endpoint auth existing; jangan mengubah backend | Membantu koneksi form ke auth contract yang sudah berjalan jika implementasi frontend tidak bisa menyelesaikan sendiri |

Jika tidak ada `frontend_engineer` bernama demikian, pilih agent frontend yang benar-benar terdaftar atau kerjakan melalui **main Codex agent**; **jangan membuat agent fiktif**. Jika agent orchestration tidak tersedia, implementasikan langsung tanpa menunggu agent.

**Jangan panggil:** `qa_engineer`, `security_auditor`, dedicated test agent, visual regression agent, audit/regression orchestrator, atau 7 agents paralel. Hindari nested subagents dan repeated handoff.

## 17. Definition of Done — Implementation Outcomes (bukan test plan)

Deliverable implementasi dianggap lengkap secara scope ketika:

- Landing, Login, Register memakai visual terbaru yang sudah approved.
- Palet `#0207CA`, `#0297DE`, `#FC734D` digunakan konsisten.
- Hero dashboard, nilai ilustrasi dan disclaimer tetap sesuai keputusan.
- Motion hero (`fadeInUp`) dan badges (`rotate entrance`) sesuai timing serta menghormati reduced motion.
- Alur Pendampingan & Tiga Sudut Pandang menjadi gradient editorial cards, bukan flat white cards.
- Layout tersedia dan adaptif untuk desktop/tablet/mobile, tanpa horizontal overflow.
- Tombol, navigasi, login, register, serta existing auth behavior tidak berubah secara bisnis.
- Tidak ada tautan dummy aktif, fake auth success, atau perubahan backend yang tidak diperlukan.
- Implementasi terlokalisasi pada halaman terkait, memanfaatkan reusable design foundation.
- Engineer menyerahkan **ringkasan file berubah + keputusan teknis utama + dependensi/limitasi**.

**Tidak mengharuskan/meminta:** test cases, perintah `test`, QA checklist, full regression, visual diff pipeline, atau report coverage. Ini instruksi eksplisit pemilik proyek.

## 18. Risiko dan keputusan yang harus dijaga

| Risiko | Aturan mitigasi saat implementasi |
|---|---|
| Prototype HTML dipaste mentah ke aplikasi | Translasi ke components/routes existing; jangan menduplikasi full app shell |
| Auth hanya simulasi | Hubungkan ke existing auth service; jangan copy behavior mock submit dari HTML |
| Gradient dekorasi menutup teks | Gunakan layering, padding, card min-height berbasis konten |
| Badges overlapping di mobile | Posisikan ulang jadi in-flow chips atau layout yang aman |
| Banyak subagents menghabiskan quota | Satu orchestrator bila benar-benar berguna + satu implementer |
| Angka demo dianggap nyata | Badge “Data ilustrasi” eksplisit |
| Palet primary terasa sangat saturated | Gunakan off-white canvas dan proporsi accent yang terkendali; **tidak mengubah HEX** |
| Perbedaan auth validation backend | UI mengikuti rule backend existing, tidak mengubah kontraknya |

## 19. Artefak Serah Terima

1. Kode implementasi JoyUMKM pada codebase aktif (bukan prototype demo standalone).
2. Pages/routes landing, login dan register yang diperbarui.
3. Shared tokens/styles/components terkait yang memang dibutuhkan.
4. Ringkasan singkat file berubah dan integrasi existing yang digunakan.
5. Catatan hanya untuk dependensi atau gap yang **benar-benar terjadi**; jangan menambah fitur baru atas inisiatif sendiri.

## 20. Non-negotiable Rules

- **Prototype final adalah acuan visual yang disetujui.** Jangan redesain dari nol.
- **Tiga HEX color tidak boleh diganti.**
- **Responsive desktop, tablet, mobile adalah mandatory.**
- **Frontend integrated with current project, not an isolated HTML delivery.**
- **No fabricated production data / auth success.**
- **No changes to auth business logic or database without an explicit blocker.**
- **NO QA, NO TESTING TASKS, NO REGRESSION.**
- **Minimal agents. No 7-agent orchestration.**

---

## Lampiran — Reference

- `JoyUMKM_Gradient_Editorial_Animated.html` — final approved source-of-visual-truth (landing + auth UI + editorial gradient + motion), disertakan di bundle bersama PRD ini.
- Screenshot awal: landing lama, login lama, register lama.
- Screenshot referensi editorial: tiga kartu bergaya gradient dengan ilustrasi abstrak.

**Catatan deployment:** Prototipe HTML merupakan referensi visual, bukan pengganti aplikasi existing dan bukan kontrak API produksi.
