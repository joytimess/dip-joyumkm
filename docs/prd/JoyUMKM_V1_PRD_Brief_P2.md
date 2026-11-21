# PRD JoyUMKM V1 P2 — Penilaian Enam Faktor per UMKM

20 November 2026 · Brief teknis implementasi web · Fondasi P0 dan API P1 berstatus PASS menurut handoff.

## 1. Tujuan dan batas produk

Dari halaman detail UMKM, pemilik dapat membuka Penilaian Enam Faktor, mengisi sebagian nilai, kembali untuk melanjutkan Draft, lalu memfinalisasi atau membatalkannya. Setelah Final, pengguna dapat membaca hasil terbaru dan membuka hasil terdahulu beserta radar masing-masing. Satu UMKM memiliki paling banyak satu Draft aktif dan dapat memiliki banyak Final.

Integrasikan fitur pada navigasi, autentikasi, dan tampilan JoyUMKM V1 yang ada. Evaluasi legacy dengan skala tersendiri tetap dapat digunakan. Distribusi global, perbandingan antar-UMKM, perubahan algoritma, migrasi, pagination backend, dan deployment berada di luar cakupan P2.

## 2. Titik awal implementasi

Handoff P1 terbaru mencatat basis `main@804738b0a39f9dc2658e40e92e796077d131aca6` dan SHA-256 manifest kandidat `6b29523e414d95a3076269f32a2bc9412339ca5c54dea57e65c2e9d38fa6a250`. Kontrak operasional ada di `docs/API.md`, bagian Penilaian Enam Faktor P1.

| Bukti dari P1 | Hasil |
|---|---|
| HTTP P1 dan konkurensi HTTP/MySQL | 63/63 dan 6/6 lulus |
| Regresi P0 dan gabungan tes fokus P0–P1 | 35/35 dan 104/104 lulus |
| JavaScript | 26/26 lulus |
| Suite PHP penuh | 197/202 lulus. Tiga kegagalan dan dua error seeder cocok dengan baseline P0 menurut nama dan jenis. |
| Lingkungan | PHP 8.3.36, Laravel 13.40.0, Sanctum 4.3.3, MySQL 8.4.3. Database tulis untuk tes adalah `joyumkm_testing`. |

Saat mulai mengimplementasikan, cocokkan working tree dengan handoff dan identifikasi halaman detail UMKM, route web, klien API, alur sesi, komponen dialog, pustaka grafik, serta harness browser di checkout aktual. Catat path yang benar dalam handoff implementasi. Gunakan kembali pola yang tersedia dan pertahankan perubahan P0/P1, termasuk file untracked. Bukti P1 mencakup backend lokal. Bukti browser P2 dikumpulkan pada kandidat P2.

## 3. Layar dan data yang dilihat pengguna

Halaman dibuka dari detail UMKM dan menampilkan nama UMKM serta navigasi kembali. Form Draft, panel **Final terbaru**, dan **Histori Final** memiliki judul dan status muat masing-masing. Bila Draft dan Final terbaru tersedia sekaligus, pengguna dapat melanjutkan Draft sambil melihat hasil Final sebelumnya.

| Urutan form dan sumbu radar | Label | Kunci API |
|---|---|---|
| 1 | Sumber Daya | `sumber_daya` |
| 2 | Kepemimpinan | `kepemimpinan` |
| 3 | Lingkungan Kerja | `lingkungan_kerja` |
| 4 | Nilai Organisasi | `nilai_organisasi` |
| 5 | Stabilitas | `stabilitas` |
| 6 | Kinerja | `kinerja` |

**Slider wajib.** Masing-masing dari enam faktor memakai native HTML `<input type="range" min="1" max="5" step="1">`. Dropdown `<select>`, radio, dan kumpulan tombol angka dilarang sebagai pengganti slider. Tampilkan angka 1, 2, 3, 4, dan 5 di bawah tiap slider, serta nilai terpilih dalam bentuk `4 / 5`.

Pada kunjungan pertama, keenam skor bernilai `null` dan tertulis **Belum dipilih**. Thumb berada di posisi visual 1 sebagai titik awal. Posisi ini belum menjadi skor. Membuka halaman, memuat ulang, atau memberi fokus pada slider tidak mengirim E2. Memilih posisi awal 1 secara sengaja dengan mouse, sentuhan, atau keyboard mengisi skor 1.

