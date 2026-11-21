# PRD JoyUMKM V1 — UI-0: Authenticated Navigation & Component Loading Architecture

**Document ID:** `PRD-JOYUMKM-UI-0`  
**Tanggal:** 20 November 2026  
**Status:** Spesifikasi disepakati untuk fase UI-0; belum diimplementasikan  
**Prioritas:** P0 — perbaikan pengalaman navigasi  
**Repository acuan:** https://github.com/joytimess/dip-joyumkm  
**Baseline inspeksi remote:** branch `main`, commit `804738b0a39f9dc2658e40e92e796077d131aca6` (4 November 2026)  
**Batas penting:** Checkout lokal/branch implementasi bisa lebih baru daripada baseline remote; inspeksi ulang file aktual sebelum memodifikasi kode. Jangan menghapus perubahan atau fitur yang tidak tercermin pada commit baseline.

---

## 1. Ringkasan eksekutif

Saat pengguna yang telah login berpindah antarmenu workspace JoyUMKM, browser menampilkan **layar penuh “Memeriksa sesi Anda…”** dengan spinner. Pengguna kehilangan sidebar, topbar, dan konteks navigasi, meskipun perpindahan terjadi di dalam area authenticated yang sama. Perilaku tersebut sangat mengganggu untuk aplikasi pendampingan yang dipakai berpindah-pindah halaman.

Fase **UI-0** menghapus *full-screen session loading* pada **navigasi normal antarrute authenticated** dan memperkenalkan pengalaman navigasi dengan **application shell stabil** serta **skeleton loading lokal per komponen**. Pemeriksaan autentikasi, pembatasan akses API, penanganan token kadaluarsa, logout, dan kegagalan jaringan harus tetap aman. Pengembangan diusahakan tetap pada **Laravel Blade + JavaScript + API Sanctum yang sudah ada**, bukan rewrite ke SPA penuh.

**Hasil yang dituju:** pengguna berpindah halaman tanpa melihat layar kosong/spinner global; sidebar/topbar tetap hadir; kartu metrik, grafik, daftar, picker, dan form yang belum siap menampilkan skeleton masing-masing; data yang telah tersedia segera terlihat; error/retry tidak menjatuhkan seluruh workspace.

## 2. Latar belakang dan diagnosis yang terverifikasi

### 2.1 Gejala teramati

- Screenshot pengguna memperlihatkan latar hampir kosong dengan spinner di tengah dan teks **“Memeriksa sesi Anda…”** setiap kali berpindah halaman setelah login.
- Hal ini berbeda dari loading data per kartu/grafik yang memang wajar pada jaringan lambat.
- Tujuan perbaikan eksplisit: **tidak perlu layar loading seperti ini; gunakan skeleton loading setiap komponen di setiap halaman.**

### 2.2 Temuan source code pada baseline remote

| Bukti | Lokasi | Perilaku aktual |
|---|---|---|
| Layout authenticated | `resources/views/layouts/app.blade.php` baris 4–6 | `#auth-status` berisi spinner/status sesi; `#app-shell` dimulai dalam keadaan `hidden`. |
| Session guard | `resources/js/auth.js` baris 20–38 | `guardProtectedPage()` memanggil `GET /api/auth/me`; baru setelah sukses status disembunyikan dan shell ditampilkan. |
| Bootstrap JS | `resources/js/app.js` baris 11–51 | Inisialisasi script halaman baru berjalan setelah `guardProtectedPage()` sukses. |
| Navigasi | `resources/views/components/navigation.blade.php` baris 3–7 | Item menu memakai tautan `href` biasa menuju halaman Blade berbeda; menyebabkan navigasi dokumen penuh. |
| Web routes | `routes/web.php` baris 10–15 | Route workspace: `/home`, `/umkms`, `/form-data`, `/factor-profile`, `/distribution`, `/comparison`. |
| API guard | `routes/api.php` | `/auth/me` dan API workspace berada dalam middleware `auth:sanctum`. |
| Skeleton yang sudah ada | `resources/views/home.blade.php`, `umkms.blade.php`, `factor-profile.blade.php`, `distribution.blade.php`, `comparison.blade.php`, `form-data.blade.php` | Terdapat placeholder skeleton lokal; saat navigasi normal placeholder tertutup oleh shell global yang disembunyikan. |
| Loading grafik | `resources/js/charts.js` fungsi `createChartPage` | Memakai skeleton lokal dan `aria-busy`; tersedia pembatalan request/chart cleanup. |
| Session/token | `resources/js/api.js` | Bearer token disimpan pada localStorage; `401` menghapus sesi dan mengarahkan ke login. |

