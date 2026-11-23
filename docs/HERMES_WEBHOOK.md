# Operasi pengirim webhook Hermes P4-01

Setiap transisi resmi Penilaian Enam Faktor dari Draft ke Final menyimpan satu `evaluation.finalized` di `webhook_outbox` dalam transaksi yang sama. Event tetap tercatat saat pengiriman dinonaktifkan, worker berhenti, atau antrean gagal. Penilaian yang sudah Final sebelum migration tidak di-backfill. Outbox menyimpan snapshot hasil minimal tanpa data kontak. Riwayat event dipertahankan secara independen dari baris evaluasi.

## Konfigurasi dan proses

Jalankan migration sebelum aplikasi menerima request finalisasi dengan kode baru. Biarkan `HERMES_WEBHOOK_ENABLED=false` sampai URL, kontrak receiver, dan secret disetujui untuk lingkungan tersebut.

| Variabel | Nilai dan batas |
| --- | --- |
| `HERMES_WEBHOOK_ENABLED` | Default `false`. Set `true` untuk mulai mengirim backlog dan event baru. |
| `HERMES_WEBHOOK_URL` | URL HTTPS lengkap, tanpa kredensial dalam URL, query, atau fragment. TLS memverifikasi sertifikat dan redirect ditolak. |
| `HERMES_WEBHOOK_ALLOWED_HOST` | Nama host DNS yang persis sama dengan host URL. Gunakan host dengan DNS dan akses keluar yang dikelola operator. |
| `HERMES_WEBHOOK_SECRET` | Secret bersama receiver. Simpan di environment atau secret manager. |
| `HERMES_WEBHOOK_TIMEOUT_SECONDS` | Default 5 detik, dibatasi 1–15 detik. Connect timeout maksimum 3 detik. |
| `HERMES_WEBHOOK_MAX_ATTEMPTS` | Default 5, dibatasi 1–10 per siklus pengiriman. |
| `HERMES_WEBHOOK_BACKOFF_BASE_SECONDS` | Default 30 detik. |
| `HERMES_WEBHOOK_BACKOFF_CAP_SECONDS` | Default 900 detik, dibatasi 1–3600 detik. |

Job selalu memakai koneksi antrean Laravel `database` dan antrean `hermes-webhooks`. Koneksi tersebut harus menunjuk ke koneksi database Laravel yang sama dengan outbox. Biarkan `DB_QUEUE_CONNECTION` kosong atau isi dengan nama koneksi database aplikasi. Pastikan migration tabel `jobs` sudah tersedia dan `DB_QUEUE_RETRY_AFTER` lebih besar dari timeout worker 20 detik. Default-nya 90 detik.

Di Laragon/Windows, jalankan worker dan scheduler dari root proyek di dua terminal.

```powershell
php artisan queue:work database --queue=hermes-webhooks --tries=1 --timeout=20
php artisan schedule:work
```

Pada deployment, jalankan worker tersebut di bawah process supervisor. Jadwalkan `php artisan schedule:run` setiap menit dari root aplikasi, misalnya via cron.

```cron
* * * * * cd /path/to/joyumkm && php artisan schedule:run
```

Di Windows tanpa terminal permanen, gunakan Task Scheduler untuk menjalankan `schedule:run` setiap menit dengan working directory proyek. `php artisan schedule:list` menampilkan `hermes:webhook-scan`. Jalankan scanner manual untuk mengecek dispatch saat pemulihan. Jalankan ulang worker setelah perubahan kode atau konfigurasi. Jika memakai config cache, buat ulang cache sebelum restart worker.

## Status dan pemulihan

```sh
php artisan hermes:webhook-status
php artisan hermes:webhook-status <event_uuid>
php artisan hermes:webhook-scan
php artisan hermes:webhook-requeue <event_uuid>
```