Sediakan tombol **Kosongkan** dengan label aksesibel untuk masing-masing faktor. Aksi ini mengatur skor menjadi `null` dan mengembalikan tampilan ke **Belum dipilih**. Baca Draft aktif saat halaman dibuka atau dimuat ulang. Pulihkan setiap skor tersimpan beserta status belum dipilih untuk skor `null`. Buat Draft saat pengguna pertama kali memilih nilai. Tampilkan kegagalan pemuatan beserta aksi coba lagi agar pengguna dapat membedakannya dari keadaan kosong.

## 4. Kontrak frontend–API

Prefix seluruh endpoint adalah `/api/umkms/{id}/six-factor-evaluations`. Gunakan ID numerik, token Sanctum dari alur autentikasi V1, `Accept: application/json`, dan `Content-Type: application/json` saat mengirim JSON. Respons sukses membungkus hasil dalam `data`.

| Operasi | Metode dan suffix | Perilaku UI |
|---|---|---|
| E1 · Baca Draft | GET `/draft` | 200 objek atau null. Pulihkan enam nilai dan ID Draft. |
| E2 · Autosave | PATCH atau PUT `/draft` | Partial merge. 201 membuat Draft, 200 memperbarui Draft aktif. Simpan ID dari kedua respons. |
| E3 · Finalisasi | POST `/{evaluationId}/finalize` | Body `{}`. 200 mengembalikan Final dengan ID yang sama. |
| E4 · Batalkan | POST `/{evaluationId}/cancel` | Body `{}`. 200 mengembalikan Cancelled dengan skor tersimpan saat pembatalan. |
| E5 · Histori | GET pada prefix | 200 array Final per UMKM, terbaru dahulu menurut `finalized_at DESC, id DESC`. Array kosong sah. |
| E6 · Terbaru | GET `/latest` | 200 objek atau null. Ketika ada histori, hasil sama dengan elemen pertama E5. |

Contoh payload E2 untuk mengubah dua faktor dalam satu simpan:

```json
{"scores":{"sumber_daya":4,"kepemimpinan":null}}
```

Empat kunci lain mempertahankan nilainya. Ambil nilai slider sebagai angka melalui `valueAsNumber` dan kirim integer 1–5 atau `null` saat faktor dikosongkan. Kirim hanya faktor yang berubah. ID, status, total, kategori, dan timestamp dimiliki server. Gunakan timestamp UTC dari respons dan format sesuai kebiasaan waktu aplikasi. Tampilkan nilai waktu yang kosong secara aman.

## 5. Autosave, Final, dan Batal

1. **Setelah input.** Tandai faktor yang berubah dan naikkan revisi lokal. Target debounce awal 700 ms sejak input terakhir. Tampilkan “Menunggu penyimpanan”, lalu “Menyimpan…”.
2. **Selama request.** Jalankan satu E2 pada satu waktu per sesi halaman dan UMKM. Rekam nilai dan revisi yang dikirim. Jika ada edit baru selama request, antrikan nilai terakhir tiap faktor. Saat respons tiba, bersihkan hanya revisi yang diakui. Kirim sisa perubahan, termasuk pengosongan eksplisit. Tampilkan “Tersimpan” setelah revisi terbaru diakui.
3. **Saat gagal.** Pertahankan input yang masih ada di halaman, tandai “Gagal menyimpan”, dan sediakan “Coba lagi”. Peringatkan saat pengguna meninggalkan halaman dengan perubahan tertunda sejauh didukung browser. Setelah reload, pulihkan data yang telah diterima server.
4. **Finalisasi.** Aktifkan aksi setelah keenam skor di Draft valid dan tersimpan, ID Draft tersedia, dan antrean kosong. Nilai visual slider yang belum dipilih tetap `null`. Dialog menjelaskan bahwa hasil Final permanen. Saat dikonfirmasi, kunci form dan aksi ganda, hentikan timer, periksa kembali Draft aktif dan skor tersimpan, lalu kirim E3 ke ID tersebut. Bila hasil pemeriksaan berubah, tampilkan data aktual agar pengguna dapat meninjaunya kembali.
5. **Batalkan Draft.** Tawarkan aksi untuk Draft aktif. Dialog menjelaskan bahwa Draft yang dibatalkan tidak dapat dilanjutkan. Saat dikonfirmasi, kunci input, hentikan debounce dan antrean yang belum dikirim, tunggu E2 yang sudah dikirim, lalu cocokkan ID aktif sebelum E4. Menutup dialog sebelum konfirmasi mengembalikan pengguna ke form yang sedang diisi.
6. **Setelah transisi.** Final dan Cancelled bersifat permanen. Bersihkan timer, antrean, dan ID lokal milik Draft lama. Muat ulang E1/E5/E6. Hanya input baru yang disengaja dapat memulai Draft berikutnya. E2 terlambat setelah aksi terminal dapat membuat Draft baru di server.
7. **Konflik atau timeout.** Jika E3/E4 timeout, hentikan write dan baca ulang E1/E5/E6 sebelum menawarkan percobaan ulang. Cocokkan ID target dengan Draft aktif dan histori. Jika status persisnya belum dapat dibuktikan, beri tahu pengguna bahwa hasil aksi belum dapat dipastikan. Untuk dua tab, baca ulang state saat kembali fokus atau sebelum aksi terminal. P1 menyediakan partial merge tanpa versi kondisional. Serialisasi autosave berlaku dalam satu sesi halaman.

