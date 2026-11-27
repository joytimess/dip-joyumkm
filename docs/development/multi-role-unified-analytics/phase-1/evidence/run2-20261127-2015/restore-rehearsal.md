# Evidence: Step 2 — Restore Rehearsal (Run 2)

- **Run:** `run2-20261127-2015` (Track C live UAT authorization, continuation of `run-20261127-1918`)
- **Recorded at:** 2026-11-27T20:15+07:00 (Asia/Jakarta)
- **Target schema (temporary, rehearsal only):** `joyumkm_p1_restore_rehearsal`
- **Verdict:** `PASS` — restore reproduces the backup; temp schema dropped after this evidence was recorded (see §6).

## 1. Backup Checksum Verification

Backup folder: `C:\laragon\backups\multi-role-pre-p1-20261127-192611\`

| File | Expected SHA-256 (SHA256SUMS.txt) | Recomputed | Result |
|---|---|---|---|
| `joyumkm-v2.sql` | `3cfc15a4…89987b` | `3cfc15a4…89987b` | MATCH |
| `joyumkm_p1_prod.sql` | `1ab9ec97…28cb2d` | `1ab9ec97…28cb2d` | MATCH (verified only; not restored — out of rehearsal scope) |

## 2. Pre-Restore Safety Checks on the Dump

- `CREATE DATABASE`, `USE`, `DROP DATABASE`, `DROP SCHEMA`, `CREATE SCHEMA` statements: **0 found** in `joyumkm-v2.sql`. The only schema references are header comments (`-- Database: joyumkm-v2`) and `-- Dumping … for database` comments, which are not executed.
- Target schema check: `SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='joyumkm_p1_restore_rehearsal'` → `0` (absent, not used by app or other process).
- Dump contents: 28 `CREATE TABLE`, 6 immutability triggers (`intelligence_artifact_manifests`, `intelligence_artifacts`, `intelligence_reports`, each `before_update` and `before_delete`), charset utf8mb4.
- Restore command targeted only the rehearsal schema explicitly: `mysql -h127.0.0.1 -uroot joyumkm_p1_restore_rehearsal < joyumkm-v2.sql` (exit `0`).
- `joyumkm-v2`, `joyumkm_p1_prod`, and all other schemas were **not** written to. Only read-only `SELECT` queries ran against `joyumkm-v2` for comparison.

## 3. Structural Verification (rehearsal vs live `joyumkm-v2`)

| Check | Rehearsal schema | Live `joyumkm-v2` (read-only) | Match |
|---|---|---|---|
| Base tables | 28 | 28 | YES |
| Triggers | 6 | 6 | YES |
| `migrations` rows | 22 | 22 | YES |

## 4. Data Verification (rehearsal vs live, read-only)

| Table / metric | Rehearsal | Live | Match |
|---|---|---|---|
| `umkms` | 456 | 456 | YES |
| `evaluations` | 1339 | 1339 | YES |
| `six_factor_evaluations` | 1316 | 1316 | YES |
| `users` | 7 | 7 | YES |
| `webhook_outbox` | 24 | 24 | YES |
| `jobs` | 2 | 2 | YES |
| `intelligence_reports` | 10 | 10 | YES |
| `intelligence_artifacts` | 12 | 12 | YES |

## 5. Foreign Key Integrity

- 15 single-column FK constraints checked on both schemas with a generated `LEFT`-style existence query over `information_schema.KEY_COLUMN_USAGE`.
- Child rows with NULL FK value are excluded, consistent with MySQL FK semantics.
- Orphan rows, rehearsal: **0**. Orphan rows, live: **0**.

**Note on an intermediate check:** the first pass used the null-safe `<=>` operator, which counted NULL child values as orphans and reported 453 on `umkms.notification_consent_by_user_id`, on both the rehearsal and live schemas. Live `NOT IN` checks confirmed 0 non-NULL orphans (3 rows with a consent-by value, all valid). The corrected check is what is recorded above. This was a verification-query defect, not a data defect.

## 6. Cleanup

- `DROP DATABASE joyumkm_p1_restore_rehearsal` executed after this evidence was written. Only this schema is dropped.
- Post-cleanup check: schema count for `joyumkm_p1_restore_rehearsal` must be `0`. Recorded in `execution-action-log.md`.