**Kesimpulan diagnosis:** akar pengalaman buruk adalah gabungan **navigasi dokumen penuh + shell `hidden` sampai `/auth/me` berhasil + inisialisasi halaman setelah guard**. Hanya menghapus teks/spinner atau mengubah CSS `#auth-status` tidak menyelesaikan akar persoalan.

**Batas diagnosis:** temuan di atas diperoleh dari pembacaan statis repository, bukan hasil network profiling produksi. Implementasi harus memverifikasi ulang perilaku runtime pada checkout yang akan dikerjakan.

## 3. Sasaran, non-sasaran, dan prinsip

### 3.1 Sasaran wajib

1. Navigasi antarhalaman authenticated **tidak lagi menampilkan layar spinner full-screen** untuk pemeriksaan sesi.
2. **Sidebar, topbar, dan kerangka workspace tetap stabil** selama navigasi normal, termasuk state menu aktif dan identitas pengguna yang valid.
3. Loading dipindahkan ke **tingkat komponen/section**, sesuai struktur halaman sebenarnya.
4. Komponen dapat **berhasil, gagal, loading, dan retry secara independen** sejauh dependensinya memungkinkan.
5. Auth tetap **fail-closed**: token hilang, dicabut, kadaluarsa, atau tidak sah tidak membuka data pengguna lain maupun meneruskan request yang tidak diizinkan.
6. Browser **Back/Forward**, deep link, query string, refresh, logout, expired session, dan transisi ke halaman publik bekerja benar.
7. Perubahan minimal, kompatibel dengan Blade/JS/API serta fitur bisnis yang telah ada.

### 3.2 Di luar cakupan UI-0

- Mengganti font global ke **Poppins**, warna dan design tokens baru (UI-1).
- Mengubah sidebar menjadi Midnight Navy dan mengelompokkan navigasi (UI-2).
- Menjadikan tabel UMKM sebagai card list mobile (UI-2).
- Redesign landing/login/register, chart styling, dialog, slider, form visual, dan penyesuaian halaman menyeluruh (UI-3).
- Mengubah skor penilaian, status Draft/Final, histori, aturan bisnis, kontrak API, ownership, schema/migrasi database, atau seed data.
- Menambahkan endpoint auth baru semata-mata untuk menyembunyikan loading, atau mengganti metode login ke sistem yang tidak diminta.
- Rewrite menjadi React/Vue/Inertia SPA penuh tanpa kebutuhan nyata.

### 3.3 Prinsip implementasi

- **Pertahankan keamanan lebih dulu.** Jangan menjadikan cache localStorage sebagai bukti otorisasi final.
- **Shell stabil, konten bertahap.** Data tidak boleh menunggu komponen lain yang tidak bergantung padanya.
- **Skeleton merepresentasikan bentuk konten**, bukan sebuah panel generik tanpa konteks.
- **Tidak perlu request ulang yang sia-sia.** Hindari `/auth/me` blocking setiap kali berpindah menu jika sesi yang valid dapat dikelola secara aman dalam satu lifecycle workspace.
- **Tidak wajib satu solusi library.** Implementor memilih progressive navigation/partial rendering/pendekatan ringan yang tepat, dengan alasan teknis, tanpa memaksakan SPA rewrite.
- **Preserve existing contracts.** Kompatibilitas dengan kode saat ini harus dapat diuji otomatis.

## 4. Pengguna dan user stories

**Aktor utama:** pendamping UMKM yang sudah login dan sering berpindah antara beranda, data UMKM, kuesioner, profil faktor, distribusi, dan perbandingan.

