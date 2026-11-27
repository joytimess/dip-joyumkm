# Evidence: P1-01 Backup Metadata & Verification (`run-20261127-1918`)

- **Evidence ID:** `EV-P1-BACKUP-001`
- **Captured At:** `2026-11-27T19:26:11+07:00` – `19:26:16+07:00` (`Asia/Jakarta`)
- **Backup Location (Outside Repo & `storage/app/`):** `C:\laragon\backups\multi-role-pre-p1-20261127-192611\`
- **Access Restriction:** NTFS inheritance removed via `icacls`; granted Full Control exclusively to current operator account, `SYSTEM`, and `Administrators`.
- **Restore Rehearsal Status:** **`NOT RUN`** (deferred pending operator authorization of a disposable target schema such as `joyumkm_p1_restore_rehearsal`; do not treat file existence/structural check alone as restore proof).

---

## 1. Backup Artifact Inventory & Integrity Digests (`SHA256SUMS.txt`)

| Artifact (Relative Path) | Source / Method | Size (Bytes) | SHA-256 Digest |
|---|---|---:|---|
| `joyumkm-v2.sql` | `mysqldump --tz-utc --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF` (credential via `MYSQL_PWD` child env, never CLI/logs) | `1,991,686` | `3cfc15a4b644d59da7eec94f651613fb91b943d16b95f0d562a296a40f89987b` |
| `joyumkm_p1_prod.sql` | Same consistent `mysqldump` flags against `joyumkm_p1_prod` (incl. 3 `SQL SECURITY DEFINER` views) | `1,752,388` | `1ab9ec97c3ba3d87eea545ec263582bfad298eca76b68ecaa242130be428cb2d` |
| `laravel.env.bak` | Exact copy of `.env` stored outside repo | `3,833` | `71571d34e27ad173e4dea14b0433b9ff81d30d92364da23c8a9dee5c0c08c147` |
| `hermes_state_snapshot.tar.gz` | Online `sqlite3.Connection.backup()` (`onboarding_receipts.sqlite3`, `state.db`, `cron_executions.db`) + `workflow-designs`, `webhook_subscriptions.json`, `cron`, `joyumkm-onboarding/settings.json`, `joyumkm-analytics/mysql.json`, `joyumkm-intelligence/state` | `21,578,443` | `f095f03ffe3e0a0648b156f0c6ee75b987e071c4eb1188c2ee49109dc556b5ba` |
| `intelligence_artifacts\` (6 event dirs, 12 files) | Exact tree copy of `storage\app\private\intelligence\` (`.pdf` + `.xlsx` pairs) | `412,175` (sum of 12 files) | Verified in `SHA256SUMS.txt` |

### `intelligence_artifacts/` File Checksums

| Event UUID | File | Bytes | SHA-256 |
|---|---|---:|---|
| `048bbef9-998f-4f5e-8626-e0995862af71` | `.pdf` / `.xlsx` | `41,923` / `28,052` | `f965d96dfe968401...be27a` / `eeda748248375bf3...f8a9f` |
| `07a9797b-70da-40e6-8109-11d28105b934` | `.pdf` / `.xlsx` | `42,091` / `28,022` | `0ee7e465faff4471...545b0` / `fe7634184c0f0234...16fc8` |
| `27594ddf-24b1-4025-95d8-ee648f059d93` | `.pdf` / `.xlsx` | `42,564` / `27,764` | `16f8e183fef261fc...f5b7a` / `a3539a4d2e319db1...c2fca` |
| `a424c677-7c5a-4c59-86aa-e35d9d348364` | `.pdf` / `.xlsx` | `37,746` / `26,541` | `f016306202590c37...2c98` / `fb895dd9f8f8afed...02e3` |
| `b919af9d-1f88-4051-bd86-c8d38be6fb64` | `.pdf` / `.xlsx` | `41,945` / `27,710` | `239f60b63c7305d8...387b` / `405daa71a215c01b...8d6ce` |
| `f1640aea-f389-4c6b-9b33-64849ba83b33` | `.pdf` / `.xlsx` | `40,258` / `27,459` | `0109e720a9fff92e...227e` / `73bafe7fa9eac6a6...87a0d` |

---

## 2. Structural Verification & Consistency Notes

- `joyumkm-v2.sql` structural check: `1,097` lines, `28` `CREATE TABLE` statements, `Dump completed` footer verified present, consistent `--single-transaction` snapshot (zero active Laravel workers or Hermes crons running at capture time).
- Pre-existing historical backups in `C:\laragon\backups\` (`joyumkm-recovery-20261123-125350`, `hermes-vw-20261125`, `hermes-consolidation-20261127`) were left completely untouched.
- **Restore Procedure (When Authorized):** Create disposable target schema (`CREATE DATABASE joyumkm_p1_restore_rehearsal`), verify SHA-256 via `Get-FileHash`, import `joyumkm-v2.sql`, assert `28` tables, `6` immutability triggers on `intelligence_*`, `456` UMKM, `1,339` 3D, `1,316` 6F, then drop the temporary rehearsal schema.
