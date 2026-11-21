# PRD — JoyUMKM Dashboard: Summary Penilaian Enam Faktor Seluruh UMKM

**Tanggal:** 21 November 2026  
**Status:** Approved design brief — siap untuk implementasi setelah audit kontrak API aktual  
**Cakupan:** Satu section dashboard *Penilaian Enam Faktor* saja  
**Referensi desain:** `wireframe_enam_faktor_all_umkm.html` (wireframe yang telah disetujui)  

## 1. Tujuan dan keputusan yang disetujui

Ganti section Penilaian Enam Faktor yang besar (termasuk radar dan tab) dengan **daftar/grid compact card untuk seluruh UMKM milik akun yang sedang login**. **Tidak ada selector satu UMKM.** Setiap card mewakili satu UMKM dan menampilkan **hasil Final enam faktor terbaru** bila tersedia. Dashboard menampilkan ringkasan, bukan halaman pengisian.

Keputusan final:
1. Satu compact card **per UMKM**; seluruh UMKM dapat ditelusuri melalui pencarian, filter, urutan, dan pagination.
2. Tampilkan total resmi `/30`, kategori resmi, enam nilai `/5`, waktu Final terbaru, dan identitas UMKM ringkas.
3. **Tidak ada radar chart, tab Draft, maupun histori** di section dashboard; fitur tersebut berada di halaman penilaian/detail yang sudah ada.
4. UMKM yang belum punya Final **tetap tampil** sebagai card *Belum ada Final*; jangan menganggap Draft sebagai Final.
5. Section Tiga Dimensi dan mekanisme skornya **tidak diubah**. Tidak ada penggabungan nilai Tiga Dimensi dengan Enam Faktor.
6. Tidak ada perubahan aturan scoring, state machine Draft/Final/Cancelled, histori, atau integrasi Hermes.

## 2. Scope

**In scope:** refactor UI section dashboard; KPI agregat; API read-only terpaginasikan bila endpoint yang ada belum memadai; query hasil Final terbaru per UMKM; pencarian, filter, sorting, pagination; navigasi detail; loading/empty/error; keamanan ownership; automated tests.

**Out of scope:** perubahan formulir penilaian; konversi V1/INS/ECT/OPS; kalkulasi ulang skor resmi; penambahan AI; webhook Hermes; perubahan section Tiga Dimensi; deployment; penghapusan data.

## 3. Struktur UI final

Urutan dalam section:
- Header: **Penilaian Enam Faktor**; subtitle “Ringkasan hasil Final terbaru dari seluruh UMKM”; keterangan skala **1–5 per faktor, total 6–30**; tautan “Buka halaman penilaian”.
- Empat KPI: **Total UMKM**, **Sudah Final**, **Belum ada Final**, **Rata-rata skor Final /30**.
- Toolbar: cari nama/sektor (opsional lokasi jika tersedia); filter **Semua / Sudah Final / Belum ada Final**; urutkan **Nama A–Z / Skor tertinggi / Skor terendah**.
- Grid compact card: **2 kolom desktop**, **1 kolom tablet/mobile**; enam faktor dapat dibungkus **3×2 pada layar sempit**.
- Pagination dengan rentang “Menampilkan X–Y dari Z UMKM”, tombol Sebelumnya/Berikutnya.
- Catatan: hanya hasil Final resmi, tanpa mencampur Tiga Dimensi.

### 3.1 Card dengan Final
Tampilkan:
- Nama UMKM, sektor dan lokasi **hanya jika data sudah ada** (jangan menciptakan atribut).
- Badge **Final**.
- `total_score/30`, `category` dari Laravel.
- Enam kotak nilai berurutan: **SD** sumber_daya, **KP** kepemimpinan, **LK** lingkungan_kerja, **NO** nilai_organisasi, **ST** stabilitas, **KI** kinerja; setiap kotak `n/5`, dengan tooltip/accessible label nama lengkap.
- Tanggal/waktu `finalized_at` dengan zona waktu yang konsisten dan format lokal.
- Tautan **Lihat detail** menuju halaman penilaian UMKM tersebut.

### 3.2 Card tanpa Final
- Identitas UMKM tetap muncul.
- Badge **Belum ada Final**; tidak menampilkan `0/30`, kategori, tanggal Final, atau skor Draft.
- Pesan “Belum ada hasil Final untuk UMKM ini.”
- Tautan **Buka penilaian** menuju halaman penilaian UMKM yang sama. Di halaman tersebut Draft dapat dilanjutkan sesuai alur existing.

