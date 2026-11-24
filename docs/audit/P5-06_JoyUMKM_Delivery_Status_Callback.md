# P5-06 · Sinkronisasi status pengiriman email

**Verdict lokal: PASS.** Hermes mengirim snapshot outcome SMTP melalui callback HMAC khusus. Laravel memvalidasi event finalisasi v2, laporan, dan expected manifest. Outcome dapat diterima sebelum PDF dan Excel diunggah.

- Laravel memakai `intelligence_deliveries`. Migrasi `2026_11_25_100000_add_email_status_sync_to_intelligence_deliveries` menambah snapshot JSON, digest, dan unique key untuk email tersinkronisasi. Foreign key laporan dan aturan kanal artefak tetap berlaku. Receiver mengunci row outbox sebelum menulis delivery.
- Status Hermes disimpan bersama revisi dan diproyeksikan ke status database. Receiver mengakui duplikat identik, mengabaikan revisi lebih lama, dan menolak revisi konflik, perubahan identitas yang sudah dipin, penurunan attempt, serta regresi terminal.
- Runtime Hermes menyimpan request byte-for-byte di sidecar privat. Recovery memakai lease tersendiri dengan budget empat request per snapshot. `email-status-sync-resume` memberi satu kesempatan menjalankan kembali budget sinkronisasi setelah habis. Recovery status tidak memiliki jalur untuk menjalankan SMTP, AI, render, atau upload.
- Detail UMKM menampilkan analisis, ketersediaan dokumen, dan status email untuk laporan aktif. `sent` berarti “Diterima server email” dan menampilkan waktu WIB. Laporan historis tanpa proyeksi tampil “Belum tersedia”. Unduhan melewati pemeriksaan akses dan verifikasi integritas P4.
- Outcome lokal yang belum memiliki identitas paket tetap terlihat melalui CLI. Receiver mengembalikan `report_not_ready` saat laporan belum ada dan `report_conflict` untuk laporan v2 tanpa manifest yang terikat secara permanen.

[Kontrak callback dan recovery](../evidence/p5-06-delivery-status-contract.md) · [QA P5-07](../evidence/p5-07-qa-summary.md)
