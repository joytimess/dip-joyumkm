# PRD P4-03A.1 — JoyUMKM Business Profile & Webhook Enrichment

**Status:** Approved scope for implementation  
**Tanggal:** 22 November 2026  
**Target:** Laravel API + Vue UI JoyUMKM; Hermes receiver tidak diubah pada fase ini.

## 1. Latar belakang

Webhook `evaluation.finalized` V2 sudah lulus pengiriman end-to-end dan memuat `six_factor` serta `three_dimension`, tetapi belum menyertakan identitas dan konteks bisnis UMKM. Form Tambah UMKM belum memiliki modal usaha. Fase ini melengkapi profil UMKM, menampilkannya di UI, dan memasukkan snapshot profil ke webhook tanpa mengubah instrumen penilaian.

## 2. Tujuan dan batasan

- Tambahkan field **Modal Usaha (Rp)** pada **modal Tambah UMKM**, modal/form **Edit UMKM**, dan tampilkan label **Modal Usaha** di **Detail UMKM**.
- Lengkapi profil dengan **Tahun Mulai Usaha**, **Jumlah Tenaga Kerja**, dan **Periode Omzet (Bulanan/Tahunan)** bila belum tersedia. Pertahankan nama, sektor/jenis usaha, alamat/lokasi, dan nominal omzet yang sudah ada.
- Tambahkan `business_context` pada payload `evaluation.finalized` agar Hermes menerima konteks usaha bersama dua instrumen penilaian.
- Jangan mengubah skor, kategori, logika finalisasi, atau mekanisme delivery yang sudah PASS.
- **Di luar scope:** implementasi skill intelligence Hermes, modifikasi konfigurasi/subscription Hermes, perombakan UI, serta perhitungan laba/profitabilitas.

## 3. Discovery wajib sebelum implementasi

Audit migration, model, FormRequest, controller, API Resource, komponen Vue Tambah/Edit/Detail UMKM, serta `app/Services/HermesWebhookOutbox.php`. Petakan nama field aktual dan tipe data, termasuk bagaimana omzet sekarang disimpan (bulanan, tahunan, atau pilihan). **Jangan menduplikasi kolom yang sudah ada atau mengasumsikan struktur repo.** Pertahankan seluruh field lama dan pola validasi/otorisasi yang sudah berjalan. Catat mapping final dalam handoff.

## 4. Spesifikasi data

| Atribut | Ketentuan | Data lama |
|---|---|---|
| Nama UMKM | Pakai field existing | Tetap |
| Sektor/jenis usaha | Pakai field existing; tidak membuat duplikat | Tetap |
| Alamat/lokasi | Pakai field existing; kirim hanya yang relevan dan tidak sensitif | Tetap |
| **Modal usaha** | **Field baru bila belum ada; rupiah, bilangan bulat nonnegatif; tidak termasuk tanah/bangunan tempat usaha** | Nullable |
| Omzet | Pakai nominal existing | Tetap |
| **Periode omzet** | **`monthly` atau `annual`; sesuai nominal yang diinput** | Nullable bila tidak dapat ditentukan secara andal |
| **Tahun mulai usaha** | Integer tahun yang masuk akal, tidak melebihi tahun berjalan | Nullable |
| **Jumlah tenaga kerja** | Integer nonnegatif | Nullable |

**Aturan penting:** angka 0 adalah nilai sah jika diisi, berbeda dari `null` (belum diisi). Jangan mengarang omzet tahunan dari omzet bulanan sebagai realisasi; bila perlu estimasi di masa depan, gunakan field/label terpisah. Jangan mengubah kategori UMKM secara otomatis pada fase ini. Nilai modal usaha wajib diisi pada **create baru** melalui UI/API setelah rilis; data historis tetap nullable dan edit data lama tidak boleh terblokir semata-mata karena modal belum diisi, kecuali pengguna memang memperbarui field tersebut. Terapkan aturan create/update yang konsisten dan dokumentasikan.

