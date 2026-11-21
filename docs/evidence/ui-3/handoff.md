# JoyUMKM UI-3 — Refinement Visual Halaman

UI-3 memperjelas isi sebelas halaman lokal di atas fondasi UI-0, UI-1, dan UI-2. Perubahan ada pada layout, label, status, dan cara menampilkan data. Endpoint, kepemilikan, perhitungan, serta lifecycle sesi dan penilaian tetap mengikuti implementasi yang ada.

## Rute, state, dan perubahan

[Snapshot 34 rute aplikasi](routes.json)

| Rute | Perubahan | State yang ditinjau |
| --- | --- | --- |
| `/` | Jarak hero mobile, pemenggalan judul, ukuran CTA dan caption. Ilustrasi serta urutan section dipertahankan. | Hero dan CTA pada keempat viewport |
| `/login`, `/register` | Desktop tetap split. Tablet dan ponsel memakai strip brand agar form lebih dekat ke atas. | Default, invalid, toggle, strength, 401, jaringan, fallback font |
| `/home` | Heading dan CTA ringkas, empat KPI padat, distribusi dan prioritas lebih mudah dibaca, CTA tersedia di ponsel. | KPI/diagram siap, pengambilan data independen, kosong dan retry |
| `/umkms` | Penyesuaian tipografi tabel, pagination, dialog, dan ringkasan di atas daftar UI-2. | Tabel, kartu, menu, nama panjang, tambah/ubah/hapus, state daftar |
| `/umkms/{id}` | Memisahkan pilihan penilaian dari informasi usaha dan status kuesioner terbaru. | Belum ada kuesioner, memiliki skor, breadcrumb dengan konteks daftar |
| `/form-data?umkm={id}` | Empty state satu kolom, progres aktual, status penyimpanan, panduan netral dan kontrol responsif. | Belum pilih, tiga dimensi, guard perubahan, pratinjau Draft, simpan Final |
| `/umkms/{id}/six-factor-evaluations` | Rel sepanjang skala, posisi belum dipilih tanpa thumb, radar dengan label singkat dan kunci lengkap. | Belum dipilih, nilai 1, autosave, kosongkan, Draft/Final/Batal, hasil dan histori |
| `/factor-profile?umkm_id={id}` | Ringkasan mendahului radar, skor keseluruhan dari objek UMKM terpilih, nilai kosong eksplisit. | Skor lengkap, belum tersedia, legenda, tooltip, tabel alternatif |
| `/distribution` | Jumlah dan persentase kategori, legenda lebih lapang dan grafik sektor lebih pendek saat seri sedikit. | Kategori lengkap, sektor tanpa skor, 503 dan retry |
| `/comparison?umkm_ids[]={id}` | Legenda membungkus nama, tabel dengan scroll lokal dan petunjuk, fallback jika semua seri kosong. | Tanpa pilihan, nama panjang, pilihan tunggal/jamak, grafik dan tabel |

## Pemetaan data nyata

| Tampilan | Sumber dan interpretasi |
| --- | --- |
| Total UMKM dan usaha dengan evaluasi | `GET /api/umkms/summary`. `total_umkms` mencakup seluruh UMKM. `total_respondents` menghitung UMKM yang memiliki evaluasi terakhir. `scored_count` menghitung evaluasi terakhir yang memiliki skor. Pada fixture visual, total 2, dengan evaluasi 1, dan berskor 1. |
| Skor keseluruhan di Profil Faktor | `GET /api/dashboard/factor-profile` menyuplai `selected_scores` dan `portfolio_averages`. Objek `umkm` di respons profil berisi `id` dan `name`. Skor keseluruhan dan sektor diambil dari objek pemilih pada `GET /api/umkms` atau `GET /api/umkms/{id}`, dengan ID dicocokkan terhadap respons profil. |
| Detail usaha | `GET /api/umkms/{id}` menyuplai metadata usaha, `evaluation_status`, `latest_score`, dan `category` untuk kuesioner. Hasil Enam Faktor tetap di halaman penilaian yang bersangkutan. |
| Progres kuesioner | `answered_count` menjadi nilai dan `total_questions` menjadi maksimum meter. Skor yang belum tersedia tidak diberi penyebut `/100`. |
| Distribusi | Jumlah berasal dari `categories[].count`, persentase dihitung terhadap `total_umkms`. Skor sektor kosong dikecualikan dari grafik rerata. |
| Radar Enam Faktor | Nilai tetap 1–5, total tetap hingga 30, dan skor berasal dari hasil Final. Singkatan SD, KP, LK, NO, ST, KI memiliki nama lengkap dan nilai pada daftar di bawah radar. |

## Bukti sebelum dan sesudah

