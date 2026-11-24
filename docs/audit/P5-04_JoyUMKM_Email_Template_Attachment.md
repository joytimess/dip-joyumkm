# P5-04 — Template dan lampiran

**PASS untuk QA lokal.** `jmi/email_template.py` memakai dokumen canonical yang sudah diverifikasi oleh `transport.prepare_verified_package()`. Body mencantumkan periode dan cutoff WIB, total Enam Faktor, INS/ECT/OPS, nilai sebelumnya dan berjalan, arah serta perubahan poin, dan narasi perkembangan canonical. Data absen tampil sebagai **Belum tersedia**. PDF dan XLSX memuat rincian serta rencana tindak lanjut.

MIME terdiri atas body HTML dan plain text UTF-8 serta tepat dua lampiran. Nama lampiran mengikuti `JoyUMKM_Report_YYYY-MM.pdf` dan `JoyUMKM_Report_YYYY-MM.xlsx`. Uji membandingkan ukuran dan SHA-256 bytes hasil decode dengan manifest. HTML meng-escape nama dan narasi, menghapus URL dari narasi, serta memakai warna JoyUMKM tanpa aset eksternal.

Worker mengukur pesan setelah serialisasi CRLF dan enkode MIME. Batas lokal 24.000.000 byte dapat diturunkan lewat `JMI_EMAIL_MAX_BYTES`. Transport juga memeriksa SIZE yang diiklankan server. Uji pesan terlalu besar berhenti sebelum SMTP. Batas Gmail dan tampilan pada klien email aktual **NOT RUN**.

- [Bukti QA](../evidence/p5-05-qa-summary.md)
