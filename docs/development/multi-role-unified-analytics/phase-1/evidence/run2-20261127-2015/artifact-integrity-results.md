# Evidence: Artifact Integrity Results (Run 2)

- **Run:** `run2-20261127-2015`
- **Event:** `5dd7efbf-b320-4be8-904d-707bbd2e8450` (UMKM 457, evaluation 1317, report 11)
- **Verdict:** `PASS` for integrity across all four sources (Hermes manifest, Hermes disk, Laravel row, downloaded bytes).

## 1. Checksum Matrix

| Artifact | Size (bytes) | SHA-256 (full, Hermes manifest) | Hermes disk | Laravel row (`intelligence_artifacts`) | Downloaded via API | Match |
|---|---|---|---|---|---|---|
| PDF (`report.pdf`) | 41,201 | `f0858a28fbd085930030eb1090a91f6800fb63fadcea2cd425cbee04f9a0c3d0` | match | id 13, `sha256` prefix `f0858a28fbd0`, size 41,201 | `f0858a28fbd085930030eb1090a91f6800fb63fadcea2cd425cbee04f9a0c3d0` | YES |
| XLSX (`report.xlsx`) | 27,101 | `7283acebeafaf3596acbe9c8b8ae5b76d4479783d823960b8df963ae6d617ae1` | match | id 14, `sha256` prefix `7283acebeafa`, size 27,101 | `7283acebeafaf3596acbe9c8b8ae5b76d4479783d823960b8df963ae6d617ae1` | YES |

## 2. Other Integrity Records

| Record | Value | Check |
|---|---|---|
| Manifest | `intelligence_artifact_manifests.id = 7` (report 11) | present |
| Laravel manifest `transport_artifact_set_hash` | `81ba1ba3dd5e4836bb0616f6d848c1bda8853369ff9fa91fd57dc6c8f7352d63` | MATCH with Hermes `delivery.json` binding and callback `ack_transport_artifact_set_hash` |
| Hermes `artifact_set_hash` (email binding; not stored in Laravel manifest table) | `fa6dc6034d8de31e551ed07d3f23dd0e1df7bfc3938558e1e77567f7329e7fb2` | Hermes-side only; Laravel manifest table has no such column |
| `report_hash` | `78a2a05f…` | matches the callback `report_hash` |
| Immutability triggers on `intelligence_artifacts` and `intelligence_reports` | Present (6 triggers in schema) | Not exercised; not altered by Run 2 |

## 3. Download Method

`curl` GET to `http://joyumkm.test/api/umkms/457/intelligence-reports/11/artifacts/{pdf|xlsx}` with the owner's bearer token. The token was revoked afterwards (`POST /api/auth/logout` → 200). The downloaded bytes were hashed and deleted. No file copy was kept in the repository or in a public location.

## 4. Caveat

The Tab Intelligence UI was not opened. The download endpoint is the same one the tab uses, so the bytes are verified, but the on-screen tab behaviour is not.