- **US-01:** Sebagai pengguna authenticated, saat klik menu lain, saya tetap melihat sidebar/topbar dan langsung memahami halaman tujuan, tanpa layar spinner pemeriksaan sesi.
- **US-02:** Saat data suatu bagian belum siap, saya melihat skeleton lokal yang bentuknya mirip komponen tersebut, sementara bagian lain dapat tampil.
- **US-03:** Saat grafik gagal dimuat, saya bisa mengulangi grafik itu tanpa kehilangan menu, data, maupun state komponen lain.
- **US-04:** Saat token saya berakhir atau dicabut, aplikasi membatasi akses dan mengarahkan saya ke login secara aman tanpa memperlihatkan data terlindungi.
- **US-05:** Saat menggunakan Back/Forward atau membuka URL halaman langsung, alamat, data, active menu, dan browser history tetap benar.
- **US-06:** Saat memakai mobile/tablet, loading tetap responsif dan tidak menyebabkan overflow atau layout yang meloncat.

## 5. Functional requirements (FR)

### FR-01 — Navigasi authenticated tanpa full-screen guard berulang **[MUST]**

- Untuk perpindahan internal dari salah satu route workspace ke route workspace lain, **tidak boleh ada frame yang menampilkan `#auth-status` sebagai layar utama**.
- Jangan membuang/recreate keseluruhan sidebar/topbar pada setiap klik menu biasa jika dapat dipertahankan dengan aman.
- Tampilkan indikasi navigasi yang ringan dan accessible pada area relevan, bukan overlay yang memblokir seluruh layar.
- Tangani perpindahan ke URL dengan query params (`?search=...`, `?umkm_id=...`) dan tautan langsung, tanpa mematahkan deep link.

### FR-02 — Keamanan session guard **[MUST]**

- Pemeriksaan sesi awal tetap memvalidasi identitas dengan mekanisme server/API saat yang diperlukan; token di localStorage bukan sumber otorisasi tepercaya.
- Semua request API terlindungi tetap memakai Bearer token dan middleware Sanctum/ownership existing.
- Jika tidak ada token atau server mengembalikan `401`, hentikan penggunaan konten terlindungi, bersihkan session yang relevan, dan arahkan ke login; jangan mempertahankan data lama milik sesi sebelumnya pada tampilan.
- `403` (jika ada) bukan login expired: tampilkan akses ditolak yang sesuai, jangan otomatis menganggap token hilang.
- Kegagalan jaringan/timeout pada `/auth/me` **tidak otomatis** disamakan dengan `401` atau memicu loop redirect; tampilkan state *tidak dapat memverifikasi sesi* dengan retry yang aman.
- Logout harus menginvalidasi token melalui alur yang telah ada, menghapus data per sesi dari memori/cache UI, dan mencegah layar privat muncul kembali lewat history cache.
- Akses pertama (cold entry/direct URL) boleh memiliki placeholder kerangka aplikasi yang aman sebelum validasi selesai; **tidak boleh** merender informasi privat sebagai data terverifikasi sebelum validasi. Full-screen guard awal hanya sebagai fallback keamanan yang benar-benar diperlukan, bukan navigasi normal.
- Multi-tab behavior dan event `storage` perlu tetap konsisten: logout dari tab lain tidak meninggalkan data yang masih bisa diakses pada tab aktif.

### FR-03 — Shared shell persisten **[MUST]**

- Sediakan satu shell workspace yang secara visual stabil: sidebar/drawer, topbar, profil/header, dan kontainer konten.
- Navigasi internal mengganti konten route, menyesuaikan `document.title`, active link (`aria-current="page"`), heading, dan focus target tanpa mengganti shell secara berlebihan.
- Event handler sidebar/drawer, search, logout, profile/help/notification, serta toast **tidak boleh terdaftar ganda** setelah beberapa navigasi.
- Navigasi ke route publik (login/logout/landing) tetap memakai transisi yang sesuai.
- Jika solusi menggunakan partial navigation, pastikan tautan biasa/fallback diperlakukan dengan sengaja untuk browser tanpa enhancement JS, sambil menjaga proteksi konten.

### FR-04 — Skeleton loading terisolasi per bagian **[MUST]**

Komponen yang memerlukan data async memiliki paling sedikit empat state: `loading`, `success`, `empty` bila relevan, dan `error`.

