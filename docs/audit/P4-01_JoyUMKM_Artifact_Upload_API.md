# P4-01 — Artifact Upload API & Authentication

**Verdict: PASS** (autentikasi, parsing request, kontrak rute). Kontrak lengkap: `docs/evidence/p4-01-upload-api-contract.md`.

## Implementasi
- Rute `PUT /api/internal/intelligence/artifacts/{eventId}/{kind}` (`routes/api.php`).
- `VerifyIntelligenceArtifactSignature` (middleware), `IntelligenceArtifactSignature` (kontrak kanonis & penanda tangan), `IntelligenceArtifactUploadRequest` (Form Request), `IntelligenceArtifactController` (tipis), `IntelligenceArtifactUploadException` (envelope error stabil).
- Konfigurasi `services.hermes_intelligence_artifact.{secret,tolerance_seconds}`, `.env.example` (tanpa nilai), rate limiter `joyumkm-intelligence-artifact`.

## QA (`IntelligenceArtifactUploadAuthTest`, 18/18 PASS, 102 assertion)
Valid signed; header hilang; signature salah; secret salah; expired & future; secret kosong/pendek (503); event/kind salah (404); method tampering (405); path/event/kind tampering dan reuse lintas event & lintas kind (401); MIME & Content-Length tampering (401); 415 signed MIME/Content-Encoding; checksum header diubah (401) dan body diubah (422 `checksum_mismatch`); oversized 413, size mismatch (lebih besar/lebih kecil) 422; body kosong; duplicate/replay; upload sebelum callback tanpa efek samping; akses publik ke storage (403/404, disk tanpa `url`, `serve=false`); 5 golden vector PHP.
- Python (container `hermes-agent`): 5/5 vektor identik (`p4-01-vectors-python-result.txt`).

## Dependency pada P4-02/P4-03
Skenario sukses penyimpanan memakai tabel manifest (P4-03) dan penyimpanan (P4-02); seluruhnya terintegrasi dan lulus pada QA gabungan.

## Catatan
Secret nyata tidak pernah ditulis; semua QA memakai secret khusus uji. Secret produksi harus dibuat oleh operator (≥32 byte acak) dan diset di environment privat Laravel + klien Hermes (P4-04/P4-07).
