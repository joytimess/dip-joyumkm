# P4-04 — Artifact Upload Client — PASS

Kontrak: [p4-04-client-contract.md](../evidence/p4-04-client-contract.md). QA: [p4-04-transport-qa.md](../evidence/p4-04-transport-qa.md).

Implementasi (Hermes): `jmi/transport.py` (baru), `jmi/callback.py` (`http_request`, `_read_env_file(name)`), `jmi/artifacts.py` (`read_package`). Signer `JMI-ARTIFACT-V1` kompatibel persis (5/5 vektor). Paket dibaca sebagai byte terverifikasi di bawah lock bersama lalu semua lock dilepas sebelum HTTP. Callback membawa proyeksi transport P3-05; ack divalidasi penuh (event, report_id, hash semantik, hash transport; `null` → fail closed). Unggah PDF/XLSX memvalidasi identitas, sha, ukuran, status, `recovered`. Klasifikasi retry/permanent/conflict/operator sesuai P4-01. Konfigurasi privat dengan secret terpisah (≥32 byte, bukan callback/snapshot/webhook), HTTPS wajib (HTTP hanya host lokal + flag eksplisit), TLS terverifikasi, tanpa redirect, respons dibatasi.

Hasil: 24/24 tes transport, 19/19 tes keamanan terhadap Laravel nyata, 5 + 5 + 5 + 6 paket lengkap melalui HTTP nyata. Batas: tidak ada perubahan Laravel, HMAC, hash, atau report kanonik.