| Tipe komponen | Skeleton minimum yang diwajibkan |
|---|---|
| Ringkasan/KPI | Card dengan label pendek, blok angka, dan caption placeholder; mempertahankan grid aktual. |
| Tabel UMKM (desktop existing) | Header tetap atau placeholder; beberapa baris dengan panjang kolom bervariasi. |
| Picker dan filter | Placeholder field/selector ukuran realistis; tidak menampilkan pilihan keliru sebelum data ada. |
| Grafik (radar/distribusi/perbandingan) | Panel chart dan legend/label placeholder yang stabil dalam dimensi. |
| Form/kuesioner | Section/field group placeholder sesuai susunan nyata; jangan tampilkan input aktif untuk data yang belum siap. |
| Detail/rangkuman pemilihan UMKM | Skeleton card detail sesuai konten yang sedang dimuat. |

- Jangan mengosongkan seluruh `main-content` hanya karena satu request berjalan.
- Jika satu section selesai lebih dulu, render section itu seketika; tidak harus menunggu request independen lain.
- Skeleton dibersihkan pada sukses, empty, error; tidak boleh macet permanen.
- Jika refresh data dilakukan setelah konten sebelumnya sudah terlihat, prioritaskan menjaga konten valid yang sedang ditampilkan dengan indikator refresh lokal, daripada mem-flash seluruh skeleton kembali, sepanjang tidak berisiko menampilkan data sesi/UMKM yang salah.
- Efek visual skeleton ringan dan menghormati `prefers-reduced-motion`.

### FR-05 — Perilaku async, race, dan cleanup **[MUST]**

- Saat navigasi cepat beruntun, response dari halaman/request lama **tidak boleh** menimpa halaman baru.
- Gunakan cancellation (`AbortController`) dan/atau request sequence guard yang konsisten; perhatikan request yang tidak bisa dibatalkan sepenuhnya.
- Bersihkan event listeners, timers, subscriptions, modal callbacks, chart instances (ApexCharts), dan pekerjaan async spesifik halaman pada unmount/exit; tidak terjadi duplicate handler.
- Request yang dibatalkan akibat navigasi normal tidak boleh memunculkan toast error.
- Jangan mereset perubahan form yang belum disimpan secara diam-diam. Pertahankan atau tampilkan konfirmasi keluar sesuai mekanisme existing, bila berlaku.
- Pencarian/picker/filter yang memakai URL query harus tetap memetakan parameter dengan benar.

### FR-06 — Error & recovery lokal **[MUST]**

- Kesalahan API data halaman (`5xx`, network timeout) menampilkan pesan dalam bahasa Indonesia pada section terkait, dengan aksi **Coba Lagi** jika aman.
- Bagian lain yang sudah sukses tetap terlihat; shell tidak disembunyikan.
- Bedakan empty state sah dari gagal mengambil data.
- Retry tidak melakukan duplikasi mutation. Jangan otomatis retry POST/PATCH/DELETE atau finalisasi penilaian.
- Jangan menampilkan raw exception, token, trace, atau detail internal.

### FR-07 — Browser/navigation semantics **[MUST]**

- Mendukung browser `Back`/`Forward`, reload, open-in-new-tab, click middle/modified click, deep link, anchor, hash bila ada, serta history URL yang sesuai.
- Perubahan route memutakhirkan `document.title`, heading, active sidebar state, dan focus management.
- Scroll halaman baru kembali ke atas kecuali restore history/anchor yang relevan; hindari lompatan yang tidak disengaja.
- Mobile navigation drawer menutup setelah navigasi sukses dan tidak meninggalkan overlay/focus trap.
- Keyboard navigation, skip link, focus visibility, screen reader announcement, dan `aria-busy` tetap semantik.

### FR-08 — Responsif dan stabil **[MUST]**

- Loading layout harus sesuai viewport desktop 1440×900, tablet 768×1024, dan mobile 390×844 (atau viewport setara bila project QA memakai ukuran tambahan).
- Hindari skeleton yang lebih lebar dari viewport, horizontal overflow tak sengaja, atau perpindahan layout besar saat data muncul.
- Memakai desain warna/font yang ada selama UI-0. Jangan memasukkan implementasi palet/Poppins sebagai bagian fase ini.

