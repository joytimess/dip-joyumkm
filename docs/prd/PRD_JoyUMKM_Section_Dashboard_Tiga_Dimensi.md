# PRD — JoyUMKM: Section Dashboard Kesehatan UMKM (Tiga Dimensi)

**Status:** Brief implementasi berdasarkan wireframe interaktif yang disepakati pada 21 November 2026  
**Jenis pekerjaan:** UI/UX refinement + integrasi data read-only  
**Area:** Dashboard utama JoyUMKM  
**Cakupan:** **Hanya section Kesehatan UMKM — Tiga Dimensi**, ditempatkan **tepat di atas section Penilaian Enam Faktor**  
**Target instrumen:** INS (*Institutional Resources*), ECT (*Economic Connectivity*), OPS (*Operational Stability*)  
**Dokumen ini bukan PRD P4 webhook/Hermes dan bukan perubahan flow penilaian.**

---

## 1. Latar belakang dan masalah

JoyUMKM sudah mempunyai halaman **Kuesioner Tiga Dimensi** (`/form-data`) yang menampilkan progres jawaban, skor keseluruhan, kategori, serta rincian skor INS/ECT/OPS. Namun, informasi hasil instrumen itu belum direpresentasikan secara jelas di dashboard utama sehingga pengguna tidak mempunyai ringkasan kesehatan UMKM berbasis tiga dimensi tanpa masuk ke halaman kuesioner.

Hasil Kuesioner Tiga Dimensi dan **Penilaian Enam Faktor** adalah dua instrumen berbeda. Dashboard harus menampilkannya sebagai section yang terpisah, **tidak mengonversi, menggabungkan, atau membandingkan secara matematis skala skor keduanya**.

### Bukti visual yang menjadi acuan

Screenshot yang diberikan pengguna menunjukkan:

- Tiga tab dimensi: **INS (6 soal), ECT (4 soal), OPS (6 soal)**, keseluruhan **16 soal**.
- Status evaluasi **Final**, progres **16/16 (100%)**, kategori **Sangat Baik**.
- Contoh skor total **90,3/100**, **INS 83,3**, **ECT 100,0**, **OPS 87,5**.
- Pada halaman kuesioner terdapat aksi simpan sebagai Final, pratinjau/simpan Draft, serta reset.

**Semua angka tersebut hanya digunakan sebagai fixture/mock data untuk pengembangan dan QA. Bukan angka tetap atau nilai agregat dashboard yang boleh di-hardcode.**

## 2. Tujuan

1. Menyajikan ringkasan status dan performa Kuesioner Tiga Dimensi secara cepat di dashboard.
2. Menunjukkan distribusi progres penilaian UMKM: **Final**, **Draft**, dan **Belum Dinilai**.
3. Memungkinkan pengguna melihat rincian **satu UMKM** dari section yang sama tanpa kehilangan konteks dashboard.
4. Mempertahankan perbedaan semantik dan rentang skor antara Tiga Dimensi (**0–100**, sesuai representasi UI saat ini) dan Enam Faktor (**6–30**).
5. Tidak mengubah data, penilaian, kategori, skor historis, atau alur Final/Draft existing.

## 3. Scope

### In scope

- Tambah satu section dashboard di atas **Penilaian Enam Faktor** dengan judul **Kesehatan UMKM — Tiga Dimensi**.
- Dua tampilan interaktif: **Portofolio UMKM** dan **Detail UMKM**.
- KPI portofolio, grafik progress-bar INS/ECT/OPS, filter status, daftar UMKM, pemilihan UMKM, dan detail hasil.
- Empty/loading/error/retry states per bagian, bukan spinner yang memblokir seluruh workspace.
- Responsif desktop/mobile, aksesibilitas keyboard, dan format Indonesia.
- Integrasi read-only ke data aktual yang telah disediakan Laravel; endpoint tambahan hanya bila data memang belum tersedia dan wajib bersifat additive.

### Out of scope

- Tidak mengubah 16 pertanyaan, skala jawaban, bobot, scoring, kategori, ataupun cara finalisasi Kuesioner Tiga Dimensi.
- Tidak mengubah logic atau tabel Penilaian Enam Faktor.
- Tidak otomatis memindahkan pengguna dari Tiga Dimensi ke Enam Faktor dan tidak menjadikan salah satunya prasyarat yang lain.
- Tidak membuat formula konversi INS/ECT/OPS ke enam faktor.
- Tidak mengubah kebijakan akses, kepemilikan data, arsip, atau histori instrumen yang sudah ada.
- Tidak mengirim webhook, membuat trigger Hermes, menjalankan AI, atau mengubah Skill P3.
- Tidak membuat halaman baru; detail dapat ditampilkan di dalam section melalui perubahan view.

