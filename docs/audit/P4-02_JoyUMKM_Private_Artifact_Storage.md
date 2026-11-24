# P4-02 — Private Storage & Integrity

**Verdict: PASS.** Kontrak: `docs/evidence/p4-02-storage-contract.md`; pemulihan: `docs/evidence/p4-02-storage-recovery.md`.

## Implementasi
`IntelligenceArtifactReceiver` (stream, SHA-256, publish tanpa overwrite, registrasi, rekonsiliasi status, `verifyStored`), `IntelligenceArtifactFormatValidator`, disk `intelligence`. Tabel P2 `intelligence_artifacts` dipakai apa adanya (append-only, composite FK, unique report+kind & disk+path).

## QA (filesystem nyata di direktori temp terisolasi)
- `IntelligenceArtifactStorageTest`: 24 tes, 23 PASS + 1 SKIP (symlink; Windows tanpa hak). Cakupan: PDF/XLSX valid byte-identik, path server, SHA/ukuran, manifest mismatch, PDF tidak valid/terpotong/JS, XLSX hostile (traversal, absolute, macro, external link, embedding, relasi eksternal, zip bomb, non-zip, bagian hilang, terenkripsi), disk write failure, crash DB setelah publish, staging terputus, lost response, baris tanpa file, file rusak, konflik checksum, isolasi lintas event, append-only DB.
- `IntelligenceArtifactConcurrencyTest` (MySQL nyata, 6 proses PHP paralel dengan barrier waktu): identik ×6 → 1×201 + 5×200; PDF+XLSX paralel → complete tanpa lost update; checksum konflik → tepat 1 pemenang; event berbeda/byte sama terisolasi; upload bersamaan dengan retry callback; callback identik paralel → 1 report + 1 manifest. 6/6 PASS.

## Batas yang diketahui
Ekspektasi Windows ACL & permission Linux tidak diuji otomatis; pemeriksaan symlink Linux tidak dijalankan di host Windows ini (NOT RUN). Pemindaian PDF berbasis token tidak membuka stream terkompresi.