### 3.3 KPI
Semua KPI mengikuti **cakupan UMKM yang sama**, yaitu UMKM aktif milik pengguna (kecuali produk saat ini memang menampilkan arsip melalui opsi terpisah; jangan diam-diam memasukkan arsip). KPI dihitung **di seluruh himpunan**, bukan hanya 4 card di halaman aktif, dan **tidak berubah oleh pagination**.

- `total_umkm`: jumlah UMKM dalam cakupan.
- `sudah_final`: jumlah UMKM yang mempunyai ≥1 penilaian enam faktor berstatus `final`.
- `belum_ada_final`: `total_umkm - sudah_final` (termasuk UMKM dengan Draft saja).
- `rata_rata_skor_final`: AVG `total_score` dari **satu Final terbaru per UMKM**, bukan AVG seluruh histori Final. Jika tidak ada Final, tampilkan **—**, bukan NaN atau `0/30`. Pembulatan tampilan satu desimal, tanpa mengubah data resmi.

**Filter/pencarian:** KPI secara default merepresentasikan keseluruhan cakupan UMKM; bila produk ingin KPI dinamis mengikuti filter, itu keputusan UX terpisah dan harus diberi label jelas. Untuk implementasi ini, **KPI tetap global dalam cakupan akun**, sedangkan jumlah pagination mengikuti filter/pencarian.

## 4. Sumber data dan aturan fetch

### 4.1 Sumber otoritatif
- Tabel UMKM Laravel/MySQL yang sudah ada: identitas dan ownership.
- Tabel `six_factor_evaluations` yang sudah ada: status `draft|final|cancelled`, enam nilai faktor, `total_score`, `category`, `finalized_at`, `umkm_id`.
- **Hanya** status `final` menjadi sumber nilai resmi dashboard. `legacy_v1`/INS/ECT/OPS tidak ikut query ini.
- Jika satu UMKM memiliki banyak Final, pilih **`finalized_at DESC`, lalu `id DESC`** sebagai tie-break deterministik. Bila implementasi domain saat ini memiliki aturan urutan resmi yang berbeda, audit dan gunakan aturan domain yang telah diuji; jangan membuat dua definisi “terbaru”.

### 4.2 Strategi fetch (direkomendasikan)
**Satu request read-only terpaginasikan untuk daftar card + KPI agregat.** Backend melakukan query join/subquery yang mengambil *latest Final per UMKM* secara efisien. Frontend **jangan** mengambil seluruh UMKM lalu melakukan N request per UMKM ke endpoint `latest` (N+1), dan jangan mengambil seluruh histori untuk menyusun ringkasan.

Langkah implementasi:
1. **Audit endpoint yang sudah ada** (`/api/v2`, endpoint daftar UMKM, latest Final, dan dashboard). Reuse jika mampu memberikan daftar + latest Final + pagination tanpa N+1.
2. Bila belum memadai, buat endpoint baca baru yang terpisah, misalnya **`GET /api/v2/dashboard/six-factor-summary`**. Path ini **usulan**, bukan klaim bahwa endpoint sudah tersedia.
3. Backend memastikan autentikasi Sanctum dan ownership setiap UMKM, kemudian membatasi UMKM sesuai kebijakan arsip yang sudah ada.
4. Terapkan pencarian/filter/sort di **database sebelum pagination**; hitung `total` filter dari query yang sama.
5. Backend mengembalikan KPI agregat keseluruhan akun secara konsisten dan `items` hasil halaman.
6. Frontend memuat data secara independen (skeleton pada section saja), mempertahankan sidebar/header dan section dashboard lain.
7. Saat ada Final baru, invalidate/refetch cache section; jangan menunggu refresh halaman penuh.

### 4.3 Parameter request yang diusulkan

`GET /api/v2/dashboard/six-factor-summary?page=1&per_page=4&search=&status=all&sort=name_asc`

| Parameter | Nilai | Aturan |
|---|---|---|
| `page` | integer ≥1 | default 1 |
| `per_page` | integer 1–24 | default 4; cap di server |
| `search` | string | nama/sektor, dan lokasi jika tersedia; trim, panjang maksimal ditentukan backend |
| `status` | `all`, `final`, `none` | `none` berarti tidak punya **Final**, walaupun Draft ada |
| `sort` | `name_asc`, `score_desc`, `score_asc` | whitelist, bukan nama kolom SQL mentah |