## 4. Hirarki dan penempatan UI

Dashboard utama secara konseptual:

1. Overview/KPI existing — **dipertahankan**.
2. **[BARU] Kesehatan UMKM — Tiga Dimensi**.
3. Penilaian Enam Faktor existing — **dipertahankan**.
4. Section dashboard lainnya — **dipertahankan**.

Header section:

- **Judul:** `Kesehatan UMKM — Tiga Dimensi`.
- **Subjudul:** `Institutional Resources · Economic Connectivity · Operational Stability` atau label bahasa Indonesia yang konsisten dengan UI kuesioner.
- **Tab/segmented control:** `Portofolio UMKM` dan `Detail UMKM`.
- Jangan menggunakan istilah "Enam Faktor" sebagai label di dalam hasil INS/ECT/OPS.

## 5. Tampilan A — Portofolio UMKM (default)

### 5.1 Empat kartu KPI

| Kartu | Nilai dan aturan | Catatan |
|---|---|---|
| Rata-rata kesehatan | Rerata skor keseluruhan terbaru **Final** dari setiap UMKM yang masuk cakupan dashboard; format `xx,x/100` | Jika tidak ada hasil Final, tampilkan `—`, bukan `0` |
| UMKM terpantau | Jumlah UMKM pada cakupan/dashboard aktif sesuai filter hak akses existing | Tidak dihitung dari banyaknya evaluasi |
| Evaluasi Final | Jumlah UMKM yang memiliki minimal satu penilaian Tiga Dimensi Final | Tampilkan jumlah dan proporsi terhadap UMKM terpantau |
| Belum Final | Jumlah UMKM tanpa hasil Final; breakdown Draft dan Belum Dinilai | Draft yang berjalan **tidak membatalkan** hasil Final terdahulu |

**Aturan status tingkat UMKM agar tidak ambigu:**

- **Final:** terdapat setidaknya satu hasil Final yang sah. Gunakan **Final terbaru** sebagai basis skor resmi. Jika ada Draft aktif lebih baru, beri indikator tambahan `Draft berjalan`, bukan mengganti skor Final dengan nilai Draft.
- **Draft:** belum ada Final, tetapi terdapat Draft aktif/tersimpan.
- **Belum Dinilai:** belum ada Final ataupun Draft relevan.
- Evaluasi dibatalkan atau record yang tidak sah tidak dianggap Final.
- Bila data status dari backend berbeda, petakan berdasarkan state domain aktual, bukan tebak dari keberadaan skor atau tanggal semata.

### 5.2 Profil rata-rata INS/ECT/OPS

Tiga baris indikator horizontal:

| Kode | Label | Nilai |
|---|---|---|
| INS | Institutional Resources / Sumber Daya Kelembagaan | Skor rerata dimensi INS dari hasil Final terbaru per UMKM |
| ECT | Economic Connectivity / Konektivitas Ekonomi | Skor rerata dimensi ECT dari hasil Final terbaru per UMKM |
| OPS | Operational Stability / Stabilitas Operasional | Skor rerata dimensi OPS dari hasil Final terbaru per UMKM |

- Bar mengikuti skala **0–100** pada UI, persentase lebar = nilai/100.
- Nilai di sebelah kanan, satu digit desimal, misalnya `83,3/100`.
- Label, angka, dan bar harus dapat dipahami tanpa mengandalkan warna.
- Rata-rata portofolio hanya menggunakan hasil Final (maksimal satu snapshot Final terbaru per UMKM).
- Jangan mengambil rerata Draft untuk KPI atau progress-bar resmi.

### 5.3 Ringkasan per UMKM

Tampilkan daftar kartu/baris interaktif, berisi:

- Nama UMKM sesuai yang dikembalikan API, dengan pembatasan akses existing.
- Status utama: **Final / Draft / Belum Dinilai**.
- Jika Final: skor `xx,x/100` dan label `Final`.
- Jika Draft: progres `x dari 16 soal terjawab`, jika tersedia dari API.
- Jika Belum Dinilai: keterangan `Belum ada kuesioner`.
- Klik/tap satu baris mengubah tampilan section ke **Detail UMKM** dan memilih UMKM tersebut.

**Filter status:** `Semua status`, `Final saja`, `Draft saja`, `Belum dinilai`.