### FR-09 — Observability yang aman **[SHOULD]**

- Sediakan cara terukur dalam dev/test untuk memastikan route change tidak melakukan full document reload tanpa alasan, tidak memicu auth guard berulang, dan tidak mendaftarkan handler ganda.
- Bila ada logging, gunakan metadata aman (nama route, status, durasi); **jangan** log token, kredensial, nama pribadi sensitif, atau payload API privat.
- Tidak perlu menambahkan sistem monitoring eksternal atau dependency besar hanya untuk UI-0.

## 6. Keputusan arsitektur dan ruang pilihan implementor

**Kriteria arsitektur (wajib):**

1. Existing stack Laravel Blade, ES modules/Vite, dan fetch API tetap dipakai.
2. Pengguna tetap bisa membuka URL workspace secara langsung melalui `routes/web.php` yang sudah ada.
3. Internal page transitions harus mempertahankan shell dan meminimalkan perubahan dokumen penuh.
4. Session validation tetap server-authoritative, dengan penanganan awal, refresh, logout, 401 dan multi-tab yang benar.
5. Komponen halaman dapat melakukan mount, update, dan cleanup; tidak mengandalkan satu kali eksekusi `DOMContentLoaded` saja bila navigasi menjadi dinamis.
6. Route content dan app shell harus jelas dipisahkan sehingga tidak memasukkan kembali `<html>`, `<body>`, topbar, dan sidebar ke dalam konten route.
7. Tidak ada migrasi ke SPA framework sebagai default; solusi boleh berupa progressive enhancement/partial navigation ringan atau solusi setara yang dibuktikan fit dengan struktur aktual.

**Implementor wajib** membuat catatan keputusan teknis singkat: pendekatan terpilih, alternatif yang dipertimbangkan, bagaimana invalid session dicegah menampilkan data privat, bagaimana lifecycle/cleanup tiap halaman ditangani, serta bagaimana fallback navigasi bekerja.

**Anti-pattern yang dilarang:**

- Menyembunyikan spinner dengan CSS tetapi tetap membuat seluruh shell berkedip/reload.
- Memaksa `#app-shell` selalu tampil berisi informasi akun yang belum tervalidasi pada cold entry.
- Menghapus pemanggilan `/auth/me` dan mengandalkan isi localStorage sebagai izin akses.
- Menggunakan artificial delay untuk menyamarkan masalah.
- Mengabaikan cleanup page module hingga chart/listener menumpuk.
- Mengubah API, database, atau logika penilaian untuk masalah yang semata terkait navigasi.

## 7. Komponen dan route yang wajib dicakup

**Rute acuan dari baseline remote:**

| Route | Komponen/loading utama | Skenario navigasi |
|---|---|---|
| `/home` | Ringkasan KPI, health chart, prioritas UMKM | Masuk pertama, pindah balik dari halaman lain |
| `/umkms` | Filter/options, ringkasan, tabel, detail/modal | Filter, sort, pagination, search query, edit dialog |
| `/form-data` | Picker, kuesioner, draft/hasil terbaru | Memilih UMKM, ganti UMKM, draft state, navigate away |
| `/factor-profile` | Picker UMKM, metrik, radar chart | `?umkm_id=...`, pilih UMKM dan revisualisasi |
| `/distribution` | KPI distribusi dan chart | State loading/empty/error |
| `/comparison` | Picker, daftar terpilih, chart | Query, pilih/hapus pembanding, cepat berpindah |

**Penting:** Daftar di atas berasal dari commit remote 4 November 2026. Pada checkout implementasi terbaru bisa terdapat **halaman detail, penilaian enam faktor, histori, atau route lain** dari pengembangan setelah commit tersebut. Semua route authenticated yang benar-benar ada pada checkout terbaru **wajib masuk inventory dan acceptance coverage**. Tidak boleh dinyatakan `NOT_APPLICABLE` hanya karena tidak ada di baseline remote.

## 8. Acceptance criteria (AC) — wajib dapat diuji