Baseline diambil dari checkout UI-2 sebelum perubahan. Fixture sebelum dan sesudah memakai akun QA terpisah, nama sintetis dan data yang setara untuk state standar. ID, waktu finalisasi, dan data hasil interaksi dapat berbeda. Manifest JSON memuat nama state, viewport, rute, nama file, timestamp, overflow, dan metrik terkait.

- [Manifest sebelum](screenshots/before/manifest.json) · [Manifest sesudah](screenshots/after/manifest.json)
- Login 390 px di [baseline](screenshots/before/390-login.png) dan [UI-3](screenshots/after/390-login.png).
- Dashboard 1440 px di [baseline](screenshots/before/1440-home.png) dan [UI-3](screenshots/after/1440-home.png).
- Detail UMKM 1440 px di [baseline](screenshots/before/1440-detail-scored.png) dan [UI-3](screenshots/after/1440-detail-scored.png).
- Kuesioner tanpa pilihan di [baseline](screenshots/before/1440-questionnaire-empty.png) dan [UI-3](screenshots/after/1440-questionnaire-empty.png).
- Slider dan radar di [baseline](screenshots/before/1440-six-factor.png) dan [UI-3](screenshots/after/1440-six-factor.png).
- Ringkasan Profil Faktor di [baseline](screenshots/before/1440-factor-profile.png) dan [UI-3](screenshots/after/1440-factor-profile.png).

## File UI-3

- `resources/css/{public,dashboard}.css` untuk aturan lokal halaman.
- `resources/views/{home,login,register,form-data,umkm-detail,six-factor-evaluation,factor-profile,distribution,comparison}.blade.php` dan `resources/views/components/{summary,footer}.blade.php` untuk hierarki dan teks.
- `resources/js/{summary,charts}.js` dan `resources/js/pages/{umkm-detail,form-data,six-factor-evaluation,factor-profile,distribution,comparison}.js` untuk presentasi data dan state.
- `tests/js/ui-page-refinement-browser.mjs` untuk bukti dan interaksi UI-3. Fixture Final Enam Faktor dipertahankan di database testing karena API melindunginya dari penghapusan.
- `tests/js/workspace-browser.mjs` menunggu perubahan fragmen URL sebelum memeriksa skip link.

## Menjalankan verifikasi

1. Verifikasi konfigurasi server Laravel QA memakai MySQL `joyumkm_testing`. Server berjalan di loopback port 8129, dengan `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=joyumkm_testing`, dan `DB_URL` kosong.
2. Jalankan `npm run build`, `npm test`, `php artisan view:cache --no-interaction`, `php artisan route:list --except-vendor --no-interaction`, dan `git diff --check`.
3. Set `JOYUMKM_BROWSER_DB=joyumkm_testing` dan `JOYUMKM_PLAYWRIGHT` ke path modul Playwright Core. Jalankan `node tests/js/ui-page-refinement-browser.mjs` dengan `JOYUMKM_UI3_EVIDENCE=docs/evidence/ui-3/screenshots`. Mode `--before` dipakai pada baseline UI-2. Menjalankan mode ini setelah perubahan akan mengukur checkout sesudah UI-3.
4. Jalankan `workspace-browser.mjs`, `ui-foundation-browser.mjs`, `ui-navigation-browser.mjs`, dan `six-factor-browser.mjs` dari `tests/js`. Runner fondasi memakai `JOYUMKM_UI1_ORIGIN=http://127.0.0.1:8129`.
5. Jalankan PHPUnit setelah browser selesai. Uji terarah memakai filter `(AuthApiTest|OwnershipApiTest|UmkmApiTest|UmkmSummaryApiTest|EvaluationApiTest|DashboardApiTest|SixFactorEvaluation)`. Simpan laporan suite lengkap dengan `--log-junit` untuk pembandingan identitas dan status tiap kasus.

Firefox, Safari, pembaca layar, autofill dari password manager, dan perangkat fisik berada di luar matriks yang dijalankan. Build lokal masih mencatat peringatan chunk ApexCharts sekitar 938 kB sebelum gzip.

## Regresi PHP sebelum dan sesudah

Kedua run suite lengkap menghasilkan 197 dari 202 tes lulus dan 2.030 assertion. Pembandingan dua JUnit sebelum dan sesudah UI-3 menemukan nol perubahan pada 202 identitas kasus, status, dan pesan awal kegagalan. Suite terarah lulus 164 tes dengan 1.816 assertion.

| Kasus baseline | Sebelum dan sesudah |
| --- | --- |
| `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores` | FAIL, 4 akun ditemukan dibanding 1 yang diharapkan |
| `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` | FAIL, 4 akun ditemukan dibanding 1 yang diharapkan |
| `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` | FAIL, 4 evaluasi dibanding batas 3 untuk profil small |
| `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` | ERROR, argumen metode tes kurang |
| `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` | ERROR, pesan assertion dipakai sebagai nama koneksi |