- Filter hanya mengubah daftar UMKM, **tidak** mengubah KPI/agregat portofolio, kecuali ada label dan rancangan terpisah yang menyatakan agregat ikut terfilter. Untuk brief ini, agregat tetap global sesuai cakupan dashboard.
- Jika daftar hasil filter kosong, tampilkan empty state kontekstual.
- Daftar menyesuaikan pagination/limit dari backend yang nyata; jangan memuat dataset tidak terbatas hanya untuk render UI. Jika dataset paginated, agregat harus dihitung dari seluruh cakupan yang berizin, bukan hanya halaman yang sedang tampil.

## 6. Tampilan B — Detail UMKM

### 6.1 Pemilihan UMKM

- Select/picker `Pilih UMKM` menggunakan daftar UMKM yang boleh diakses oleh pengguna.
- Klik kartu dari tampilan Portofolio membuka detail dengan UMKM yang diklik telah terpilih.
- Pemilihan UMKM baru memperbarui isi detail tanpa mengubah route/dashboard global secara tidak terduga.
- Tombol `Kembali ke ringkasan portofolio` kembali ke tampilan A; pertahankan filter yang sebelumnya dipilih.

### 6.2 Kondisi Final

Tampilkan:

1. **Status:** `Final`.
2. **Skor keseluruhan:** contoh `90,3/100`.
3. **Kategori resmi:** contoh `SANGAT BAIK`, gunakan label yang dikirim/diturunkan oleh sistem existing, **bukan** penentuan kategori ulang di frontend.
4. **Kelengkapan:** contoh `16/16` dan/atau `100%`.
5. **Rincian tiga dimensi:** bar INS, ECT, OPS lengkap dengan skor satu desimal.
6. **Tanggal hasil Final terbaru:** jika tersedia, tampilkan dalam zona waktu/format lokal yang jelas. Jangan mengarang tanggal bila kosong.
7. **Interpretasi ringkas non-AI:** opsional, berupa *perbandingan numerik* yang jelas diberi label, seperti `ECT memiliki skor tertinggi; INS terendah` bila data mendukung. Ini bukan diagnosis, rekomendasi AI, atau perubahan kategori resmi.
8. Jika ada Draft aktif tambahan, berikan label `Ada Draft berjalan` tanpa menimpa snapshot Final.

Semua data resmi berasal dari backend; frontend hanya memformat dan menyajikan.

### 6.3 Kondisi Draft tanpa hasil Final

- Status `Draft`.
- Progres jawaban `x/16` dan progress bar jika jumlah jawaban tersedia.
- Keterangan `Penilaian belum difinalisasi. Hasil sementara tidak dianggap skor resmi.`
- **Jangan** tampilkan skor/kategori Draft sebagai hasil kesehatan resmi.
- Jika entry point kuesioner existing mendukung membuka/lanjutkan Draft untuk UMKM tersebut, boleh ada tautan kontekstual; jangan menciptakan endpoint baru tanpa audit.

### 6.4 Kondisi Belum Dinilai

- Empty state dengan ikon netral dan copy: `Belum ada penilaian Tiga Dimensi`.
- Deskripsi: `Belum tersedia hasil INS, ECT, dan OPS untuk UMKM ini.`
- Jangan mengisi nilai `0/100` atau kategori buatan.
- Opsional: tautan `Isi kuesioner` hanya jika dapat diarahkan ke entry point kuesioner existing dengan UMKM yang tepat.

### 6.5 Kondisi data tidak lengkap/inkonsisten

- Jika backend mengembalikan status Final tetapi skor atau rincian dimensi hilang/tidak valid: jangan render angka salah atau mengasumsikan nol.
- Tampilkan notifikasi ringan `Data hasil belum lengkap` dan tindakan retry; log error yang aman untuk debugging.
- Jangan menghitung ulang skor resmi di frontend untuk mengatasi inkonsistensi backend.

## 7. Perilaku interaktif dan state

| ID | Tindakan | Perilaku yang diharapkan |
|---|---|---|
| INT-01 | Halaman dashboard dibuka | Default tab `Portofolio UMKM`; section tidak menghambat section lain |
| INT-02 | Klik `Detail UMKM` | Picker dan state/detail UMKM ditampilkan |
| INT-03 | Klik baris UMKM | Pindah ke detail dengan UMKM itu terpilih |
| INT-04 | Ganti pilihan UMKM | Detail berubah, tanpa mengirim mutasi |
| INT-05 | Filter `Final/Draft/Belum dinilai` | Hanya daftar ringkasan berubah |
| INT-06 | Kembali ke Portofolio | Tab portofolio tampil; filter dipertahankan selama komponen aktif |
| INT-07 | Data masih dimuat | Skeleton lokal di KPI, chart, dan daftar; layout tetap stabil |
| INT-08 | Permintaan salah satu komponen gagal | Komponen itu menampilkan pesan error dan `Coba lagi`; bagian lain tetap aktif |
| INT-09 | Data tidak tersedia | Empty state tanpa skor/kategori rekaan |
| INT-10 | Refresh data berhasil | Snapshot terbaru ditampilkan sesuai backend; tidak membuat Final baru |