- `pending` menunggu job. `retry_wait` siap dipindai setelah `next_attempt_at`. `processing` memakai lease 45 detik dan dapat dipindai kembali setelah lease habis. Scanner menghindari job kedua selama job yang tercatat masih menunggu atau memiliki reservasi aktif.
- `delivered` berarti receiver mengakui penerimaan HTTP. Terjadinya analisis AI ditentukan di sisi receiver.
- Timeout, gangguan koneksi, 408, 429, dan 5xx dijadwalkan ulang dengan backoff eksponensial, jitter hingga 20%, dan cap. 429 dan 503 mendukung `Retry-After` dalam detik atau HTTP-date hingga cap. Status lain di luar 2xx masuk `dead`, termasuk redirect dan penolakan 400/401/403/404/422.
- Untuk `dead`, cek kode/keterangan terakhir dan perbaiki penyebabnya sebelum menjalankan requeue. Perintah hanya bekerja pada `dead`, mengulang siklus attempt pada UUID dan payload yang sama, serta mempertahankan hasil Final. Gunakan scanner jika antrean sedang gagal. Jadwal retry dikelola outbox sehingga pemulihan dilakukan lewat scanner atau requeue outbox.
- Saat status menunjukkan `disabled`, `missing_config`, atau `invalid_destination`, event tetap tersimpan. Koreksi konfigurasi, restart worker, lalu jalankan scanner. Gunakan jumlah dan umur event tertua di `hermes:webhook-status` untuk memantau backlog. Log dispatch hanya mencatat ID internal dan alasan konfigurasi yang terklasifikasi. Status menampilkan metadata tanpa payload, signature, atau secret.
- Untuk rotasi secret, koordinasikan waktu perubahan dengan receiver. Nonaktifkan pengiriman, restart worker, tunggu request berjalan selesai, lalu perbarui secret bersama dan hidupkan kembali pengiriman dengan worker yang sudah direstart. Event yang tertahan menggunakan secret saat attempt berikutnya.

## Kontrak provisional untuk P4-02

Sender memakai HTTP `POST` berisi snapshot JSON versi 1 dengan `Content-Type: application/json`. Body memakai byte yang dibaca dari penyimpanan snapshot yang sama pada setiap attempt. Envelope memuat `event_id`, `event_type`, `event_version`, `source`, `occurred_at` UTC, dan `data`. Kontrak saat ini adalah **versi 2** (P4-03A), lihat bagian "Payload versi 2" di bawah.

| Header | Nilai |
| --- | --- |
| `X-Request-ID` | UUID event yang cocok dengan envelope dan tetap sama saat retry (delivery ID Hermes). |
| `X-Webhook-Timestamp` | Unix timestamp dalam detik pada setiap request. |
| `X-Webhook-Signature-V2` | Hex HMAC-SHA256 huruf kecil atas `timestamp + "." + rawBody` dengan secret bersama. |

Fixture menerima timestamp dalam selisih ±300 detik, memeriksa UUID dalam header terhadap envelope, dan membandingkan signature secara constant-time. Hermes mengakui admission, bukan selesainya analisis AI. Satu-satunya ACK sukses:

| HTTP | Body | Hasil |
| --- | --- | --- |
| `202` | `status=accepted`, `route` = segmen terakhir path `HERMES_WEBHOOK_URL`, `event=evaluation.finalized`, `delivery_id` = UUID event | `delivered` |
| `200` | `status=duplicate`, `delivery_id` = UUID event | `delivered` |

`200 ignored` menjadi `dead` dengan kode `ack_ignored`. `202 coalesced`, JSON rusak/kosong, field hilang atau salah, status asing, dan 2xx lain menjadi `dead` dengan kode `invalid_ack`. Keduanya tidak di-retry otomatis karena Hermes mungkin sudah menerima event. Periksa konfigurasi route Hermes, lalu gunakan `hermes:webhook-requeue <event_uuid>`. 408, 429, 5xx, dan error jaringan tetap retry dengan backoff. 3xx dan 4xx lain langsung `dead` (`http_rejected`). Jika request pertama telah diterima tetapi respons hilang, pengirim mengirim ulang event yang sama dan Hermes membalas `duplicate`.

