# Kontrak status delivery P5-06

## Request dan signature

`POST /api/internal/intelligence/deliveries` menerima satu objek JSON UTF-8 dengan panjang maksimal 16 KiB. Apache menerapkan limit body khusus path. Receiver menolak query, trailing slash, framing ambigu, media type selain JSON, JSON rusak, duplikasi key, dan field tambahan. Limit 60 request/menit per IP untuk endpoint ini. HTTP hanya untuk origin lokal yang dibatasi dan Host `joyumkm.test`. Client memakai TLS terverifikasi untuk HTTPS dan tidak mengikuti redirect.

Header `X-Intelligence-Signature-Version: 1`, `X-Intelligence-Timestamp` dalam epoch detik dengan toleransi ±300 detik, serta `X-Intelligence-Signature` HMAC-SHA256 hex lowercase. Kanon UTF-8 dengan LF aktual dan tanpa LF terakhir.

```text
JMI-DELIVERY-STATUS-V1
POST
/api/internal/intelligence/deliveries
<timestamp>
<sha256 raw JSON bytes>
```

Vektor lintas PHP/Python memakai kunci sintetis `synthetic-delivery-key-0123456789`, timestamp `1795568400`, body `{}`, digest `44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a`, signature `be98d0b8bbac8370f6a7419bdfdbfc9d1e24703b6d2360bbd8d6b7b04a3b9425`. Vektor ini menguji signer. Body tersebut tidak memenuhi schema delivery.

Field wajib mengikuti PRD §5.2. Revision integer 1–9007199254740991, attempt integer 0–4, waktu UTC ISO-8601 hingga enam digit fraksi, channel `email`. Seluruh hash paket terisi mulai attempt pertama. Fingerprint, versi consent, key SMTP, dan Message-ID ditetapkan bersama. Message-ID dibatasi ke key hasil rumus SMTP existing dan domain pengirim yang dikonfigurasi. Error code memakai allowlist P5-05. Timestamp retry wajib setelah outcome dan maksimal 5.400 detik dari outcome. `sent_at` hanya berlaku saat `sent`.

## Binding dan penyimpanan

Receiver mengikat outbox finalisasi v2 ke evaluasi Final dan UMKM, lalu memeriksa hash laporan semantik dan hash transport expected manifest. Hash paket lokal dan hash byte source dipin sebagai identitas yang dilaporkan Hermes. Tidak diperlukan upload lengkap. Snapshot berisi nilai identitas SMTP asli. Kolom key lama menampung key status domain-separated SHA-256 dari `JMI-DELIVERY-STATUS-V1\n{event_id}\nemail`, sentinel fingerprint kosong saat penerima belum dipin, dan kolom provider message ID tetap kosong.

`email_snapshot` menyimpan revisi, substate Hermes, identitas, dan timestamp. `email_snapshot_sha256` menyimpan digest raw body. Unique generated key `synced_email_event` berlaku untuk snapshot email, sementara event yang sudah memiliki row email legacy ditolak sebagai konflik agar identitas lama tidak tertimpa. Down migration menjaga snapshot dengan menolak rollback bila snapshot sudah tersimpan.

## Response dan retry

- `201 stored` atau `200 updated/duplicate/stale` membawa `schema_version`, `result`, `event_id`, `channel`, `accepted_revision`, `snapshot_sha256`, `status`, `attempt_no`, `report_hash`, dan `transport_artifact_set_hash`. Client memverifikasi jenis nilai, identitas penuh, dan digest. Stale dengan revisi remote yang lebih tinggi memblokir sync untuk ditinjau.
- `409 report_not_ready`, `429`, `5xx` sementara, network, dan timeout memakai cooldown 600/1800/5400 detik. Reservation tersimpan sebelum HTTP. Retry menandatangani ulang body yang identik. `503 delivery_config_invalid` dan `401 unauthorized` memblokir sync.
- `404 event_not_found`, payload/format invalid, ack rusak, serta `delivery_conflict`, `report_conflict`, `artifact_conflict`, dan `event_identity_conflict` terminal untuk revisi tersebut.

Sidecar `email_status_sync.json` dibuat saat intake email baru. Sync mengunci lease status lalu lease email untuk capture dan reserve, melepaskan lease email saat HTTP, lalu membaca ulang outcome sebelum ack. Job lama tanpa marker dilewati. CLI `python -m jmi email-status EVENT_ID` menampilkan status lokal dan metadata sync terpilih. `python -m jmi email-status-sync-resume EVENT_ID` mensyaratkan flag aktif, pause lepas, state valid, serta revisi yang statusnya exhausted dan belum pernah di-resume.

Endpoint dokumentasi terotorisasi P4 `GET /api/umkms/{id}/intelligence-reports/{reportId}/artifacts` menambah `email_delivery` nullable. Proyeksi berisi `status`, `sent_at`, dan alasan aman untuk persetujuan, suppression, allowlist, atau email usaha yang tidak tersedia. Perubahan penerima ditampilkan melalui status.