**Penting:** Interaksi section bersifat **read-only**. Tombol Draft/Final/Reset tetap berada di modul kuesioner existing, bukan bagian dari section dashboard baru.

## 8. Sumber data dan kontrak integrasi

**Jangan mengarang nama endpoint atau struktur tabel baru sebelum mengaudit checkout Laravel saat implementasi.**

Frontend membutuhkan bentuk data konseptual berikut (mapping ke kontrak aktual dilakukan saat implementasi):

```json
{
  "summary": {
    "total_umkm": 3,
    "with_final": 1,
    "draft_only": 1,
    "not_assessed": 1,
    "overall_final_average": 90.3,
    "dimension_final_averages": { "INS": 83.3, "ECT": 100.0, "OPS": 87.5 }
  },
  "items": [
    {
      "umkm_id": "contoh-1",
      "name": "UMKM Contoh A",
      "state": "Final",
      "has_active_draft": false,
      "latest_final": {
        "overall_score": 90.3,
        "category": "SANGAT BAIK",
        "answered_count": 16,
        "question_count": 16,
        "dimensions": { "INS": 83.3, "ECT": 100.0, "OPS": 87.5 },
        "finalized_at": null
      }
    }
  ]
}
```

> **Contoh di atas adalah model data untuk memandu UI, bukan kontrak API final.** Field names, sumber timestamp, tipe kategori, dan batasan arsip harus diaudit dari data nyata. `finalized_at: null` pada fixture berarti tanggal tidak tersedia, bukan tanggal tertentu. `summary` harus mengikuti kepemilikan/akses dan kebijakan cakupan dashboard existing.

### Prinsip integrasi

- Ambil data menggunakan API/auth yang sudah berlaku; tidak mengakses MySQL langsung dari frontend.
- Server menerapkan ownership/perizinan; jangan mengandalkan filter frontend untuk keamanan.
- Pakai hasil Final dan perhitungan kategori yang sudah tersimpan/ditentukan backend.
- Jika agregasi belum disediakan API, implementor boleh mengusulkan endpoint GET read-only yang additive dan diuji; jangan memodifikasi endpoint write existing.
- Hindari N+1 request per UMKM pada daftar portofolio.
- Jangan mengubah sistem penilaian legacy, aturan instrumen, maupun kebijakan read-only yang berlaku di checkout aktual.

## 9. Visual dan responsivitas

Ikuti design system JoyUMKM existing:

- Tipografi **Poppins**.
- Warna merek **#0207CA** (primary), **#0297DE** (secondary), **#FC734D** (accent); latar dan teks dominan netral.
- Style SaaS profesional, border halus, radius konsisten, padding teratur; tidak menambah desain halaman baru di luar section.
- Desktop: empat KPI disusun dalam grid yang responsif; grafik tiga dimensi dalam satu kolom yang mudah dipindai; daftar UMKM rapi.
- Mobile: seluruh blok ditumpuk secara vertikal, tidak ada horizontal overflow, tap target nyaman, nama UMKM panjang di-wrap, dan picker/filter dapat dioperasikan.
- State aktif segmented control terlihat jelas tetapi tidak terlalu kontras.
- Badge status tidak hanya dibedakan oleh warna; selalu ada teks.
- Bar INS/ECT/OPS menampilkan nilai numerik; screen reader mendapat label indikator dan nilai yang bermakna.
- Tab/segmented control, filter, picker, item interaktif, dan tombol kembali mendukung keyboard, fokus terlihat, serta urutan tab logis.

## 10. Ketentuan data, matematika, dan historis

1. Skor `0–100` Tiga Dimensi tidak disetarakan dengan skor `6–30` Penilaian Enam Faktor.
2. Rerata dihitung dari **satu Final terbaru per UMKM**, bukan dari seluruh riwayat yang dapat membuat UMKM dengan banyak pengukuran berbobot berlebihan.
3. Ambang kategori dan penamaan kategori harus mengikuti backend/instrumen yang sudah berlaku.
4. Data Draft tidak masuk statistik skor kesehatan resmi.
5. UMKM yang sudah Final kemudian memiliki Draft baru tetap masuk hitungan `with_final`; Draft hanya ditandai sebagai aktivitas tambahan.
6. Kategori `Belum Final` pada KPI berarti UMKM **tanpa** Final: `draft_only + not_assessed`.
7. Jika tidak ada Final, skor rata-rata dan dimensi ditampilkan sebagai `—`; hindari pembagian nol.
8. Cakupan UMKM aktif/arsip menggunakan aturan dashboard existing; tampilkan label cakupan jika ada potensi ambigu.
9. Nama, skor, status, dan tanggal dari screenshot merupakan **fixture demo**, bukan angka untuk produksi.

