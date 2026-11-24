# P3-04 — JoyUMKM Excel Workbook Generator

**P3-04 PASS.** Generator XLSX tersedia di volume `hermes_data`. Lima sampel lulus validasi sumber, pembukaan Excel, rekalkulasi dan inspeksi cetak LibreOffice. Tes terarah Hermes lulus **135/135**. Suite historis mempertahankan **49 identitas kegagalan** dengan **0 regresi baru**.

Alur render membaca laporan kanonik P3-02 dan memverifikasi konteks sumber dengan validator P3-03. Template membangun Ringkasan, Enam Faktor, Tiga Dimensi, Histori, dan Rekomendasi menggunakan XlsxWriter. Openpyxl dan validator OOXML memeriksa paket sebelum dipublikasikan. Lock per UUID dan sidecar mengikat artefak ke byte laporan kanonik. Container kedua memverifikasi dan memakai ulang lima workbook serta sidecar dengan byte identik. Lima PDF P3-03 dan laporan sumber tetap identik.

Operator menjalankan `python -m jmi render-xlsx EVENT_ID` dan `python -m jmi xlsx-verify EVENT_ID` dari root JMI sebagai pengguna `hermes`. Antarmuka Python ialah `jmi.xlsx.operate(event_id, verify=False)`. Manual CLI ini tersedia tanpa perubahan alur worker.

| Berkas | Perubahan |
|---|---|
| `hermes_data:/joyumkm-intelligence/jmi/__main__.py` | Registrasi dua perintah CLI. |
| `hermes_data:/joyumkm-intelligence/jmi/xlsx.py` | Validasi konteks, paket OOXML dan sidecar, eksklusi penulis, publikasi dan pemulihan. |
| `hermes_data:/joyumkm-intelligence/jmi/xlsx_template.py` | Lima worksheet, format brand, formula dengan cache, conditional formatting dan grafik native. |
| `hermes_data:/joyumkm-intelligence/tests/test_xlsx.py` | Tes sampel, keamanan, stress dan recovery. |
| `hermes_data:/joyumkm-intelligence/backups/p3-04-before-xlsx/jmi__main__.py` | Backup CLI sebelum deployment. |
| `docs/audit/` dan `docs/evidence/` | Handoff, kontrak, laporan QA, lima pasangan XLSX dan sidecar, screenshot cetak serta bukti checksum. |

- [Kontrak, CLI, worksheet dan kebijakan recovery](../evidence/p3-04-xlsx-contract.md)
- [QA, inspeksi visual dan regresi](../evidence/p3-04-test-regression.md)
- [Lima workbook sintetis beserta sidecar](../evidence/p3-04/samples/)
- [Checksum deployment](../evidence/p3-04/deployment-sha256.txt)

Cakupan verifikasi adalah job sintetis dengan format kanonik P3-02 pada image Hermes yang tercatat. Penampil spreadsheet menentukan substitusi font Poppins dan lokal tampilan tanggal. Konflik versi atau sumber membutuhkan pemeriksaan operator jika ID event telah memiliki artefak. Laravel full suite, printer fisik, dan ekspor cetak Excel tidak terverifikasi pada tahap ini.