Urutan `score_desc`/`score_asc`: hanya UMKM ber-Final diurut berdasarkan total; **UMKM tanpa Final selalu di belakang** untuk kedua arah; tie-break nama UMKM dan ID stabil. `name_asc` juga memiliki tie-break ID. Search/filter/sort mengganti `page` ke 1 di UI.

### 4.4 Contoh respons (kontrak usulan; cocokkan dengan Resource Laravel existing)

```json
{
  "data": [
    {
      "umkm_id": 123,
      "nama": "Jaya Barokah",
      "sektor": "Makanan & Minuman",
      "lokasi": null,
      "has_final": true,
      "latest_final": {
        "id": 876,
        "status": "final",
        "total_score": 29,
        "category": "Sangat Sehat",
        "finalized_at": "2026-11-21T13:50:00+07:00",
        "factors": {
          "sumber_daya": 4,
          "kepemimpinan": 5,
          "lingkungan_kerja": 5,
          "nilai_organisasi": 5,
          "stabilitas": 5,
          "kinerja": 5
        }
      }
    },
    {
      "umkm_id": 124,
      "nama": "Toko Sinar Jaya",
      "sektor": "Perdagangan",
      "lokasi": null,
      "has_final": false,
      "latest_final": null
    }
  ],
  "summary": {
    "total_umkm": 7,
    "sudah_final": 5,
    "belum_ada_final": 2,
    "rata_rata_skor_final": 22.8
  },
  "meta": {
    "current_page": 1,
    "per_page": 4,
    "total": 7,
    "last_page": 2
  }
}
```

**Catatan:** Nama kolom JSON `nama`, `sektor`, `category`, `factors` adalah **contoh bentuk response**, bukan jaminan nama atribut di repo. Implementor wajib memetakan dari skema/Resource nyata tanpa mengubah kontrak lain secara merusak. Skor/kategori contoh adalah data ilustrasi.

### 4.5 Strategi query MySQL
- Query utama dimulai dari UMKM yang diizinkan oleh policy ownership; `LEFT JOIN` ke **satu** evaluasi Final terbaru per UMKM (mis. `ROW_NUMBER() OVER (PARTITION BY umkm_id ORDER BY finalized_at DESC, id DESC)` pada MySQL 8 atau subquery berkorelasi yang terindeks).
- Hindari `GROUP BY umkm_id` dengan kolom non-agregat yang menghasilkan baris Final arbitrer.
- Filter `none` menggunakan `NOT EXISTS` atau `latest_final IS NULL`; jangan menyaring UMKM tanpa Final secara tidak sengaja dengan `WHERE` pada hasil `LEFT JOIN`.
- Indeks yang sudah ada ditinjau lebih dulu; tambahkan indeks `(umkm_id,status,finalized_at,id)` **hanya jika** EXPLAIN/pengujian menunjukkan kebutuhan dan migration additive aman.
- KPI dihitung dari himpunan UMKM yang sama dan latest Final per UMKM; jangan menduplikasi perhitungan kategori.

## 5. State UI, UX dan navigasi

- **Loading:** skeleton KPI + card di dalam section; dashboard lain tetap interaktif.
- **Error:** pesan spesifik + tombol *Coba lagi*; jangan menampilkan data palsu atau spinner fullscreen.
- **No UMKM:** empty state “Belum ada UMKM”, CTA menuju daftar/tambah UMKM yang sudah ada.
- **No search results:** “Tidak ada UMKM yang cocok”, tombol reset filter.
- **UMKM ada, nol Final:** semua card menampilkan *Belum ada Final*, KPI rata-rata `—`.
- **Draft aktif tetapi belum pernah Final:** card tetap *Belum ada Final*.
- **Draft baru setelah Final lama:** card tetap menunjukkan **Final terakhir yang sah**, bukan Draft.
- **Arsip:** ikuti scope aktif/arsip pada daftar UMKM existing; jangan mencampur data arsip tanpa penjelasan.
- **Detail:** klik card/tautan menuju route penilaian nyata untuk `umkm_id` yang dipilih (contoh handoff `/umkms/{id}/six-factor-evaluations`, verifikasi route aktual). Radar, Draft, histori berada di halaman tersebut; tidak membuat modal preview sebagai fitur produksi.
- **Aksesibilitas:** label lengkap faktor; tab/keyboard; fokus terlihat; kategori bukan dibedakan warna saja; teks tidak terpotong pada mobile; format skor dan tanggal lokal.