## 11. Acceptance criteria (finite)

| AC | Kriteria penerimaan |
|---|---|
| TD-01 | Section baru muncul **tepat di atas** Penilaian Enam Faktor pada dashboard, tanpa merusak section existing. |
| TD-02 | Default `Portofolio UMKM`; toggle ke `Detail UMKM` berfungsi. |
| TD-03 | Empat KPI menggunakan data aktual dan rumus/cakupan yang benar; nilai kosong bukan nol. |
| TD-04 | Tiga progress-bar INS/ECT/OPS menggunakan skor resmi dari Final terbaru, maksimal satu Final per UMKM. |
| TD-05 | Filter status mengubah daftar, dan filter portofolio dipertahankan saat kembali dari detail. |
| TD-06 | Klik item UMKM membuka detail UMKM yang benar; pemilihan melalui picker juga benar. |
| TD-07 | Detail Final menampilkan skor, kategori resmi, 16 soal/progres bila tersedia, tiga dimensi, dan tanggal bila ada. |
| TD-08 | Detail Draft menampilkan progres tanpa membuat skor/kategori resmi. |
| TD-09 | Detail Belum Dinilai menampilkan empty state tanpa nilai rekaan. |
| TD-10 | UMKM yang punya Final + Draft berjalan tetap menampilkan skor Final terakhir dan badge Draft tambahan. |
| TD-11 | Data tidak lengkap, API error, dan loading ditangani per-komponen dengan retry yang berfungsi; tidak ada spinner full-screen pada navigasi internal. |
| TD-12 | UI desktop dan mobile tidak overflow; keyboard, fokus, label status, dan aksesibilitas bar diuji. |
| TD-13 | Ownership/auth dan kebijakan arsip yang sudah ada tetap ditegakkan; frontend tidak menerima data UMKM di luar izin. |
| TD-14 | Tidak ada perubahan scoring, finalisasi, tabel historis, endpoint mutasi, workflow enam faktor, maupun Hermes. |
| TD-15 | Regresi dashboard existing, `/form-data`, dan halaman Enam Faktor lulus dengan bukti tes yang relevan. |

**Status kriteria hanya boleh PASS / FAIL / NOT RUN berdasarkan hasil yang benar-benar diuji. Jangan menganggap mock UI sebagai bukti integrasi backend.**

## 12. Rencana kerja implementor

1. **Audit singkat:** identifikasi struktur dashboard saat ini, API data Tiga Dimensi, state Draft/Final, histori, kategori, aturan ownership, serta sumber skor yang digunakan `/form-data`.
2. **Bangun section:** komponen read-only dengan dua view, state yang didefinisikan, dan tampilan responsif sesuai wireframe.
3. **Integrasi:** sambungkan ke API existing; jika agregasi belum tersedia, tambahkan read endpoint minimal sesuai kebutuhan tanpa mengubah write flow.
4. **QA:** unit/component test, request/response test, serta browser verification desktop + mobile untuk Final, Draft, Empty, error, dan data banyak.
5. **Handoff:** ringkas perubahan, sumber data/endpoint aktual, hasil TD-01 sampai TD-15, screenshot dashboard, dan catat risiko tersisa tanpa meluaskan scope.

## 13. Batasan dan keputusan yang sengaja ditahan

- **Tidak ada keputusan baru** tentang apakah Tiga Dimensi wajib diisi sebelum Enam Faktor. Keduanya tetap independen sampai aturan bisnisnya diputuskan dalam pekerjaan terpisah.
- Hubungan penilaian Tiga Dimensi dengan webhook Hermes **tidak dibahas** oleh PRD dashboard ini.
- Penggunaan istilah `INS / ECT / OPS` dan contoh `16/16` mengikuti screenshot; bila implementasi existing memiliki kondisi historis dengan jumlah pertanyaan berbeda, frontend harus membaca metadata yang tersedia dan tidak merusak data lama.
- Nama endpoint dan field JSON contoh tidak boleh dianggap sumber kebenaran sebelum audit kode.

---

**Output yang diminta:** satu section dashboard siap pakai yang mencerminkan wireframe interaktif, data aktual dari Laravel, tidak mengubah alur penilaian existing, dan lulus acceptance criteria terarah.
