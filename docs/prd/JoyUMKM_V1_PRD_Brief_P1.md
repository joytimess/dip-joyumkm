# JoyUMKM V1 · PRD Brief P1
## API Laravel untuk penilaian enam faktor per UMKM

**20 November 2026 · Spesifikasi disetujui · Dasar implementasi adalah handoff P0 pasca-reset.**

Pemilik UMKM dapat memulai penilaian, menyimpannya sebagian, melanjutkannya, lalu memilih Final atau Batal. Setelah finalisasi, aplikasi dapat membaca hasil terbaru dan histori milik UMKM tersebut. P1 menyediakan seluruh alur melalui API Laravel dalam satu fase. Integrasi layar, autosave browser, grafik radar, dan dashboard berada di luar P1.

## 1. Titik awal dari handoff P0

| Aspek | Hasil yang diterima | Implikasi untuk P1 |
|---|---|---|
| Kandidat | P0 PASS pada perubahan working tree berbasis `main@804738b0a39f9dc2658e40e92e796077d131aca6`. Handoff memuat SHA-256 tujuh berkas kandidat. | Kerjakan di atas kandidat yang memuat P0. HEAD saja belum mencakup perubahan tersebut. |
| Database | Migration P0 sudah berjalan pada MySQL 8.4.3 di `joyumkm_testing`. Saat handoff, database aplikasi `joyumkm-v2` masih memiliki delapan migration V1 dan belum menerima migration P0. | Gunakan skema uji terisolasi untuk pengujian. P1 tidak memerlukan migration tambahan. |
| Domain | `SixFactorEvaluationService` menangani Draft, Final, Cancel, histori dan hasil terbaru. DB menjamin satu Draft aktif per UMKM. Urutan lock adalah UMKM lalu evaluasi. | Delegasikan penulisan dan perhitungan ke layanan P0. Hindari bulk update yang melewati guard model. |
| Penghapusan | FK RESTRICT memblokir hapus UMKM jika ada evaluasi enam faktor. HTTP lama masih dapat meneruskan error MySQL 1451. | Petakan konflik ini ke 409 dengan pesan publik, termasuk jika FK menjadi pengaman terakhir saat dua request bersamaan. |
| QA P0 | 35/35 tes fitur lulus, 355 assertion. PHP penuh 128/133, dengan tiga kegagalan dan dua error lama yang sama seperti baseline 93/98. JavaScript 26/26 lulus. | Jadikan hasil ini pembanding awal. Identifikasi kasus lama menurut nama dan jenis kegagalan saat membandingkan hasil P1. |

Handoff P1 sebelum reset mencatat 79/79 tes P1 lulus, subset reviewer 154 lulus, dan suite penuh 225 lulus dengan lima kegagalan lama. Angka tersebut hanya berlaku untuk kandidat historis dengan manifest `2a28d6043d21d7f406ad536fc523ceeb01c456617dd92ce666c383e44afd799c`. Status implementasi P1 pasca-reset ditentukan dari pengujian kandidat baru.

## 2. Kontrak HTTP untuk frontend

Prefix seluruh path di bawah adalah `/api/umkms/{id}/six-factor-evaluations`. Gunakan ID numerik, autentikasi Sanctum, `Accept: application/json`, dan `Content-Type: application/json` untuk request JSON. Seluruh respons sukses membungkus hasil dalam `data`.

