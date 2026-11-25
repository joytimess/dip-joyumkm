# P5-01 — Recipient Pull API

Hermes menarik penerima melalui `GET /api/internal/intelligence/recipients/{eventId}`. Laravel memverifikasi HMAC khusus recipient sebelum membaca outbox. Batasnya 60 permintaan per menit per IP. UUID harus kanonis dan huruf kecil. Query, body dan metode selain GET ditolak.

Controller mencocokkan ID, jenis, versi, sumber, serta identitas evaluasi dan UMKM di outbox dan payload. Ia membaca evaluasi, profil UMKM dan suppression dalam satu transaksi baca. Respons eligible memakai email dari model UMKM yang sama dengan keputusan eligibility. Event v2 sah dapat diproses saat outbox masih pending dan sebelum ada report atau manifest P4.

Respons eligible hanya memuat identitas event dan UMKM, alamat, fingerprint, versi consent dan jendela penggunaan 60 detik. Respons ineligible memuat event, keputusan dan alasan. Semua respons rute memakai `Cache-Control: no-store`.

Secret recipient khusus tersedia di Laravel dan Hermes. Lokasi efektif dan kontrak signer ada di [kontrak API](../evidence/p5-01-recipient-api-contract.md). Hasil HTTP dan matriks acceptance ada di [ringkasan QA](../evidence/p5-02-qa-summary.md).