| ID | Kriteria penerimaan terukur | Bukti minimum |
|---|---|---|
| **UI0-AC01** | Seluruh navigasi internal antarroute authenticated yang ada berjalan **tanpa layar penuh “Memeriksa sesi Anda…”**. | Browser test route matrix + video/screenshot transisi. |
| **UI0-AC02** | Sidebar/topbar tetap stabil selama navigasi; tidak terjadi document reload yang tidak diinginkan pada klik internal normal. | Observasi DOM shell identity, navigation/resource entries. |
| **UI0-AC03** | Komponen independen menampilkan skeleton lokal dan selesai sesuai request masing-masing; skeleton sesuai bentuk card/chart/table/form. | Network throttling + screenshot desktop/tablet/mobile. |
| **UI0-AC04** | Sesi tidak valid (no token, 401 expired/revoked, logout, cross-tab logout) tidak mempertahankan atau membuka data privat; alur redirect aman. | E2E auth/security + API regression. |
| **UI0-AC05** | Network error/timeout saat validasi awal menghasilkan recovery aman (bukan logout palsu/redirect loop); error data lokal punya retry. | Mock network errors + retry test. |
| **UI0-AC06** | Back/Forward, reload, direct URL, query params, search, new-tab, dan modified click bekerja; document title dan active nav sesuai. | E2E browser navigation matrix. |
| **UI0-AC07** | Navigasi cepat/berulang tidak menampilkan data route sebelumnya; tidak ada duplicate listener/chart, unhandled rejection, atau error AbortError palsu. | Stress E2E + console instrumentation. |
| **UI0-AC08** | Setelah login, logout, dan penggantian user/token, state komponen yang sensitif tidak bocor antar sesi. | E2E session isolation + API ownership tests. |
| **UI0-AC09** | Fitur bisnis existing (UMKM CRUD, form, draft/final, penilaian enam faktor jika tersedia, history, analitik) tetap berjalan; tidak ada perubahan schema/contract. | Regression suite yang relevan. |
| **UI0-AC10** | Semua screenshot/evidence dan hasil test sesuai real execution; tidak ada klaim PASS untuk test yang tidak dijalankan atau environment blocked. | Evidence manifest, laporan akhir, daftar gap. |

**Definisi kelulusan:** Phase UI-0 hanya `PASS` jika seluruh AC wajib lulus di checkout yang dijadikan target, dan tidak ada regresi kritis. Jika ada kegagalan baseline yang benar-benar tidak terkait, dokumentasikan bukti reproduksi pembanding; jangan diam-diam menghitungnya lulus. Jika gate belum terbukti, tulis `NOT RUN` / `BLOCKED` / `FAIL` dengan penyebab nyata, bukan klaim sukses.

## 9. Test plan dan skenario minimum

### 9.1 Browser E2E (Playwright direkomendasikan)

1. Login dengan **akun testing**, lalu navigasi `/home → /umkms → /factor-profile → /distribution → /comparison → /form-data → /home` (perlu diperluas sesuai inventory route terbaru).
2. Verifikasi tidak ada overlay spinner global yang muncul pada setiap transisi dan shell tidak dibuang.
3. Jalankan network throttling untuk endpoint data per halaman; bandingkan kapan skeleton muncul dan kapan tiap section selesai, tanpa memblokir section lain.
4. Jalankan simulasi `500`, timeout, network offline untuk endpoint chart/table dan `/auth/me`; periksa perbedaan error lokal vs error validasi sesi.
5. Jalankan token expired/revoked, token hilang, login ulang, logout, dan logout dari tab kedua.
6. Jalankan navigation race: klik cepat 3–5 halaman, navigasi saat chart belum selesai, dan pergantian picker/filter di tengah request.
7. Uji Back/Forward, refresh, direct URL `?umkm_id=...` dan `?search=...`, new tab, middle click/modified click.
8. Uji modal, global search, form unsaved/draft, dan toast yang sudah ada agar tidak kehilangan callback atau menggandakan event.
9. Validasi tidak ada console error, unhandled promise rejection, kebocoran timer/listener/charts, serta network request yang berulang tak terkendali.
10. Uji keyboard/focus, `aria-current`, `aria-busy`, dan `prefers-reduced-motion`.

### 9.2 Backend/API regression