| ID | Method dan suffix | Perilaku yang diterima |
|---|---|---|
| E1 | GET `/draft` | 200 dengan Draft aktif atau `null`. Membaca tidak menciptakan Draft. |
| E2 | PUT atau PATCH `/draft` | Kedua method memperbarui hanya faktor terkirim. Draft pertama menghasilkan 201. Penyimpanan berikutnya menghasilkan 200 dengan ID yang sama. |
| E3 | POST `/{evaluationId}/finalize` | 200 dengan objek Final ber-ID sama. Keenam skor tersimpan wajib lengkap. Server menghitung total, kategori dan waktu finalisasi. |
| E4 | POST `/{evaluationId}/cancel` | 200 dengan objek Cancelled ber-ID sama. Pertahankan seluruh nilai terakhir termasuk null, isi waktu pembatalan, dan bebaskan slot Draft. |
| E5 | GET pada prefix | 200 dengan array Final milik UMKM, tanpa paginasi, terurut `finalized_at DESC, id DESC`. Hasil kosong berupa `[]`. |
| E6 | GET `/latest` | 200 dengan objek yang sama persis dengan elemen pertama E5, atau `null` jika histori kosong. |

Route detail, edit dan hapus evaluasi per ID berada di luar kontrak. Pertahankan route evaluasi lama `/api/umkms/{id}/evaluations*` dan perilaku data V1.

### Input dan hasil

Enam kunci dalam urutan publik adalah `sumber_daya`, `kepemimpinan`, `lingkungan_kerja`, `nilai_organisasi`, `stabilitas`, dan `kinerja`. E2 menerima objek seperti berikut.

```json
{"scores":{"sumber_daya":4,"kepemimpinan":null}}
```

Contoh itu menyimpan Sumber Daya bernilai 4 dan mengosongkan Kepemimpinan. Empat faktor lain mempertahankan nilai sebelumnya. Body tanpa isi, `{}`, dan `{"scores":{}}` sah untuk E2. Jika hadir, `scores` wajib berupa objek JSON dengan nilai integer 1–5 atau null. Tolak array, faktor asing, string numerik, string kosong, boolean, pecahan, nilai di luar rentang, serta field tambahan atau milik server, termasuk saat nilainya null. E3/E4 hanya menerima body tanpa isi atau objek kosong. JSON rusak menghasilkan 422 pada ketiga operasi tulis sebelum data berubah.

Resource publik memuat tepat `id`, `umkm_id`, `status`, `scores` berisi keenam kunci, `total_score`, `category`, `finalized_at`, `cancelled_at`, `created_at`, dan `updated_at`. Tanggal memakai ISO-8601 UTC dengan mikrodetik. Total dan kategori hanya terisi pada Final. Masing-masing waktu terminal hanya terisi pada status terkait. Kolom internal `active_draft_umkm_id` tidak diekspos.

| Total Final (inklusif) | Kategori dari P0 |
|---|---|
| 6–12 | Kurang Sehat |
| 13–18 | Cukup Sehat |
| 19–24 | Sehat |
| 25–30 | Sangat Sehat |

### Akses, konflik, dan pesan publik

Urutan pemeriksaan adalah format ID pada route, autentikasi, kepemilikan UMKM, validasi payload, pencarian evaluasi di UMKM tersebut, status Draft, lalu kelengkapan untuk Final. Kepemilikan diperiksa sebelum payload agar request invalid ke UMKM asing tetap mendapat respons yang sama dengan UMKM yang tidak ditemukan.

| HTTP | Kondisi dan respons |
|---|---|
| 401 | Token absen atau invalid. Pesan “Silakan masuk untuk melanjutkan.” |
| 404 | ID nonnumerik tidak cocok dengan route. Untuk UMKM asing/absen atau evaluasi yang tidak ditemukan pada UMKM terkait, gunakan “Data tidak ditemukan.” |
| 409 | E3/E4 pada Final memberi “Evaluasi sudah Final dan tidak dapat diubah.” Pada Cancelled gunakan “Evaluasi sudah dibatalkan dan tidak dapat diubah.” |
| 409 | DELETE UMKM milik pengguna yang memiliki Draft, Final, atau Cancelled memberi “UMKM tidak dapat dihapus karena memiliki riwayat penilaian enam faktor.” Tanpa riwayat enam faktor, pertahankan sukses 200 dan perilaku legacy. |
| 422 | Gunakan “Data yang diberikan tidak valid.” beserta objek `errors`. Nilai faktor memakai `scores.<kunci>`, bentuk scores salah memakai `scores`, JSON rusak memakai `body`. Finalisasi parsial memakai `scores` dan menyebut kunci faktor yang belum terisi. |
| 500 | Gunakan “Terjadi kesalahan pada server. Silakan coba lagi.” Respons publik hanya memuat pesan aman, tanpa SQL atau stack trace. |

