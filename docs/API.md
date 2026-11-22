# JoyUMKM — Kontrak API V1

Base path `/api`. Semua endpoint mengembalikan JSON, termasuk error tanpa Accept. Kirim `Accept: application/json`, `Content-Type: application/json` untuk body JSON, dan `Authorization: Bearer <token>` kecuali register/login. Data UMKM/evaluasi/ringkasan/dashboard dibatasi pada akun token. ID asing dan hilang sama-sama `404 {"message":"Data tidak ditemukan."}`.

## Response dan status

Sukses tunggal: `{"data":{...}}`. Koleksi berhalaman memuat `data`, `links` (first/last/prev/next), dan `meta` (current_page, from, last_page, links, path, per_page, to, total). Latest belum tersedia: `{"data":null}`. ID, jumlah, omzet, jawaban berupa number/integer; skor number atau null. Null bukan nol. Timestamp string UTC ISO 8601; submitted_at draft null.

| HTTP | Arti |
|---|---|
| 200 | Baca, update, logout, delete sukses |
| 201 | Registrasi, UMKM, evaluasi baru |
| 400 | Request tidak valid |
| 401 | Token tidak ada/invalid/expired atau kredensial salah |
| 404 | Record tidak ada atau bukan milik akun |
| 409 | Penilaian enam faktor sudah selesai, atau UMKM memiliki riwayat penilaian enam faktor |
| 422 | Validasi gagal; message dan errors per field |
| 429 | Rate limit; hormati Retry-After |
| 500 | Pesan generik; detail hanya di log server |

Contoh bentuk validasi (pesan menyesuaikan field):

```json
{"message":"Data yang diberikan tidak valid.","errors":{"name":["Nama wajib diisi."]}}
```

Field bertingkat pada errors adalah key literal, misalnya `answers.0.value`. Final belum lengkap juga menyediakan `errors.missing_question_ids` berupa daftar ID numerik dan pesan `errors.answers`.

## Daftar endpoint lengkap

| Metode | Path setelah `/api` | Input | Response sukses |
|---|---|---|---|
| POST | `/auth/register` | Body registrasi | 201 AuthResource |
| POST | `/auth/login` | Body login | 200 AuthResource |
| GET | `/auth/me` | Tanpa input | 200 UserResource |
| POST | `/auth/logout` | Tanpa body | 200 MessageResource |
| GET | `/umkms` | Query list | 200 UmkmCollection |
| POST | `/umkms` | Body UMKM | 201 UmkmResource |
| GET | `/umkms/options` | Tanpa input | 200 UmkmOptionsResource |
| GET | `/umkms/summary` | Tanpa input | 200 UmkmSummaryResource |
| GET | `/umkms/{id}` | ID path numerik | 200 UmkmResource |
| PUT, PATCH | `/umkms/{id}` | ID path dan body UMKM | 200 UmkmResource |
| POST | `/umkms/{id}/whatsapp-check` | ID path dan `whatsapp_number` yang harus sama dengan nomor tersimpan | 200 UmkmResource dengan hasil OpenWA untuk nomor tersebut; 409 bila nomor sudah berubah |
| DELETE | `/umkms/{id}` | ID path | 200 MessageResource |
| GET | `/questionnaire` | Tanpa input | 200 QuestionnaireResource |
| GET | `/umkms/{id}/evaluations/latest` | ID path | 200 EvaluationResource atau data null |
| POST | `/umkms/{id}/evaluations` | ID path dan body evaluasi | 201 EvaluationResource |
| GET | `/umkms/{id}/six-factor-evaluations/draft` | ID path | 200 Draft atau `data: null` |
| PUT, PATCH | `/umkms/{id}/six-factor-evaluations/draft` | ID path dan body Draft opsional | 201 Draft baru atau 200 Draft yang diperbarui |
| POST | `/umkms/{id}/six-factor-evaluations/{evaluationId}/finalize` | Dua ID path dan body kosong | 200 Final |
| POST | `/umkms/{id}/six-factor-evaluations/{evaluationId}/cancel` | Dua ID path dan body kosong | 200 Cancelled |
| GET | `/umkms/{id}/six-factor-evaluations` | ID path | 200 array Final tanpa paginasi |
| GET | `/umkms/{id}/six-factor-evaluations/latest` | ID path | 200 Final terbaru atau `data: null` |
| GET | `/dashboard/factor-profile` | Query umkm_id | 200 FactorProfileResource |
| GET | `/dashboard/distribution` | Tanpa input | 200 DistributionResource |
| GET | `/dashboard/three-dimension-health` | Query status, page, per_page opsional | 200 koleksi item + `summary` |
| GET | `/dashboard/three-dimension-detail` | Query umkm_id wajib | 200 item detail |
| GET | `/dashboard/comparison` | Query umkm_ids opsional | 200 ComparisonResource |

