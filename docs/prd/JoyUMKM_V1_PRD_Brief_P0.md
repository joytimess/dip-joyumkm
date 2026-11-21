# JoyUMKM V1 · PRD P0 — Foundation & Database Adjustment

**20 November 2026 · Brief implementasi · Satu fase**

## Tujuan dan titik mulai

Pemilik UMKM perlu bisa mengisi penilaian enam faktor secara bertahap, melanjutkannya di lain waktu, dan menyimpan hasil akhir sebagai histori. P0 menyediakan fondasi MySQL dan layanan domain Laravel untuk alur ini. Evaluasi V1 yang sudah ada tetap dapat digunakan dan datanya harus utuh.

Owner sudah menjalankan `git reset` dan `git clean`. Handoff sebelumnya mencatat PASS untuk kandidat dengan perubahan yang belum di-commit. Saat memulai implementasi, catat HEAD, periksa working tree, migration yang tercatat di database sasaran, dan keberadaan tabel aktual. Reset Git hanya memberi informasi tentang berkas. Jika tabel sudah ada, cocokkan struktur serta isinya sebelum menentukan perubahan. Pertahankan data yang ditemukan. Ukur baseline tes pada checkout sekarang.

## Perilaku yang harus tersedia

| Tindakan pemilik | Perilaku domain |
|---|---|
| Mulai atau lanjutkan penilaian | Satu UMKM dapat memiliki satu Draft aktif. Simpan pertama boleh kosong. Simpan berikutnya memakai ID Draft yang sama, memperbarui faktor yang dikirim, dan mempertahankan faktor lain. Nilai null mengosongkan faktor itu. |
| Finalisasi | Keenam faktor wajib lengkap. Server menjumlahkan skor, menentukan kategori dan mengisi waktu finalisasi pada baris Draft yang sama. Jika belum lengkap, Draft tetap dapat dilanjutkan. |
| Batalkan | Draft menjadi Cancelled dengan waktu pembatalan. Nilai yang sudah diisi tersimpan. Slot Draft aktif kembali tersedia. |
| Baca hasil | Histori memuat seluruh Final milik UMKM tersebut dengan hasil terbaru lebih dahulu. Pembacaan Final terakhir mengembalikan hasil yang sama dengan entri pertama histori, atau hasil kosong jika belum ada Final. |
| Mulai penilaian berikutnya | Setelah Final atau Cancelled, simpan berikutnya membuat Draft baru. Hasil terdahulu tetap utuh. |

## Kontrak data dan implementasi

Tambahkan satu migration untuk `six_factor_evaluations`. Setiap baris memiliki ID, `umkm_id`, status, enam nilai faktor, `total_score`, `category`, `finalized_at`, `cancelled_at`, serta timestamp standar. Gunakan FK ke `umkms.id` dengan `ON DELETE RESTRICT`. Banyak evaluasi boleh terkait ke satu UMKM. Status awal adalah `draft`.

Enam kolom faktor adalah `sumber_daya`, `kepemimpinan`, `lingkungan_kerja`, `nilai_organisasi`, `stabilitas`, dan `kinerja`. Simpan sebagai unsigned tinyint nullable. Saat menerima input, izinkan hanya integer 1–5 atau null. Tolak kunci asing dan kolom milik server, termasuk status, total, kategori dan pemilik. Gunakan pesan validasi berbahasa Indonesia.

| Status | Aturan penyimpanan |
|---|---|
| `draft` | Faktor boleh kosong atau parsial. Total, kategori, dan kedua waktu terminal bernilai null. |
| `final` | Semua faktor terisi. Total sama dengan jumlah enam faktor. Kategori sesuai pita total, waktu finalisasi terisi, dan waktu pembatalan null. |
| `cancelled` | Faktor terakhir dipertahankan. Waktu pembatalan terisi. Total, kategori, dan waktu finalisasi null. |

| Total inklusif | Kategori |
|---|---|
| 6–12 | Kurang Sehat |
| 13–18 | Cukup Sehat |
| 19–24 | Sehat |
| 25–30 | Sangat Sehat |

Tegakkan rentang skor dan konsistensi status dengan CHECK. Buat kolom generated STORED `active_draft_umkm_id` yang bernilai `umkm_id` untuk Draft dan null untuk status terminal. UNIQUE pada kolom tersebut mengamankan aturan satu Draft, termasuk saat ada dua penulis. Tambahkan indeks `(umkm_id, status, id)`. Rollback migration boleh menghapus tabel kosong dan wajib menolak saat sudah ada evaluasi.

Gunakan model `SixFactorEvaluation` dengan hanya enam faktor sebagai fillable. Model menolak update Final dan Cancelled serta penghapusan evaluasi pada semua status. Seluruh penulisan aplikasi melewati layanan domain. Guard model ini berlaku di Eloquent. FK melindungi histori dari penghapusan induk.