## 3. Kriteria penerimaan dan bukti handoff P1

| AC | Kondisi lulus pada kandidat pasca-reset |
|---|---|
| 01 · Routing | Keenam route menggunakan Sanctum, method dan batas ID sesuai kontrak. Route V1 lama tetap tersedia. |
| 02–04 · Draft | E1 kosong tidak menulis. E2 create/resume, PUT/PATCH parsial, field absen, null eksplisit dan objek kosong terbukti lewat HTTP. Uji payload salah saat create dan resume. Snapshot database tetap identik setelah 422. |
| 05–08 · Transisi | Uji total 6, 12, 13, 18, 19, 24, 25 dan 30. Final parsial tetap Draft. Cancel mempertahankan nilai. Final dan Cancelled immutable. Menyimpan sesudah transisi menghasilkan 201 dengan ID baru. Aksi terminal memakai ID lama tidak menyentuh Draft baru. |
| 09 · Histori | Buktikan isolasi per UMKM, filter Final, kondisi kosong, urutan jika timestamp sama, dan kesamaan E6 dengan E5[0]. Periksa integritas fixture evaluasi legacy. |
| 10–11 · Akses | Uji tanpa token, UMKM asing/absen, evaluasi salah induk, serta payload invalid ke UMKM asing. Pastikan kode publik konsisten dan tidak ada mutasi. |
| 12 · Konkurensi | Gunakan koneksi independen pada MySQL. Save paralel dari keadaan kosong menghasilkan tepat satu 201, sisanya 200 dengan ID sama. Dua Final atau Final lawan Cancel pada ID sama menghasilkan satu 200 dan satu 409. |
| 13 · DELETE UMKM | Uji seluruh status serta jalur tanpa riwayat. Saat DELETE bersaing dengan E2, save lebih dulu menghasilkan 201 dan DELETE 409. Jika DELETE menang, hasilnya 200 dan E2 mendapat 404. Tidak ada 500 atau baris yatim. |
| 14–15 · Kontrak publik | Verifikasi bentuk resource, tipe JSON asli, waktu UTC, pesan aman, dan error per field. Serahkan kontrak E1–E6 serta contoh request, hasil kosong, Final, dan error yang cocok dengan respons aktual untuk P2. |
| 16–17 · Regresi dan review | Jalankan tes P1, regresi P0, dan suite terdampak pada kandidat yang sama. Catat hasil aktual, bandingkan lima kasus baseline, perbaiki regresi baru, dan tautkan bukti tiap AC ke identitas kandidat termasuk file untracked. |

**Batas penyerahan.** Ruang implementasi mencakup routing Laravel, validasi, otorisasi, resource, integrasi layanan P0, pemetaan error DELETE, serta tes. Handoff mencantumkan verdict P1, identitas kandidat, hasil tes dan batas verifikasinya. UI P2, perubahan skema, perbaikan seeder lama, commit, push, merge dan deployment berada di luar pekerjaan ini.

## Referensi

- [Handoff P0 pasca-reset, 20 November 2026](https://chatgpt.com/library?file_id=libfile_c34214aaa8e881919aecb2003e63602f&account_id=personal)
- [Kontrak API P1 untuk integrasi frontend](https://chatgpt.com/library?file_id=libfile_00eaac6a7ea88191bc4b92ec12409d18&account_id=personal)
- [Handoff review P1 sebelum reset](https://chatgpt.com/library?file_id=libfile_39aef993af0481919d68eca98834f18d&account_id=personal)