## 5. UI — Modal Tambah UMKM (WAJIB)

**Acceptance UI utama:** Pada modal **Tambah UMKM** yang sudah ada, tampilkan field berikut dengan label persis:

1. **Modal Usaha (Rp)** — input numerik berformat rupiah; helper: `Tidak termasuk tanah dan bangunan tempat usaha`; required untuk UMKM baru; validasi angka nonnegatif, pesan error bahasa Indonesia.
2. **Omzet (Rp)** — pertahankan field nominal existing; jangan buat duplikat.
3. **Periode Omzet** — pilihan **Bulanan** / **Tahunan** jika belum ada; disimpan sebagai nilai enum terstruktur dan dikirim sesuai periode aktual.
4. **Tahun Mulai Usaha** — input tahun; opsional.
5. **Jumlah Tenaga Kerja** — input bilangan bulat; opsional.

Pertahankan field identitas existing (nama UMKM, sektor, alamat, dll). Susun menjadi kelompok **Identitas Usaha** dan **Profil Ekonomi & Operasional** tanpa merombak desain modal. Modal harus tetap dapat digunakan pada desktop dan mobile, dengan scrolling internal bila tinggi melebihi viewport, footer aksi tetap dapat diakses, fokus/label aksesibel, dan error per-field. **Jangan menghilangkan field lama.**

## 6. UI — Edit UMKM (WAJIB)

Form/modal **Edit UMKM** harus menampilkan field dan label yang sama, memuat nilai existing, menyimpan perubahan, dan memperlihatkan validasi yang konsisten. Nilai historis yang belum tersedia ditampilkan kosong/`Belum diisi`, tidak diisi otomatis dengan 0. Tidak boleh mereset field lain ketika hanya satu field diedit.

## 7. UI — Detail UMKM (WAJIB)

Tambahkan bagian atau kelompok label **Profil Ekonomi & Operasional** pada **Detail UMKM**. Tampilkan label:

- **Modal Usaha** — `Rp...` atau `Belum diisi`.
- **Omzet Bulanan** atau **Omzet Tahunan** sesuai periode aktual; bila periode tidak diketahui, tampilkan **Omzet** dengan keterangan `Periode belum diketahui`.
- **Tahun Mulai Usaha** — tahun atau `Belum diisi`.
- **Jumlah Tenaga Kerja** — `N orang` atau `Belum diisi`.
- **Sektor/Jenis Usaha** dan **Lokasi Usaha** — gunakan label/field existing; tidak perlu duplikasi jika sudah tampil di bagian identitas.

Gunakan format rupiah Indonesia dan tata letak konsisten dengan desain JoyUMKM. **Label baru harus terlihat pada halaman detail, bukan hanya tersimpan di API.** Tidak perlu menambah label **Skala UMKM** jika belum ada aturan klasifikasi terimplementasi dan disetujui.

## 8. Laravel API, database, dan kompatibilitas

- Buat migration **hanya untuk kolom yang belum ada**, nullable pada DB untuk menjaga data historis.
- Tambahkan casting, validasi create/update, mapping service/controller, dan API Resource agar nilai baru tersimpan dan dapat dibaca kembali.
- Pastikan permission/authorization existing, pagination, listing, filter, dan operasi arsip tetap berjalan.
- Jangan menyimpan nilai mata uang sebagai float; gunakan integer rupiah (atau representasi presisi yang sudah baku dalam proyek).
- Pertahankan kontrak API existing; penambahan field harus additive. Uji create, update parsial, detail, null, 0, invalid, dan UMKM historis.

## 9. Kontrak webhook `evaluation.finalized`

Tambahkan `data.business_context` secara **additive** ke V2 jika receiver existing menerima field ekstra; bila ternyata receiver strict, siapkan penyesuaian kontrak/versi secara eksplisit dan laporkan sebelum breaking change. Jangan mengubah `data.six_factor`, `data.three_dimension`, `event_id`, timestamp, signature, maupun aturan ACK/deduplikasi.

Contoh ilustratif (bukan data UMKM nyata):

