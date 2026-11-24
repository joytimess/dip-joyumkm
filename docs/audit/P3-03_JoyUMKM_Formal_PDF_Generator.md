# P3-03 — JoyUMKM Formal PDF Generator

## Handoff

**P3-03 PASS.** Lima PDF lulus validasi dan inspeksi visual. Tes terarah Hermes lulus 130/130, termasuk delapan tes baru P3-03. Perbandingan suite historis menemukan nol identitas kegagalan baru. Uji lintas container membuktikan artefak bertahan dan dapat dipakai ulang tanpa perubahan byte.

Generator PDF formal tersedia di `hermes_data:/joyumkm-intelligence/`. Operator menjalankan `python -m jmi render-pdf EVENT_ID` dari root JMI sebagai pengguna `hermes`. Pemeriksaan sesudah render memakai `python -m jmi pdf-verify EVENT_ID`. PDF dan sidecar tersimpan di folder job yang sama dengan laporan kanonik.

Alur runtime memvalidasi job dan `report.json` terhadap konteks kanonik P3-02, membangun tampilan deterministik, merender ReportLab A4, memvalidasi PDF dengan pypdf, lalu memublikasikan PDF dan metadata secara atomik per berkas. Lock per event mengatur penulisan dan verifikasi. PDF sah dapat dipakai ulang dengan byte identik. Seluruh proses render dan recovery yang diuji berjalan dengan jaringan terputus.

| Dokumen | Isi |
|---|---|
| [Kontrak PDF](../evidence/p3-03-pdf-contract.md) | Perintah operator, pemetaan sepuluh bagian, dependency, metadata, status dan recovery. |
| [QA dan regresi](../evidence/p3-03-test-regression.md) | Hasil tes terarah, perbandingan historis, inspeksi 63 halaman, dan bukti persistence. |
| [Checksum deployment](../evidence/p3-03/hermes-deployment-sha256.txt) | SHA-256 file persisten dan backup CLI lama. |
| [Sampel PDF](../evidence/p3-03/samples/) | Lima PDF kanonik sintetis dan satu sidecar per PDF berisi metadata serta hash sumber. |

## File yang berubah

| Lokasi | Perubahan |
|---|---|
| Volume `jmi/__main__.py` | Perintah `render-pdf` dan `pdf-verify`. |
| Volume `jmi/pdf.py` | Validasi sumber/PDF, lock, idempotensi, recovery dan persistence. |
| Volume `jmi/pdf_template.py` | Template formal, presentasi, tabel, grafik vektor, header dan footer. |
| Volume `jmi/pdf_assets/` | Poppins Regular dan SemiBold, DejaVu Sans, mark JoyUMKM serta dua file lisensi. |
| Volume `tests/test_pdf.py` | Delapan tes PDF dan fixture QA. |
| Volume `backups/p3-03-before-pdf/jmi__main__.py` | Salinan CLI sebelum pemasangan. |
| Repo `docs/audit/` dan `docs/evidence/` | Handoff ini, kontrak, laporan tes, sampel dan sidecar, bukti visual per halaman, serta hasil verifikasi. |

Deployment memakai image Hermes lokal `sha256:d6746a084e9341baada483e3d6210444d3c1aeb7c130f0915150065fb2851459`. Bukti final dikumpulkan dari container one-off yang memasang sumber persisten secara read-only. Data QA dipisahkan melalui `JMI_HOME=/qa/state` pada volume `jmi-p303-visual-final`. SHA-256 setiap file yang dipasang dicocokkan dengan staging.

## Batas verifikasi

Sumber uji adalah lima laporan sintetis P3-02. Aset final memakai mark favicon resmi repo dan wordmark JoyUMKM Intelligence. Cakupan huruf mengikuti Poppins Latin dan DejaVu Sans. Validitas keluaran terkait dengan ketersediaan konteks kanonik job P3-02 dan versi template aktif. Kualitas data atau narasi produksi serta hasil cetak fisik berada di luar verifikasi ini.

`HERMES_WEBHOOK_ENABLED=false` dikonfirmasi pada konfigurasi lokal. Konfigurasi Laravel, database, backlog dan state historis berada di luar pekerjaan P3-03. Kode Laravel tidak berubah sehingga suite Laravel penuh berstatus NOT RUN.
