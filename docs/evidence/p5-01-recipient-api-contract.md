# Kontrak Recipient Pull

## Permintaan

`GET /api/internal/intelligence/recipients/{eventId}` dengan UUID kanonis huruf kecil. Kirim tiga header berikut.

| Header | Nilai |
| --- | --- |
| `X-Intelligence-Signature-Version` | `1` |
| `X-Intelligence-Timestamp` | Detik epoch UTC desimal |
| `X-Intelligence-Signature` | HMAC-SHA256 lowercase hex, 64 karakter |

Tandatangani UTF-8 dengan LF aktual antarbaris. Baris terakhir berakhir pada digit timestamp.

```text
JMI-RECIPIENT-V1
GET
/api/internal/intelligence/recipients/11111111-1111-4111-8111-111111111111
1795536000
```

Golden vector sintetis. Secret `synthetic-recipient-key-0123456789abcdef` menghasilkan `9cd3824ddc3e7bfdbea8d340aba12a9073ee427cff9c3786bb5b1ffb165aba0c` untuk blok di atas. Timestamp harus berjarak paling jauh 300 detik dari jam Laravel, termasuk ke arah masa depan. Toleransi ini membatasi jendela replay. Request yang valid dapat digunakan kembali dalam jendela tersebut.

## Respons

Respons 200 eligible memuat `event_id`, `umkm_id`, `eligible: true`, `recipient_email`, `recipient_fingerprint`, `consent_version`, `issued_at` dan `expires_at`. Tanggal UTC bersufiks Z dan expiry berjarak 60 detik. Fingerprint adalah HMAC P2 dengan pepper Laravel atas email setelah trim dan lowercase.

Respons 200 ineligible hanya memuat `event_id`, `eligible: false` dan `reason`. Alasan berurutan adalah `evaluation_not_final`, `no_consent`, `no_business_email`, `invalid_email`, `suppressed` dan `not_allowlisted`. Semua respons memakai `Cache-Control: no-store`.

| HTTP | Kode | Perlakuan consumer |
| --- | --- | --- |
| 400 | `invalid_request` | Periksa format, path dan skema transport |
| 401 | `unauthorized` | Periksa jam, secret dan signer |
| 404 | `event_not_found` | Lewati event yang hilang atau unsupported |
| 405 | `method_not_allowed` | Koreksi metode |
| 409 | `event_identity_conflict` | Hentikan percobaan, review identitas |
| 429 | `rate_limited` | Gunakan retry terkontrol pada fase consumer |
| 503 atau 5xx | `recipient_unavailable` | Tahan pengiriman sampai service atau konfigurasi pulih |

## Konfigurasi dan pemakaian Hermes

Laravel membaca `HERMES_INTELLIGENCE_RECIPIENT_SECRET`, `HERMES_INTELLIGENCE_RECIPIENT_TOLERANCE_SECONDS`, `INTELLIGENCE_RECIPIENT_TTL_SECONDS`, `INTELLIGENCE_EMAIL_TEST_MODE` dan `INTELLIGENCE_EMAIL_TEST_ALLOWLIST`. Pada dev, toleransi 300 detik, TTL 60 detik dan mode uji aktif. Mode uji memerlukan allowlist valid. Pepper tetap di Laravel.

Hermes menyimpan secret dengan nama yang sama di `/opt/data/joyumkm-intelligence/state/recipient.env` (mode 0600, owner runtime). File ini berisi `INTELLIGENCE_RECIPIENT_BASE_URL=http://host.docker.internal` dan `INTELLIGENCE_RECIPIENT_HTTP_HOST=joyumkm.test` untuk Apache lokal. Probe QA membaca file tersebut langsung. Consumer dan loader runtime recipient merupakan handoff P5-03. Gunakan HTTPS di lingkungan nonlokal, larang redirect, dan jangan catat header, body, alamat maupun secret.

Consumer mengambil paket dari Package Ready lokal Hermes. Pull ulang tepat sebelum setiap percobaan SMTP, termasuk retry, dan gunakan alamat hanya di memori sebelum expiry. Hentikan percobaan bila consent berubah atau fingerprint berbeda. Pencabutan setelah pull tidak dapat menarik kembali pesan yang sudah diserahkan ke SMTP.
