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

Sender memakai HTTP `POST` berisi snapshot JSON versi 1 dengan `Content-Type: application/json`. Body memakai byte yang dibaca dari penyimpanan snapshot yang sama pada setiap attempt. Envelope memuat `event_id`, `event_type`, `event_version`, `source`, `occurred_at` UTC, dan `data` berisi ID evaluasi/UMKM, status, enam skor, total, serta kategori.

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
