# P5-01 dan P5-02 — Recipient Consent

P5-01 dan P5-02 memenuhi acceptance lokal Laragon dan Hermes Docker. Signed GET bekerja pada event v2 yang sah sebelum callback atau upload. Pemilik UMKM dapat memberi serta mencabut consent dari editor kontak. API menolak stale grant setelah email berubah.

Perubahan utama ada di middleware dan controller recipient, service eligibility/suppression, alur update UMKM, resource profil, dan editor Kontak Usaha. Schema P2 tetap digunakan. Tes terarah berjalan pada MySQL uji dengan fixture sintetis.

Laravel dan Hermes memakai secret recipient khusus yang cocok. Laravel menyimpan satu alamat allowlist secara privat dan mode testing aktif. ACL `.env` dibatasi ke akun runtime, SYSTEM dan Administrators. Backup konfigurasi dan ACL tersimpan di folder privat owner. Jalur HTTP lokal memakai alias Docker dan virtual host Apache Laragon. Webhook, pipeline dan delivery tetap dipause. SMTP dan pepper tetap menggunakan konfigurasi sebelumnya.

- [Implementasi recipient pull](P5-01_JoyUMKM_Recipient_Pull_API.md)
- [Implementasi consent dan eligibility](P5-02_JoyUMKM_Consent_Eligibility.md)
- [Kontrak signer dan handoff P5-03](../evidence/p5-01-recipient-api-contract.md)
- [Matriks QA dan cleanup](../evidence/p5-02-qa-summary.md)

P5-03 mengambil paket lokal Package Ready serta memanggil signer dengan config di atas. Consumer harus pull ulang setiap percobaan dan menghormati TTL, kategori error serta perubahan fingerprint. Pengujian hosting nonlokal, SMTP dan pekerjaan P5-03 sampai P5-07 berada di fase berikutnya.