- Jalankan auth tests existing (`tests/Feature/AuthApiTest.php`) dan suite ownership/security relevan.
- Validasi `/api/auth/me`, logout, 401, token expiry, dan `/api/*` protected tetap sesuai kontrak.
- Tidak perlu migration/database baru; bila checkout terbaru memiliki test tambahan, gunakan test yang sesuai tanpa menghapus tes lama.

### 9.3 Matriks viewport dan evidence

| Viewport | Wajib diperiksa |
|---|---|
| 1440×900 desktop | Sidebar, header, KPI grid, chart, table, perubahan route. |
| 768×1024 tablet | Drawer/sidebar sesuai desain existing, responsif skeleton/picker, tidak overflow. |
| 390×844 mobile | Drawer, skeleton per section, no full-screen block, scroll/focus aman. |

**Evidence minimum:** screenshot sebelum/sesudah untuk kondisi transisi, 3 viewport, screenshot satu komponen loading dan satu error/retry, video atau trace Playwright yang menunjukkan shell stabil, hasil regresi, serta matriks PASS/FAIL per AC.

## 10. Non-functional requirements (NFR)

- **Keamanan:** tidak mengurangi validasi Sanctum/ownership; data dan identitas tidak bocor antar sesi atau ke view yang belum terotorisasi.
- **Performa:** menghilangkan biaya visual reload shell pada navigasi normal; tidak meningkatkan request berlebihan. Ukur dan laporkan sebelum/sesudah (jumlah document navigations, `/auth/me` calls, waktu konten terlihat, dan visual stability). Tidak menetapkan waktu ms absolut tanpa baseline lingkungan.
- **Aksesibilitas:** navigasi dan loading accessible; animasi opsional mengikuti reduced motion; fokus dan status busy tidak membingungkan.
- **Reliabilitas:** error dan slow API terisolasi; response stale diabaikan; retry aman.
- **Maintainability:** ownership lifecycle page modules jelas, tidak ada duplikasi shell/event listener; manfaatkan skeleton existing.
- **Kompatibilitas:** desktop, tablet, mobile; browser modern yang didukung proyek; Vite production build berhasil.

## 11. Urutan kerja implementasi yang disarankan (bukan tambahan fase bisnis)

1. **UI0-01 — Audit checkout aktual & baseline:** inventaris seluruh route/komponen aktif, rekam bukti spinner saat ini, pahami auth/error/session, catat mismatch baseline remote vs lokal.
2. **UI0-02 — Navigation/session shell:** pilih arsitektur transisi minimal, implementasi shell yang stabil serta validasi sesi aman dan lifecycle route.
3. **UI0-03 — Component skeleton & async isolation:** rapikan skeleton per komponen, independently render, race cancellation, chart/page cleanup, error retry.
4. **UI0-04 — E2E security/regression & handoff:** jalankan gate AC01–AC10, bukti 3 viewport, laporan keputusan dan batasan nyata.

Urutan ini adalah work breakdown **di dalam satu fase UI-0**, bukan empat fase roadmap baru. Implementor boleh mengatur patch sepanjang hasil dan gate dipenuhi.

## 12. Dependensi, risiko, dan mitigasi

| Risiko | Konsekuensi | Mitigasi wajib |
|---|---|---|
| Remote `main` lebih lama dari checkout kerja | Route P0/P1/P2 terbaru terlewat | Audit route/file terkini sebelum implementasi; jangan overwrite perubahan baru. |
| Menghilangkan guard terlalu agresif | Data tampil sebelum auth valid / bocor lintas sesi | Guard server-authoritative, reset UI per sesi, uji 401/expiry/multi-tab. |
| Client navigation tanpa cleanup | Chart bocor, listener ganda, memory leak | Page lifecycle eksplisit, AbortController, destroy ApexCharts, stress test. |
| Navigation intercept rusak | Back/Forward, open-new-tab, deep link gagal | Browser native link semantics dan fallback yang diuji. |
| Skeleton terlalu generik | Layout shift, konten tetap terasa lambat | Bentuk dan ukuran komponen realistis, test throttling 3 viewport. |
| Banyak request serentak | Race, stale response, flicker, beban API | Cancel/sequence, dedupe sesuai kebutuhan, jangan global-block. |
| Menyentuh UI-1/2/3 bersamaan | Scope creep dan regresi visual | Batasi UI-0 pada lifecycle navigasi/loading; visual tokens tetap. |