## 6. Konsistensi dan keamanan

- Semua query dibatasi `user_id`/ownership sesuai policy Laravel; tidak boleh ada UMKM akun lain dalam daftar, KPI, maupun pencarian.
- Gunakan parameter binding, whitelist sorting, validasi parameter, limit pagination.
- Field `total_score` dan `category` berasal dari **snapshot resmi Final**, bukan dihitung ulang oleh JS atau AI.
- Data V1/INS/ECT/OPS tidak diikutkan.
- Setelah finalisasi sukses, summary dapat direfresh; kegagalan fetch hanya memengaruhi section.
- Tidak mengubah kontrak write, status Final, atau catatan historis.

## 7. Acceptance Criteria (AC)

| ID | Kriteria |
|---|---|
| SF-01 | Section menampilkan **seluruh UMKM** dalam card terpaginasikan, tanpa selector satu UMKM. |
| SF-02 | Grid dua kolom desktop, satu kolom mobile; enam nilai terbaca pada 320px. |
| SF-03 | Setiap UMKM ber-Final menampilkan **satu Final terbaru** berdasarkan urutan deterministik; skor, kategori, faktor, waktu sesuai database. |
| SF-04 | UMKM tanpa Final tetap ada dengan status *Belum ada Final* dan tanpa skor resmi palsu. |
| SF-05 | Draft/Cancelled tidak menjadi sumber nilai dashboard; Final lama tetap muncul jika Draft baru aktif. |
| SF-06 | Empat KPI benar untuk semua UMKM dalam cakupan; rata-rata hanya latest Final per UMKM; nol Final → `—`. |
| SF-07 | Pencarian nama/sektor, filter status, dan urutan bekerja **sebelum pagination** dan tidak bocor lintas akun. |
| SF-08 | Pagination stabil, count benar, next/prev aman, reset halaman ketika filter berubah. |
| SF-09 | Fetch tidak melakukan N+1 per UMKM atau mengambil semua histori; query efisien pada MySQL. |
| SF-10 | Radar chart, Draft, histori tidak ada di dashboard; tersedia di halaman detail existing. |
| SF-11 | Tautan setiap card membuka UMKM yang benar; tidak menggunakan modal demo sebagai fitur produksi. |
| SF-12 | Skeleton section, error/retry, no UMKM, no results, dan tanpa Final ditangani terpisah. |
| SF-13 | Hanya akun pemilik dapat membaca data; parameter buruk ditolak; sorting aman. |
| SF-14 | Tidak mengubah Tiga Dimensi, V1, scoring, state transition, data histori, atau Hermes. |
| SF-15 | Test API/MySQL untuk tie `finalized_at`, banyak Final, no Final, Draft sesudah Final, filter, pagination, ownership dan KPI lulus. |
| SF-16 | Test browser responsive, keyboard, loading, empty, error, pencarian dan navigasi lulus. |

## 8. Implementasi dan verifikasi

1. Audit skema, policy, endpoint, resource, halaman dashboard dan route detail **yang benar-benar ada**.
2. Implementasi query/read API paling kecil yang memenuhi kebutuhan; **reuse dulu** jika tersedia.
3. Refactor section Enam Faktor saja mengikuti wireframe yang disetujui.
4. Tambahkan test MySQL untuk data campuran (Final, Draft, Cancelled, multi-Final, archived, multi-owner), serta browser UI.
5. Laporkan PASS/FAIL/NOT RUN per AC dan bukti. Jangan menganggap screenshot prototype sebagai bukti integrasi API.
6. Jangan melakukan deploy/commit/push tanpa otorisasi terpisah.

## 9. Definition of Done

**Selesai** bila seluruh SF-01..SF-16 PASS dengan bukti, UI sesuai wireframe all-UMKM, data berasal dari Laravel/MySQL aktual, tidak ada N+1, dan regresi penilaian existing tidak rusak. Tidak perlu memperluas scope ke section lain atau webhook Hermes.