GET dibaca dari query string, bukan body JSON. Owner ditetapkan dari token; user_id pada mutasi UMKM ditolak. ID internal path berasal dari route dan tidak dapat ditimpa body.

## Autentikasi

Register: name string wajib maksimal 120; email wajib valid/unik maksimal 255; password wajib minimal 8 karakter, maksimal 72 byte dan tanpa NUL; password_confirmation harus sama. Name/email di-trim, email lowercase. Login: email/password wajib; email dinormalisasi; batas password 72 byte/NUL tetap berlaku. Kredensial salah memberikan pesan generik 401.

AuthResource data: `token`, `token_type:"Bearer"`, `expires_at`, `user:{id,name,email}`. Me: `data:{id,name,email}`. Logout: `data.message`, mencabut token saat ini saja. Register/login menerbitkan token baru; tidak ada single-device login atau refresh token. Baseline expiry tujuh hari dari SANCTUM_TOKEN_EXPIRATION_DAYS, minimal satu hari.

Register dibatasi lima request/menit/IP; login lima request/menit/IP+email. Klien mempertahankan token pada 404/422/429/500/network error. Protected 401 membersihkan sesi dan mengarah ke login.

## UMKM

### Body tambah/update

| Field | Aturan |
|---|---|
| name | String di-trim, wajib, maksimal 120; unik case-insensitive per akun |
| province | String canonical dari GET /umkms/options |
| sector_id | Integer ID sektor valid dari options |
| omzet | Integer rupiah 0–9007199254740991; tanpa pemisah ribuan dan tanpa periode |

POST/PUT membutuhkan keempat field. PATCH hanya memvalidasi field yang hadir tetapi wajib memuat setidaknya satu field UMKM yang dapat diubah. Nama sama boleh digunakan akun berbeda. Update/delete ID asing diperiksa kepemilikannya lebih dahulu dan menghasilkan 404. Untuk UMKM tanpa riwayat penilaian enam faktor, DELETE menghapus UMKM dan evaluasi serta jawaban V1 melalui FK cascade. Jika ada penilaian enam faktor, DELETE menghasilkan 409 dan data tetap tersimpan.

UmkmResource data: `id`, `name`, `province`, `sector:{id,name}`, `omzet`, `latest_score`, `category`, `recommendation`, `evaluation_status`, `evaluation_id`. Recommendation adalah alias kategori, bukan saran naratif. Tanpa evaluasi: skor/evaluation_id null, status none, kategori BELUM DINILAI. Draft kosong tetap draft dengan skor null.

### Query list

| Query | Aturan/default |
|---|---|
| search | Substring literal nama, maksimal 120 karakter; wildcard LIKE di-escape |
| sector_id | Integer sektor valid |
| province | Canonical dari options |
| category | Label kategori termasuk BELUM DINILAI |
| status | none, draft, final; none berarti belum pernah dievaluasi |
| sort | score (default), name, omzet |
| direction | desc (default), asc |
| page | Integer positif, default 1 |
| per_page | Integer 1–100, default 10 |

Filter digabung AND. Skor null selalu paling akhir untuk asc/desc; tie-breaker ID asc. UI kembali ke page 1 setelah filter/search/sort berubah. Meta kosong from/to null; jangan menampilkan range 1–0. Pemilih memakai list beserta pagination/search dan detail endpoint untuk selected ID di luar halaman aktif.

Options data: `sectors:[{id,name}]`, `provinces:[string]`, `categories:[{minimum,label,color}]`. Minimum BELUM DINILAI null. Master options bersama tetapi tetap memerlukan autentikasi.

### Summary

Summary tidak mengikuti filter tabel. Semua agregasi memakai satu evaluasi terbaru (ID terbesar) per UMKM, termasuk draft; tidak fallback ke final lama.

Data: total_umkms (seluruh UMKM), total_respondents (punya evaluasi termasuk draft kosong), scored_count (latest skor non-null), draft_count (latest draft), average_score (mean skor non-null dibulatkan satu desimal), category, critical_sector.

Critical sector null bila tidak ada skor; selain itu `{id,name,average_score,total_count,scored_count,draft_count,needs_intervention}`. Mean sektor dibulatkan satu desimal sebelum label intervensi (<70). Sektor terendah dipilih menurut mean dibulatkan, nama, lalu ID asc. Nol masuk mean; null dikeluarkan. Update sektor memindahkan kontribusi latest ke sektor baru.