Tes berikut menjalankan worker database MySQL dan receiver HTTP loopback sementara dengan secret fixture. HTTP ke `127.0.0.1` hanya diterima ketika `APP_ENV` adalah `local` atau `testing` dan host yang diizinkan adalah `127.0.0.1` (misalnya `http://127.0.0.1:8644/webhooks/joyumkm-events`). Environment lain tetap mewajibkan HTTPS dan FQDN. Gunakan database MySQL terisolasi `joyumkm_testing`. Tes feature ini mereset data di schema uji.

```powershell
php vendor/bin/phpunit tests/Feature/HermesWebhookOutboxTest.php tests/Feature/HermesWebhookWorkerTest.php tests/Unit/HermesWebhookSignatureTest.php
```

Untuk melihat fixture secara terpisah dari test runner, jalankan `php -S 127.0.0.1:8380 -t public tests/Fixtures/HermesWebhookReceiver.php`. Path fixture adalah `/joyumkm-events` dan secret uji didefinisikan dalam file fixture tersebut.

## Payload versi 2 (P4-03A, dua instrumen)

Event `evaluation.finalized` versi 2 membawa snapshot Enam Faktor (V2) dan snapshot Tiga Dimensi INS/ECT/OPS (V1) milik UMKM yang sama. Keduanya dibangun dan disimpan dalam transaksi finalisasi yang sama, tanpa request jaringan, sehingga perubahan V1 setelahnya tidak mengubah event yang sudah tersimpan. Transport, signature HMAC V2, ACK, retry, dan idempotensi `event_id` tidak berubah.

```json
{
  "event_id": "<uuid>",
  "event_type": "evaluation.finalized",
  "event_version": 2,
  "source": "joyumkm",
  "occurred_at": "2026-11-22T10:00:00Z",
  "data": {
    "umkm_id": 5,
    "business_context": {
      "name": "Contoh UMKM",
      "sector": "Kuliner",
      "location": "Jakarta",
      "started_year": 2021,
      "employee_count": 5,
      "business_capital": 50000000,
      "revenue": {"amount": 15000000, "period": "monthly"}
    },
    "six_factor": {
      "evaluation_id": 9,
      "status": "final",
      "total_score": 26,
      "category": "Sangat Sehat",
      "scores": {
        "sumber_daya": 3, "kepemimpinan": 4, "lingkungan_kerja": 5,
        "nilai_organisasi": 4, "stabilitas": 5, "kinerja": 5
      }
    },
    "three_dimension": {
      "evaluation_id": 31,
      "status": "final",
      "assessed_at": "2026-11-21T08:30:00Z",
      "average_score": 81.3,
      "category": "BAIK",
      "is_complete": true,
      "scores": {"ins": 71.5, "ect": 81.25, "ops": 91}
    }
  }
}
```

- **Lokasi field berubah dari versi 1.** `evaluation_id`, `status`, `scores`, `total_score`, dan `category` kini berada di `data.six_factor`. `data.umkm_id` tetap di root `data`. Konsumen dibedakan lewat `event_version`; versi 1 tidak di-backfill dan event versi 1 yang sudah tersimpan tidak diubah.
- **Skala.** `six_factor.scores` bilangan bulat 1–5 dan `total_score` jumlah enam faktor. `three_dimension.scores` dan `average_score` berskala 0–100 persis seperti tersimpan di V1 (presisi dimensi 4 desimal, rata-rata 1 desimal). Nilai tidak diskalakan ulang dan tidak digabung dengan V2. Kategori V1 memakai label V1 (`SANGAT BAIK`, `BAIK`, `CUKUP`, `KURANG`, `KRITIS`); kategori V2 memakai label V2.
- **Aturan seleksi V1.** Penilaian V1 berstatus `final` terbaru milik `umkm_id` yang sama dengan waktu penilaian (`submitted_at`, cadangan `created_at` untuk data lama) pada atau sebelum `finalized_at` V2; pemutus seri adalah `id` terbesar. Draft, penilaian setelah waktu finalisasi, dan penilaian UMKM lain tidak pernah dipilih. `assessed_at` memakai `submitted_at` (cadangan `created_at`).
- **Data tidak ada.** Tanpa penilaian V1 yang memenuhi syarat, `data.three_dimension` bernilai `null`, bukan objek skor nol. Dimensi yang tidak memiliki pertanyaan aktif tersimpan `null` di V1; snapshot meneruskannya sebagai `null` dan menandai `is_complete: false` sehingga tidak dianggap lengkap.
- **V1 tidak diubah.** Pembuatan snapshot hanya membaca `evaluations` (memakai indeks `umkm_id, id`) dan tidak menambah tabel atau migration.