## 6. Hasil, histori, dan aksesibilitas

Panel terbaru mengambil enam skor, total, kategori, dan waktu Final dari E6. Referensi kategori server adalah 6–12 **Kurang Sehat**, 13–18 **Cukup Sehat**, 19–24 **Sehat**, dan 25–30 **Sangat Sehat**. Browser menampilkan nilai resmi yang dikirim server.

Histori memakai E5 tanpa parameter pagination. Detail berasal dari objek baris terpilih. Saat pengguna membuka hasil lama, label dan tanggal mengidentifikasi Final tersebut, sedangkan panel terbaru tetap merujuk E6. Radar memakai tepat enam sumbu dalam urutan tabel, dengan skala konsisten 1–5. Tampilkan juga keenam angka sebagai teks atau tabel. Untuk histori kosong, tampilkan “Belum ada penilaian Final”.

Gunakan layout bertumpuk pada layar sempit. Slider harus mudah digunakan dengan mouse, sentuhan, dan keyboard, memiliki indikator fokus yang jelas, dan mengumumkan **Belum dipilih** kepada pembaca layar ketika skornya `null`. Tombol **Kosongkan** dapat dioperasikan dengan keyboard dan sentuhan. Kembalikan fokus ke pemicu saat dialog ditutup. Umumkan status simpan ketika status berubah. Sajikan loading, empty, dan error secara terpisah untuk tiap panel.

## 7. Penanganan kegagalan

| Kondisi | Respons yang diharapkan |
|---|---|
| 401 | Ikuti alur sesi berakhir V1 dan hentikan autosave sesi tersebut. |
| 404 | Tampilkan “Data tidak ditemukan.” untuk ID hilang, salah induk, atau di luar kepemilikan akun. |
| 409 | Jelaskan bahwa status Draft telah berubah, hentikan aksi, lalu sinkronkan E1/E5/E6. |
| 422 | Petakan `errors.scores.<kunci>` ke label faktor. Untuk Final parsial, baca `errors.scores` dan tunjukkan faktor yang perlu dilengkapi. Sajikan `errors.body` sebagai pesan umum. |
| 500, timeout, offline | Beri pesan singkat tanpa SQL atau stack trace. Pertahankan input selama sesi masih terbuka. Bedakan gagal simpan dari hasil transisi yang belum pasti. |

## 8. Kriteria penerimaan dan bukti