## Questionnaire dan evaluasi

Questionnaire data: factors dan scoring. Faktor: `id`, `code`, `name`, `description`, `color`, `scale_min`, `scale_max`, `sort_order`, `questions`. Pertanyaan aktif: `id`, `sort_order`, `text`, `input_type` (labeled_radio/likert), `options:[{value,label}]`. Scoring: factor_precision, score_precision, rounding, categories (minimum/label/color), unrated (label/color). Bank awal 6 INS, 4 ECT, 6 OPS. Klien membaca ID dan metadata aktual, tidak mengasumsikan ID 1–16 atau menyamaratakan opsi sumber modal.

Body simpan: `{"status":"draft|final","answers":[{"question_id":integer,"value":integer}]}`. Status wajib; answers wajib berupa list berurutan, boleh kosong untuk draft. Question ID harus aktif, unik, dikenal, dan value ada pada options. Final wajib menjawab seluruh soal aktif tepat sekali. Field skor factor_scores/ins_score/ect_score/ops_score/average_score/category ditolak. ID UMKM asing dengan payload salah tetap 404 sebelum validasi bisnis terkait record.

Evaluasi, jawaban, dan hitungan disimpan dalam satu transaksi. Tiap POST sukses membuat riwayat baru; tidak mengubah evaluasi lama. Submitted_at hanya terisi untuk final. Latest ID terbesar berlaku tanpa membedakan status. Answers latest dapat memuat jawaban historis; UI prefill hanya soal aktif sekarang. Answered_count menghitung jawaban soal aktif, total_questions jumlah aktif saat ini.

EvaluationResource data: `id`, `umkm_id`, `status`, `factor_scores:{INS,ECT,OPS}`, `average_score`, `category`, `answers:[{question_id,value}]`, `answered_count`, `total_questions`, `created_at`, `submitted_at`.

Rumus: mean jawaban faktor → `(mean-min)/(max-min)*100` → mean faktor non-null pada presisi penuh → half-up satu desimal. Faktor disimpan empat desimal. Kategori dari skor akhir dibulatkan: ≥85 SANGAT BAIK, ≥70 BAIK, ≥55 CUKUP, ≥40 KURANG, <40 KRITIS, null BELUM DINILAI. Semua minimum=0, maksimum=100, midpoint=50. Draft parsial hanya menghitung faktor tersedia dan berlabel Sementara pada UI; draft kosong seluruh skor null. Nol valid.

Tidak ada backend idempotency key V1. Klien menonaktifkan submit saat berjalan, tidak retry POST otomatis saat timeout, dan membaca latest untuk rekonsiliasi. Dua POST sukses yang disengaja menghasilkan dua riwayat. Reset form hanya lokal sampai disimpan.

## Penilaian enam faktor P1

Gunakan prefix `/api/umkms/{id}/six-factor-evaluations`, ID numerik, dan token Sanctum pemilik UMKM. Kirim `Accept: application/json` serta `Content-Type: application/json` saat mengirim JSON.

| ID | Metode dan suffix | Status dan isi `data` |
|---|---|---|
| E1 | GET `/draft` | 200 Draft aktif atau `null`. Pembacaan tidak membuat Draft. |
| E2 | PUT/PATCH `/draft` | 201 ketika membuat Draft. 200 ketika memperbarui Draft yang sama. Kedua metode menyimpan faktor yang dikirim. |
| E3 | POST `/{evaluationId}/finalize` | 200 Final dengan ID Draft semula. Keenam skor harus tersimpan sebelum aksi ini. |
| E4 | POST `/{evaluationId}/cancel` | 200 Cancelled dengan ID Draft semula. Seluruh skor terakhir termasuk `null` dipertahankan. |
| E5 | GET pada prefix | 200 array Final milik UMKM, urut `finalized_at DESC, id DESC`. Tanpa hasil, `{"data":[]}`. |
| E6 | GET `/latest` | 200 objek yang sama dengan elemen pertama E5 untuk histori yang sama. Tanpa hasil, `{"data":null}`. |

E2 menerima body tanpa isi, `{}`, atau objek `scores`. Skor yang disertakan harus berupa integer JSON 1–5 atau `null`. Skor yang absen mempertahankan nilai sebelumnya dan `null` mengosongkan skor. Contoh berikut menyimpan Sumber Daya dan mengosongkan Kepemimpinan.