## 13. Deliverables wajib

- Implementasi UI-0 pada kode yang ditargetkan (belum diminta dieksekusi oleh dokumen ini).
- Test otomatis relevan dan regresi fitur yang sudah ada.
- Catatan arsitektur/keputusan teknis singkat, termasuk pilihan mekanisme navigasi dan session security.
- Matriks route dan komponen yang benar-benar tercakup.
- Evidence screenshot/trace/video (desktop, tablet, mobile; loading/error/auth cases).
- Laporan handoff: ringkasan perubahan, daftar file, hasil AC01–AC10, command test, hasil PASS/FAIL, gap, serta catatan deferred untuk UI-1/2/3.

**Contoh lokasi evidence yang boleh dipakai** (ikuti konvensi repo aktual jika sudah ada):

```text
docs/evidence/ui-0/
  route-inventory.md
  navigation-desktop-before-after/
  navigation-tablet/
  navigation-mobile/
  slow-network-skeleton/
  auth-expiry-and-errors/
  playwright-traces/
  ui-0-verification.md
```

Jangan menyimpan token atau browser storage state authenticated ke repository/evidence.

## 14. Definition of Done / final go-no-go

UI-0 **GO/PASS** hanya jika:

- Semua route authenticated pada checkout terbaru tercakup inventory dan AC relevan.
- Tidak ada full-screen session spinner saat navigasi authenticated normal.
- Shell stabil; skeleton hanya pada area data yang belum siap.
- Validasi sesi tetap aman (401/expired/logout/multi-tab/cold entry).
- Kegagalan data tidak menjatuhkan seluruh halaman; retry tersedia dan aman.
- Browser history, deep link, query, focus, serta mobile drawer bekerja.
- Tidak ada race/stale response/chart/listener leak yang terdeteksi pada tes terarah.
- Regresi bisnis dan API terkait lulus atau kegagalan baseline terdokumentasi dengan pembanding tegas.
- Bukti nyata dan handoff tersedia.

**NO-GO:** jika salah satu persyaratan keselamatan autentikasi gagal; perubahan hanya menyembunyikan spinner tanpa memperbaiki perilaku navigasi; atau pengujian inti belum dijalankan tetapi diklaim PASS.

---

## 15. Referensi kode (baseline remote)

- Layout session guard: https://github.com/joytimess/dip-joyumkm/blob/main/resources/views/layouts/app.blade.php
- Auth guard: https://github.com/joytimess/dip-joyumkm/blob/main/resources/js/auth.js
- Frontend bootstrap: https://github.com/joytimess/dip-joyumkm/blob/main/resources/js/app.js
- API client: https://github.com/joytimess/dip-joyumkm/blob/main/resources/js/api.js
- Nav Blade: https://github.com/joytimess/dip-joyumkm/blob/main/resources/views/components/navigation.blade.php
- Route web: https://github.com/joytimess/dip-joyumkm/blob/main/routes/web.php
- Route API: https://github.com/joytimess/dip-joyumkm/blob/main/routes/api.php
- Existing skeleton/chart lifecycle: https://github.com/joytimess/dip-joyumkm/blob/main/resources/js/charts.js
- Existing auth tests: https://github.com/joytimess/dip-joyumkm/blob/main/tests/Feature/AuthApiTest.php

## 16. Keputusan yang dibawa ke fase berikutnya (bukan scope UI-0)

Roadmap UI JoyUMKM V1 terdiri dari **lima fase total**: `UI-0` Navigation & Loading; `UI-1` Design System (Poppins, token warna asli, ikon SVG dan komponen netral); `UI-2` Workspace/Navigation (Midnight Navy sidebar, grup menu, desktop table dan mobile UMKM card list); `UI-3` Page-Level Visual Refinement (halaman publik tetap layout existing); `UI-4` Visual QA & Regression keseluruhan.

**UI-0 wajib menjaga agar fase berikutnya tetap dapat dikerjakan tanpa merombak ulang mekanisme loading/navigasi.**