| Komponen | Tanggung jawab |
|---|---|
| `SixFactorEvaluationService` | `activeDraft`, `saveDraft`, `finalize`, `cancelDraft`, `finalHistory`, dan `latestFinal`. Verifikasi kepemilikan baca/tulis lewat `UmkmService::owned`. |
| Operasi tulis | Validasi input, lalu jalankan transaksi dengan urutan lock UMKM → Draft. Untuk benturan unique Draft saat insert, ulangi sekali dan baca ulang Draft. Propagasikan kegagalan yang menetap. |
| `Umkm` | Tambahkan relasi `sixFactorEvaluations()`, `activeSixFactorDraft()` dan `latestSixFactorFinal()`. |
| Factory dan tes | Sediakan state Draft, Final, Cancelled, serta tes fitur untuk kontrak di bawah. |

## Kriteria penerimaan P0

Gunakan MySQL terisolasi yang menegakkan CHECK untuk pembuktian skema dan konkurensi. Jalankan tes pada kandidat yang akan diserahkan. Simpan hasil aktual beserta identitas kandidat dalam handoff.

| ID | Bukti yang menentukan kelulusan |
|---|---|
| AC-01–03 | Migration hanya menambah tabel baru. Bandingkan struktur, jumlah dan fingerprint isi tabel bisnis V1 sebelum/sesudah pada data uji terisi. Rollback tabel baru yang kosong berhasil. Saat berisi, rollback ditolak dan baris tetap utuh. |
| AC-04–07 | Periksa DDL, FK, indeks, status dan keenam kolom. Satu UMKM dapat memiliki banyak evaluasi. Uji rentang keenam faktor di DB dan validasi tipe, null, kunci asing serta kolom server pada service. |
| AC-08–11 | DB menolak Draft kedua pada UMKM yang sama dan mengizinkan Draft pada UMKM lain. Dua penyimpanan melanjutkan ID yang sama. Uji Draft kosong, parsial dan pembaruan sebagian. Dengan koneksi MySQL independen, jalankan simpan bersamaan serta finalisasi bersamaan dengan simpan. Verifikasi maksimal satu Draft dan setiap hasil konsisten dengan urutan transaksi. |
| AC-12–15 | Uji total 6, 12, 13, 18, 19, 24, 25 dan 30. Final tidak lengkap ditolak tanpa mengubah baris. Total/kategori berasal dari server. Insert atau update SQL yang melanggar konsistensi status ditolak DB. |
| AC-16–19 | Model menolak perubahan hasil terminal serta penghapusan evaluasi. Histori dan latest hanya mengembalikan Final UMKM terkait. Cancel mempertahankan faktor dan membuka slot baru. Kegagalan di tengah transaksi mengembalikan keadaan awal. |
| AC-20–21 | Pemilik lain gagal membaca maupun menulis evaluasi. FK menolak referensi UMKM yang tidak ada dan penghapusan UMKM yang memiliki evaluasi. UMKM tanpa evaluasi tetap mengikuti aturan hapus V1. |
| AC-22–23 | Tinjau diff terhadap ruang lingkup P0, jalankan tes fitur, suite regresi dan pemeriksaan format proyek. Bandingkan kegagalan dengan baseline checkout yang sama dan selesaikan setiap regresi baru akibat P0. |

**Pembuktian AC-10.** Handoff lama menunjukkan INSERT dari koneksi kedua tertahan ketika koneksi pertama memegang lock induk. Uji kompetisi koneksi nyata untuk membuktikan invariant satu Draft. Uji cabang retry lewat simulasi unique violation sekali dan berulang. Catat kedua hasil secara terpisah. Koneksi kedua tidak perlu dipaksa commit di tengah lock untuk meluluskan AC ini.

## Batas fase dan penyerahan

P0 selesai pada migration, model, relasi, service, factory dan tes. Pertahankan migration dan alur evaluasi lama. Konversi skor lama, endpoint API, form web, autosave UI, grafik, dashboard, perubahan autentikasi, kebijakan arsip dan integrasi Hermes berada di luar fase ini. FK RESTRICT dapat memengaruhi alur hapus induk saat histori sudah ada. Catat dampaknya untuk fase yang membuka akses tulis melalui HTTP.

Saat brief dipakai untuk eksekusi, tuntaskan implementasi, QA independen, perbaikan dalam scope, dan uji ulang sebagai satu P0. Untuk alur Hermes, gunakan profil yang sudah disepakati: `prd-maker` untuk spesifikasi, `implementor` untuk kode, dan `testing-qa-regression` untuk QA. Serahkan verdict, pemetaan AC ke bukti, hasil baseline vs kandidat, serta risiko tersisa. Akhiri eksekusi di P0. Commit, push, merge, deploy dan pembukaan P1 memerlukan instruksi tersendiri.

**Acuan historis.** Handoff P0 tanggal 20 November mencatat 53 tes fitur lulus dengan 261 assertion dan lima kegagalan seeder yang juga muncul pada baseline lama. Angka ini hanya pembanding. Status checkout setelah reset ditentukan oleh verifikasi baru. [Handoff P0 V1](https://chatgpt.com/library?file_id=libfile_3ef6c22b212881918467059e4aaa5656&account_id=personal)