```http
PATCH /api/umkms/12/six-factor-evaluations/draft
Accept: application/json
Content-Type: application/json
Authorization: Bearer <token>

{"scores":{"sumber_daya":4,"kepemimpinan":null}}
```

E3 dan E4 menerima body tanpa isi atau `{}`. Setelah salah satu aksi sukses, penyimpanan berikutnya membuat Draft baru dengan ID baru. Aksi terminal berikutnya pada ID lama menghasilkan 409. E1 juga memakai `{"data":null}` saat slot Draft kosong.

Contoh Final dengan total 24. ID mengikuti data UMKM yang dipilih.

```json
{
  "data": {
    "id": 34,
    "umkm_id": 12,
    "status": "final",
    "scores": {
      "sumber_daya": 4,
      "kepemimpinan": 4,
      "lingkungan_kerja": 4,
      "nilai_organisasi": 4,
      "stabilitas": 4,
      "kinerja": 4
    },
    "total_score": 24,
    "category": "Sehat",
    "finalized_at": "2026-11-20T08:00:00.000000Z",
    "cancelled_at": null,
    "created_at": "2026-11-20T08:00:00.000000Z",
    "updated_at": "2026-11-20T08:00:00.000000Z"
  }
}
```

`scores` selalu memuat keenam kunci. Status terdiri dari `draft`, `final`, dan `cancelled`. `total_score` dan `category` terisi pada Final. `finalized_at` terisi pada Final dan `cancelled_at` pada Cancelled. Tanggal yang terisi memakai UTC ISO 8601 dengan enam digit mikrodetik. Jika `created_at` atau `updated_at` absen pada baris P0, API membaca kolom tersebut sebagai `null`. Kategori server adalah Kurang Sehat (6–12), Cukup Sehat (13–18), Sehat (19–24), dan Sangat Sehat (25–30).

| HTTP | Pesan publik |
|---|---|
| 401 | `Silakan masuk untuk melanjutkan.` |
| 404 | `Data tidak ditemukan.` untuk format ID yang tidak cocok, UMKM asing atau hilang, dan evaluasi di luar UMKM terkait. |
| 409 pada Final | `Evaluasi sudah Final dan tidak dapat diubah.` |
| 409 pada Cancelled | `Evaluasi sudah dibatalkan dan tidak dapat diubah.` |
| 409 pada DELETE UMKM | `UMKM tidak dapat dihapus karena memiliki riwayat penilaian enam faktor.` Berlaku untuk ketiga status. |
| 422 | `Data yang diberikan tidak valid.` beserta objek `errors`. |
| 500 | `Terjadi kesalahan pada server. Silakan coba lagi.` |

Kunci bertingkat pada `errors` bersifat literal. Contoh berikut berlaku ketika skor Kinerja dikirim sebagai string.

```json
{
  "message": "Data yang diberikan tidak valid.",
  "errors": {"scores.kinerja": ["Nilai faktor harus berupa bilangan bulat 1 sampai 5 atau null."]}
}
```

Body JSON rusak memakai kunci `body`. Bentuk `scores` yang salah memakai kunci `scores`. Finalisasi parsial juga memakai `scores` dan pesannya mencantumkan kunci faktor yang belum diisi. Field di luar kontrak ditolak meskipun nilainya `null`.

## Dashboard

Semua memakai latest per UMKM dengan scope akun seperti summary. Array faktor selalu INS, ECT, OPS. Baseline mempertahankan presisi agregat skor faktor tersimpan; klien menampilkan satu desimal. Denominator berbeda per faktor karena null dikeluarkan.

### Factor Profile

`GET /dashboard/factor-profile?umkm_id=<id>` wajib ID integer positif. Data: `umkm:{id,name}`, `factors:["INS","ECT","OPS"]`, `selected_scores:[number|null]`, `portfolio_averages:[number|null]`, `sample_count_per_factor:[integer]`, `evaluation_status`, `answered_count`, `total_questions`.

Baseline mencakup semua UMKM akun termasuk pilihan. Tanpa evaluasi, selected_scores null dan status none. Radar tidak digambar jika ada faktor pilihan null; UI menampilkan nilai tersedia, pesan Data faktor belum lengkap, CTA kuesioner. ID asing/hilang 404. UI otomatis memilih UMKM pertama berdasarkan daftar nama asc bila URL tidak menentukan pilihan.

### Distribution

