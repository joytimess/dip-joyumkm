# P5-07 · QA integrasi dan deployment

**Verdict lokal: PARTIAL.** Alur integrasi, deployment, dan regresi terarah lulus. Gate cleanup masih terbuka karena review otomatis menolak penghapusan rekursif staging Windows dengan alasan “blocked by policy”.

QA memakai satu server MySQL Docker 8.4 yang terpisah dari MySQL Laragon. Proses QA Hermes memakai direktori temporer, SMTP stub, dan satu paket P3 `complete`. Fixture laporan mengikat event sintetis ke manifest byte P3. MIME mempertahankan kedua lampiran asli. Probe nyata melewati Apache/FastCGI Laragon dan storage QA. Tidak memanggil AI, renderer, recipient pull asli, atau SMTP nyata.

Migrasi lulus fresh, down/up kosong, dan upgrade dari dump dev yang dipulihkan dalam schema QA. Dev `joyumkm-v2` mencatat migrasi sebagai batch 9. Digest baris outbox, evaluasi, UMKM, laporan, manifest, artefak, dan delivery cocok sebelum/sesudah. Source dan test Hermes terpasang di volume persisten dengan owner 10000. Fingerprint inventaris 40 file pekerjaan Hermes cocok sebelum/sesudah.

Backup privat Windows berada pada `%LOCALAPPDATA%/JoyUMKM-P506-Private-20261124T174935Z` dan dibatasi ke akun pemilik, SYSTEM, dan Administrators. Isinya dump MySQL, salinan konfigurasi sebelum provisioning, dan bukti visual sintetis. Source Hermes sebelumnya berada di `/opt/data/joyumkm-intelligence/.JoyUMKM-P506-Private-20261124T174935Z`. Untuk rollback, pertahankan pause, pulihkan source Hermes dan konfigurasi dari backup privat setelah membandingkan perubahan terbaru, lalu hentikan penggunaan route/proyeksi P5-06. Rollback migration menolak eksekusi jika snapshot sudah ada sehingga riwayat tidak terhapus.

Resource Docker, MySQL QA dan schema restore, Apache/FastCGI QA, Chrome QA, dan staging Hermes sudah dibersihkan. File token fixture Windows sudah dihapus dan database penerbit token sudah dibuang. Folder `%TEMP%/joyumkm-p506-work` masih ada, termasuk profil browser QA yang sudah berhenti. Inventaris Docker kembali ke baseline 16 kontainer. Tidak ada port QA yang masih listen.

[Hasil per acceptance criterion](../evidence/p5-07-qa-summary.md)
