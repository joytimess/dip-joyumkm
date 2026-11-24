# P5-03 — Recipient consumer dan SMTP

**PASS untuk QA lokal.** Hermes mengambil otorisasi penerima melalui signed GET P5-01 sebelum setiap percobaan. Probe Docker ke Laravel terisolasi menerima HTTP 200 untuk event sintetis tanpa report atau upload P4. Pull baru setelah pencabutan consent menghentikan pengiriman dengan `NO_CONSENT`. Probe runtime persisten ke Apache Laragon juga menerima 404 yang diharapkan untuk UUID tanpa event.

`jmi/recipient.py` memvalidasi identitas event dan UMKM, mailbox tunggal, fingerprint, consent `v1`, TTL 60 detik, dan exact allowlist. `jmi/email_transport.py` membedakan kegagalan sebelum DATA, penolakan eksplisit, acknowledgment 250, dan hasil ambigu. Transport menguji kedua mode TLS dengan SMTP palsu. Konfigurasi Laravel yang diprovisikan menggunakan TLS implisit port 465.

`state/email.env` dan `state/recipient.env` dapat dibaca oleh UID runtime Hermes dan memakai mode `0600`. Ownership `recipient.env` diperbaiki dari root ke Hermes dengan hash isi yang sama. Konfigurasi SMTP disalin secara privat dari dotenv Laravel. Perbandingan konfigurasi dan pemeriksaan hash menunjukkan `.env` Laravel tetap identik. Autentikasi dan pengiriman ke Gmail **NOT RUN**.

- [Kontrak recipient dan SMTP](../evidence/p5-03-smtp-recipient-contract.md)
- [Bukti QA](../evidence/p5-05-qa-summary.md)