`GET /dashboard/distribution` → `data:{total_umkms,categories,sectors}`. Categories seluruh level termasuk jumlah nol: `[{label,color,count}]`. Jumlah count sama dengan total_umkms, termasuk tanpa evaluasi dan draft kosong dalam BELUM DINILAI. Sectors: `[{id,name,average_score,total_count,scored_count,draft_count}]`. Sektor tanpa UMKM tidak disertakan. Sektor dengan UMKM tanpa skor memiliki average_score null, bukan nol. Semua belum dinilai: donut tetap valid, bar sektor empty. Mean sektor dibulatkan satu desimal.

### Comparison

Tanpa parameter, `GET /dashboard/comparison` memilih maksimal lima UMKM berskor tertinggi, tie-breaker ID asc. Pilihan eksplisit: `?umkm_ids[]=12&umkm_ids[]=34`. ID wajib positif, unik, maksimal lima, seluruhnya milik akun. Urutan response mengikuti pilihan. Satu ID asing/hilang menggagalkan seluruh request dengan 404 tanpa response parsial.

Pilihan kosong eksplisit pada HTTP query: `?umkm_ids=`. FormRequest menormalisasikannya ke list kosong; umkms/selected_ids kosong, tidak otomatis memilih default. Jangan memakai `?umkm_ids[]=` (list berisi ID kosong, gagal 422). Body JSON GET diabaikan.

Data: factors, selected_ids, umkms, portfolio_averages, sample_count_per_factor. Item umkms: `{id,name,scores:[number|null],sample_count_per_factor:[0|1],evaluation_status,answered_count,total_questions}`. Count tingkat atas adalah seluruh sampel portofolio per faktor; baseline seluruh portofolio, bukan hanya pilihan. Grafik memakai column series per UMKM dan satu line series mean faktor, skala 0–100. Null bukan bar nol; label Belum dijawab. Pilihan kosong menampilkan empty state.

### Three Dimension Health (read-only)

`GET /dashboard/three-dimension-health?status=all|final|draft|none&page=&per_page=` (per_page maksimal 50, default 10). Response: `data[]` (paginated) dan `summary` yang selalu dihitung dari seluruh cakupan akun, tidak dipengaruhi filter status. `summary`: `total_umkms`, `with_final`, `draft_only`, `not_assessed`, `overall_final_average`, `dimension_final_averages{INS,ECT,OPS}`. Rerata hanya memakai satu Final terbaru per UMKM; tanpa Final bernilai null.

Setiap item: `umkm_id`, `name`, `state` (`final|draft|none`), `has_active_draft`, `latest_final` (null bila belum Final: `evaluation_id`, `overall_score`, `category`, `answered_count`, `question_count`, `dimensions`, `finalized_at`, `is_complete`), dan `draft` (hanya Draft yang lebih baru dari Final; `evaluation_id`, `answered_count`, `question_count`, `updated_at`). Skor Tiga Dimensi 0–100 dan tidak disetarakan dengan Enam Faktor.

`GET /dashboard/three-dimension-detail?umkm_id=<id>` mengembalikan satu item dengan bentuk yang sama. ID asing dan ID hilang menghasilkan 404 yang identik. Endpoint ini tidak mengubah data.

## Contoh permintaan

Alur lengkap register → logout token register → login → tambah UMKM → questionnaire dinamis → final → summary/grafik → logout ada di [README](../README.md#contoh-api-nyata-dengan-curl). Contoh Bash berikut memakai BASE, TOKEN, UMKM_ID dari alur itu **sebelum logout**:

```bash
curl -fsS --get "$BASE/umkms" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" --data-urlencode 'search=Usaha' --data-urlencode 'status=final' --data-urlencode 'sort=score' --data-urlencode 'direction=desc' --data-urlencode 'per_page=10'
curl -fsS "$BASE/umkms/$UMKM_ID/evaluations/latest" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
printf '%s' '{"status":"draft","answers":[]}' | curl -fsS "$BASE/umkms/$UMKM_ID/evaluations" -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" --data-binary @-
curl -fsS "$BASE/dashboard/comparison?umkm_ids=" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -fsS --get "$BASE/dashboard/comparison" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" --data-urlencode "umkm_ids[]=$UMKM_ID"
printf '%s' '{"omzet":20000000}' | curl -fsS "$BASE/umkms/$UMKM_ID" -X PATCH -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" --data-binary @-
curl -fsS "$BASE/umkms/$UMKM_ID" -X DELETE -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

Draft V1 kosong membuat latest baru dan mengubah ringkasan serta grafik. DELETE UMKM menghapus evaluasi V1 saat UMKM tidak memiliki riwayat penilaian enam faktor. Gunakan record uji milik Anda. Status pemeriksaan aktual tersedia di [IMPLEMENTATION.md](IMPLEMENTATION.md).
