# QA presentasi DIP-JoyUMKM

**Verdict akhir: PASS untuk artefak presentasi.** Status ini berlaku untuk empat gate di bawah. Bukti implementasi dan UAT dibatasi cakupan yang tercantum di dokumen Evidence.

| Gate | Hasil | Bukti dan pemeriksaan |
|---|---|---|
| 1 · Technical Accuracy | PASS | Matriks klaim ditinjau terhadap source dan bukti yang sudah terkumpul. Diagram dan workflow memperlihatkan arah dispatch HMAC, GET snapshot, dan callback/artefak. Cabang SMTP dan delivery Laravel berbagi paket terverifikasi. Skenario 3 eksplisit sebagian, Tiga Dimensi bisa belum tersedia, dan status UAT, SMTP, inbox, rollout serta fitur konseptual ditulis terpisah. |
| 2 · Content Completeness | PASS | Tepat 14 slide mengikuti struktur. Lima topik, tiga skenario dan enam tahap journey diperiksa. Semua slide memiliki speaker notes dengan referensi dan revisi. Placeholder cover hanya untuk identitas, mata kuliah, dan institusi. |
| 3 · Visual Quality | PASS | Microsoft PowerPoint 16.0 membuka dan merender 14 slide pada 1920 × 1080. Semua PNG diperiksa satu per satu. Pemeriksaan batas shape dan dimensi teks mencakup text box, shape dan sel tabel. Tidak ditemukan teks terpotong, tumpang tindih visual atau panah terbalik pada render final. |
| 4 · Artifact Integrity | PASS | PowerPoint berhasil membuka file. Pembacaan ulang python-pptx memvalidasi 14 notes, empat tabel native pada slide 09/11/12/13, dan connector native pada diagram dan kurva. CRC ZIP, XML dan target relationship internal valid. Tidak ada media atau relationship eksternal. Empat belas PNG final dan contact sheet tersedia. |

## Perbaikan setelah render pertama

- Slide 02 dan 03 mendapat pemendekan teks dan pengaturan ulang baris di area yang terlalu rapat.
- Slide 12 dan 13 memakai label “Kanal” untuk mencegah kata terbelah di kolom dan perataan vertikal tabel diperbaiki.
- Slide 14 mendapat kalimat pembuka yang lebih singkat sehingga tidak melewati kotak teks.
- Efek bayangan bawaan PowerPoint dihapus dari shape dan connector. Render ulang dan pemeriksaan per slide dilakukan setelah perbaikan.

## Pembuatan ulang

Jalankan `python docs/presentation/DIP-JoyUMKM_Presentation_Source.py` dengan python-pptx 1.0.2. Paket PPTX menggunakan rasio 16:9, palet brand dari `docs/DESIGN_TOKENS.css`, dan Arial sebagai fallback karena Poppins tidak terpasang sebagai font Windows. Preview final diekspor dari PowerPoint.

## Batas pemeriksaan

Uji aplikasi, UAT dan delivery tidak dijalankan ulang. Pemeriksaan runtime tambahan dihentikan sesuai arahan pengguna. Bukti QA aplikasi berasal dari hasil yang sudah terdokumentasi. Rollout terakhir OFF/PAUSED, penerimaan SMTP terbukti pada uji terbatas, inbox dan hosting eksternal belum diverifikasi.