### Konteks usaha `data.business_context` (P4-03A.1)

Snapshot profil usaha ditambahkan secara **additive** di `data` event versi 2, sejajar dengan `umkm_id`, `six_factor`, dan `three_dimension`. Struktur dua instrumen tidak diubah, dan `event_version` tetap `2`. Snapshot dibuat dalam transaksi finalisasi yang sama dengan outbox, lalu tersimpan di payload; perubahan profil setelah finalisasi tidak mengubah event yang sudah dibuat.

| Field payload | Sumber di `umkms` | Catatan |
|---|---|---|
| `name` | `name` | Nama UMKM. |
| `sector` | `sectors.name` melalui `sector_id` | Nama sektor, bukan ID. |
| `location` | `city` | Kabupaten/kota saja. Alamat lengkap, kecamatan, kelurahan, kode pos, dan koordinat tidak dikirim. |
| `started_year` | `year_founded` | Tahun mulai usaha. |
| `employee_count` | `employee_count` | Jumlah tenaga kerja. |
| `business_capital` | `business_capital` | Modal usaha dalam rupiah bilangan bulat, tidak termasuk tanah dan bangunan. |
| `revenue.amount` | `omzet` | Nominal omzet, bilangan bulat rupiah. |
| `revenue.period` | `omzet_period` | `monthly` → `"monthly"`; `yearly` → `"annual"`; kosong → `null`. |

- **Data belum diisi.** Nilai yang belum diisi dikirim `null`, bukan `0` atau string kosong. Angka `0` yang memang diisi tetap `0`.
- **Data pribadi tidak dikirim.** `owner_name`, `whatsapp_number`, `business_email`, `pic_name`, `address`, `latitude`, `longitude`, `nib`, `description`, `website_url`, dan `social_media_url` tidak masuk payload.
- **Kompatibilitas.** Event versi 2 yang sudah tersimpan sebelum rilis ini tidak memiliki `business_context`; konsumen harus memperlakukan field ini sebagai opsional. Receiver harus mengabaikan field `data` yang tidak dikenalnya.
- **Migrasi dan rollback P4-03A.1.** Satu migration menambah kolom nullable `umkms.business_capital` (`unsignedBigInteger`). Kolom profil lain (`omzet_period`, `year_founded`, `employee_count`) sudah ada sebelumnya. Rollback kode tidak menghapus kolom; event versi 2 yang sudah tersimpan tetap dikirim apa adanya.
- **Aturan input.** Modal usaha wajib saat UMKM dibuat, dan boleh kosong saat UMKM lama diubah (`null` = belum diisi). `omzet_period` wajib saat create dan boleh `null` saat update. `year_founded` tidak boleh melebihi tahun berjalan.

Migrasi dan rollback: tidak ada migration. Deploy kode baru langsung membuat event baru versi 2; event versi 1 yang masih `pending`/`retry_wait` tetap terkirim dengan payload aslinya. Receiver harus menerima `event_version` 1 dan 2 sebelum deploy. Rollback kode akan kembali membuat event versi 1 untuk finalisasi berikutnya, sedangkan event versi 2 yang sudah tersimpan tetap dikirim sebagaimana adanya. Pemrosesan intelijen dua instrumen di Hermes adalah cakupan P4-03B.