```json
{
  "event_type": "evaluation.finalized",
  "event_version": 2,
  "event_id": "<uuid>",
  "occurred_at": "<ISO-8601>",
  "source": "joyumkm",
  "data": {
    "umkm_id": 5,
    "business_context": {
      "name": "Contoh UMKM",
      "sector": "Kuliner",
      "location": "Jakarta",
      "started_year": 2021,
      "employee_count": 5,
      "business_capital": 50000000,
      "revenue": {
        "amount": 15000000,
        "period": "monthly"
      }
    },
    "six_factor": { "...": "struktur V2 existing, tidak diubah" },
    "three_dimension": { "...": "struktur V2 existing, tidak diubah" }
  }
}
```

- Snapshot `business_context` dibuat dalam transaksi finalisasi yang sama dengan snapshot evaluasi/outbox, tanpa HTTP call atau pekerjaan intelligence di dalam transaksi.
- Snapshot bersifat immutable setelah outbox dibuat; perubahan profil kemudian tidak mengubah payload event lama.
- Untuk data tidak tersedia, gunakan `null` secara eksplisit, bukan nilai karangan. Hindari data pribadi sensitif atau detail kontak pemilik yang tidak dibutuhkan.
- Dokumentasikan bentuk payload dan kompatibilitas receiver V1/V2 di `docs/HERMES_WEBHOOK.md` atau dokumen existing yang relevan.

## 10. Pengujian dan acceptance criteria

| ID | Kriteria penerimaan |
|---|---|
| AC-01 | Audit field existing terdokumentasi; tidak ada kolom/field duplikat. |
| AC-02 | **Modal Tambah UMKM** menampilkan **Modal Usaha (Rp)** beserta helper, validasi, dan penyimpanan benar. |
| AC-03 | Modal Tambah UMKM menampilkan periode omzet, tahun mulai, jumlah tenaga kerja sesuai hasil audit. |
| AC-04 | **Edit UMKM** mendukung semua field baru tanpa menghapus data lama. |
| AC-05 | **Detail UMKM** menampilkan **label baru** dan nilai yang tepat, termasuk `Belum diisi` untuk null. |
| AC-06 | API create/update/detail dan data historis lolos tes MySQL; validasi angka dan periode tepat. |
| AC-07 | Webhook `evaluation.finalized` memuat `business_context` dan dua instrumen existing utuh. |
| AC-08 | Snapshot immutable, outbox idempotent, signature dan ACK tidak berubah; regression PASS. |
| AC-09 | Dokumentasi payload, mapping field, dan bukti tes diperbarui. |
| AC-10 | Live E2E event baru membuktikan Hermes menerima `business_context`; jika lingkungan live tidak tersedia, tandai NOT RUN, jangan klaim PASS. |

Pengujian minimum: PHPUnit feature/integration pada **MySQL** (termasuk outbox dan regression), Vue component/UI checks, build frontend, dan inspeksi visual modal Tambah, Edit, Detail pada desktop/mobile. Jangan menggunakan hasil test yang tidak dijalankan sebagai bukti PASS. Hindari full SQLite suite jika tidak diperlukan; utamakan suite terarah MySQL.

## 11. Urutan implementasi

1. Audit schema dan UI existing.
2. Migration/model/validation/resource API.
3. Modal **Tambah UMKM** dan **Edit UMKM**.
4. **Detail UMKM** dengan label baru.
5. Snapshot `business_context` di Laravel webhook.
6. Test, build, dokumentasi, live E2E jika memungkinkan.

## 12. Handoff wajib

Laporkan daftar file berubah, mapping field lama/baru, keputusan kompatibilitas payload V2, hasil tes dan build yang benar-benar dijalankan, screenshot/hasil inspeksi UI modal Tambah + Edit + Detail, contoh payload redacted, serta status tiap AC (PASS/PARTIAL/NOT RUN/BLOCKED). Jangan mengklaim P4-03B intelligence sudah diimplementasikan.
