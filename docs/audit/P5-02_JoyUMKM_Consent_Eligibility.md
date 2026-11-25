# P5-02 — Consent dan Eligibility

Buka **Detail UMKM → Kontak Usaha → Edit Kontak → Pengaturan Pengiriman Laporan**. Checkbox menggunakan email usaha pada form yang sama. Status hanya berubah setelah PATCH berhasil. Email baru mereset checkbox. Jika pengguna mengembalikan alamat saat editor dibuka, editor mengembalikan keadaan consent awal untuk alamat tersebut. Pengguna dapat mencabut consent dengan mengosongkan centang dan menyimpan. Panel menunjukkan bahwa pengiriman otomatis belum aktif.

`PATCH /api/umkms/{id}` tetap memakai Sanctum dan ownership UMKM. Mutasi consent menerima:

| Field | Grant | Revoke |
| --- | --- | --- |
| `notification_consent` | `grant` | `revoke` |
| `expected_business_email` | Email tersimpan yang ditampilkan saat editor dibuka, atau `null` jika kosong | — |
| `consented_business_email` | Email valid yang disetujui penerima | — |
| `business_email` | Kirim bila alamat berubah | Opsional mengikuti aturan edit kontak |

Server mengunci baris UMKM saat membandingkan email awal dan email akhir. Email awal yang stale menghasilkan 409. Ketika alamat diganti, hook model menghapus metadata consent. Grant eksplisit untuk alamat baru menyimpan UTC, versi `v1` dan actor server dalam transaksi yang sama. Revoke mengosongkan tiga field metadata dan aman diulang. Update field lain mempertahankan consent. Data detail terotorisasi mengembalikan `notification_consent_active` dan `business_email_valid` untuk status form.

Urutan keputusan. Final → consent → email tersedia → format valid → suppression. Suppression tetap berlaku setelah grant. Normalisasi fingerprint mengikuti P2 melalui trim dan lowercase. Alamat pengiriman berasal dari email usaha milik UMKM. Pengaturan suppression tetap privat.

Alur update email aplikasi melewati model UMKM. Operasi batch WhatsApp yang memakai query builder hanya menulis status pemeriksaan WhatsApp. SQL administrator yang melewati hook model tetap menjadi batas operasional. Skema P2 dipakai langsung.