| ID | Skenario dan syarat lulus |
|---|---|
| AC-P2-01 | Dari detail UMKM, buka halaman penilaian untuk UMKM yang benar, lalu kembali melalui navigasi V1. Verifikasi di browser. |
| AC-P2-02 | Verifikasi ada tepat enam native range slider sesuai urutan dengan `min="1" max="5" step="1"`, tanpa dropdown, radio, atau tombol angka. Angka 1–5 terlihat di bawah slider dan skor terpilih tampil sebagai `n / 5`. Pilih tiap integer 1–5 secara tepat, termasuk 1 dari posisi awal. |
| AC-P2-03 | Geser slider dengan cepat saat respons E2 ditahan, ubah faktor yang sama dan faktor lain. Pastikan hanya satu request aktif, payload berisi integer atau `null` untuk faktor yang berubah, nilai terakhir tersimpan, dan status “Tersimpan” mengikuti pengakuan 201/200. Simulasikan gagal lalu retry. |
| AC-P2-04 | Form awal menampilkan enam status **Belum dipilih**. Posisi visual 1 dan fokus tanpa pemilihan tidak membuat Draft. Simpan Draft parsial, reload, kosongkan satu nilai melalui tombol **Kosongkan**, dan periksa E2 mengirim `null`. Reload lagi untuk memeriksa status belum dipilih dan nilai faktor lain. |
| AC-P2-05 | Uji Final saat skor keenam belum dipilih meski thumb sudah tampak di posisi 1, saat debounce, saat save berjalan, dan setelah sukses. E3 hanya terkirim dengan enam skor tersimpan dan body kosong JSON. Periksa total 6, 12, 13, 18, 19, 24, 25, 30 serta kategori dari server. |
| AC-P2-06 | Uji batal dialog dan konfirmasi pembatalan saat ada save berjalan. E4 menargetkan ID yang benar. Sesudah E3/E4 sukses, tunggu melewati debounce dan pastikan E1 tetap null hingga pengguna memulai input baru. |
| AC-P2-07 | Buat dua Final per UMKM. Periksa tie-break ID pada timestamp sama, E6 sama dengan E5[0], serta hasil kosong. Cancelled dan Draft tidak muncul di histori. |
| AC-P2-08 | Buka Final historis. Radar dan enam angka sesuai objek terpilih, panel terbaru tetap benar, dan hasil legacy memakai tampilan serta skalanya sendiri. |
| AC-P2-09 | Simulasikan seluruh kondisi pada tabel kegagalan, termasuk 422 Final parsial, dua tab dengan ID usang, dan timeout setelah server memproses aksi. Pastikan pesan publik dan rekonsiliasi sesuai. |
| AC-P2-10 | Uji desktop dan viewport ponsel tanpa overflow horizontal. Pilih nilai dengan geser mouse, sentuhan, dan keyboard. Pastikan fokus slider terlihat, tombol **Kosongkan** aksesibel, fokus dialog terkelola, dan angka radar tersedia sebagai teks. |
| AC-P2-11 | Uji sesi kedaluwarsa, dua akun, dan evaluasi salah induk. Verifikasi alur evaluasi legacy dan kepemilikan data pada API aktual. |
| AC-P2-12 | Jalankan E2E browser, integrasi API/MySQL dan suite regresi terdampak. Bandingkan lima kasus baseline berdasarkan identitas dan jenis kegagalan. Catat setiap kegagalan baru. |
| AC-P2-13 | Periksa diff terhadap batas P2. Fitur individual memakai E1–E6 dan skema yang tersedia. |
| AC-P2-14 | Kaitkan hasil QA, perbaikan, retest, serta review akhir ke manifest kandidat yang sama. Handoff memuat perintah uji, hasil, keterbatasan, dan bukti per AC. |

**Definisi selesai.** P2 mendapat status PASS setelah seluruh kriteria terbukti pada kandidat yang direview, tanpa defect P2 terbuka. Handoff membedakan hasil uji browser nyata, simulasi jaringan, dan uji backend. Status PASS yang tercatat pada awal PRD ini berlaku untuk fondasi P0/P1.

## Referensi keputusan

- [Pra-PRD P2 — keputusan produk dan skenario penerimaan](https://chatgpt.com/library?file_id=libfile_8cf2b4ebc2708191a6f45cfbab4452d9&account_id=personal)
- [PRD P1 — kontrak teknis E1–E6](https://chatgpt.com/library?file_id=libfile_a119ed6d2c7c819193733d7b5ee7d75c&account_id=personal)
- [Handoff P1 terbaru — kandidat, pengujian, dan baseline](https://chatgpt.com/library?file_id=libfile_59d50e3d793481919966af7d1487f90d&account_id=personal)
