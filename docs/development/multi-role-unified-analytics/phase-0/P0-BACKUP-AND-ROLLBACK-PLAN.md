# P0-07 — Backup & Rollback Plan

- **Audit Timestamp:** `2026-11-27T18:45:00+07:00` (`Asia/Jakarta`)
- **P0 Boundary:** Read-only metadata inspection only. **No backup snapshots were created or restored in P0.** A real backup capture and isolated restore rehearsal MUST be separately authorized and executed before any mutating phase (P2+).

---

## 1. Existing Backup Assets Verified on Host Disk (Metadata Only)

| Asset | Path | Timestamp (WIB) | Size / Verification | Readiness for P2–P8 |
|---|---|---|---|---|
| **Hermes Post-Consolidation Backup** | `C:\laragon\backups\hermes-consolidation-20261127\` | `2026-11-27 10:57` | `hermes_data_critical.tar.gz` (`1.31 MB`, 412 entries), 4 SQLite online backups (`4/4 ok`), Compose/env/script backups, `SHA256SUMS.csv` (19 files) | **FRESH (same day)** — valid Hermes pre-consolidation baseline; take a fresh post-consolidation snapshot before P6! |
| **Hermes Full Volume Archive** | `C:\laragon\jobsheet2\backup-20261125-233805\hermes_data.tgz` | `2026-11-25 23:45` | `938.4 MB` full volume archive + `rollback.md` | Historical pre-Jobsheet 3–6 archive (keep intact, do not overwrite) |
| **Hermes Pre-Visual-Workflow Snapshot** | `C:\laragon\backups\hermes-vw-20261125\` | `2026-11-25 22:44` | `/opt/data` copy + hash manifests | Historical archive |
| **MySQL `joyumkm-v2` Dump** | `C:\laragon\backups\joyumkm-recovery-20261123-125350\joyumkm-v2.sql` | `2026-11-23 12:53` | `28,082 B` + `SHA256SUMS.txt` | **STALE (4 days old — pre-P6 UAT & Jobsheet 3–6). CANNOT be used as P2 rollback!** |
| **Repo Scripts Directory** | `scripts/` | N/A | **Does not exist** in repository | No automated backup script in repo; follow runbook procedures below |

---

## 2. Mandatory Pre-Mutation Backup Checklist (Required Before P2, P3, and P6)

Store all backups outside Git & outside `storage/app` (e.g., `C:\laragon\backups\multi-role-pre-p2-<YYYYMMDD-HHMMSS>\`), restrict ACLs, and **never commit secret-bearing files**.

### 2.1 MySQL Logical Backups (Zero-Lock Consistent Dump — Follows `P2_JoyUMKM_Post_P2_Timezone_Recovery_Runbook.md`)
- Capture **both** `joyumkm-v2` and `joyumkm_p1_prod` (schema + data + triggers + routines + events + views) using:
  ```powershell
  # Proposed command for pre-P2 authorization (DO NOT RUN IN P0)
  mysqldump --login-path=joyumkm-backup --tz-utc --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF joyumkm-v2 > "$backupDir\joyumkm-v2.sql"
  mysqldump --login-path=joyumkm-backup --tz-utc --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF joyumkm_p1_prod > "$backupDir\joyumkm_p1_prod.sql"
  ```
- Export MySQL read-only analytics grants (`SHOW GRANTS` for analytics RO user, password hashes redacted/excluded from repo) and record SHA-256 checksums (`Get-FileHash -Algorithm SHA256`).
- **Restore Rehearsal (Mandatory Gate Before P2):** Restore `joyumkm-v2.sql` into a disposable schema (`joyumkm_v2_restore_rehearsal`), verify table counts, all **6 immutability triggers** on `intelligence_*`, CHECK constraints, and migration batch match source, then drop the rehearsal schema.

### 2.2 Laravel Application, Artifacts & Config Backup
- Record `git rev-parse HEAD`, `git status --short`, and tag/branch baseline (`pre-p2-baseline`).
- Copy `.env` to secure backup folder (NEVER inside Git or `storage/app/`).
- Archive `storage/app/private/intelligence/` (all 6+ event UUID directories with PDF/XLSX files) + SHA-256 manifest.

### 2.3 Hermes Persistent State & Compose Backup (Mandatory Before P6)
- Confirm all 3 cron jobs (`jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring`) are `paused` and `receipts.sqlite3` has `0` pending events.
- Backup `C:\laragon\jobsheet2\docker-compose.yml`, `C:\laragon\jobsheet2\.env`, `docs/deployment/hermes-proxy/allowlist_proxy.py`.
- Take online SQLite backups (`sqlite3.Connection.backup()`) of `state.db`, `kanban.db`, `cron_executions.db`, `onboarding_receipts.sqlite3` + `PRAGMA integrity_check`.
- Archive `/opt/data/{config.yaml,webhook_subscriptions.json,workflow-designs,plugins/visual-workflow,joyumkm-analytics,joyumkm-onboarding,joyumkm-prediction,cron}` + record SHA-256 of all 7 workflow JSON files.

---

## 3. Component Rollback Procedures (Fail-Safe & Non-Destructive)

### 3.1 Hard Guardrails (NEVER Violate)
- **NEVER** run `docker volume rm hermes_data`, `docker compose down -v`, or `docker volume prune`.
- **NEVER** run `php artisan migrate:fresh` or `db:wipe` against `joyumkm-v2` (and never run `migrate:fresh --env=testing` outside PHPUnit!).
- **NEVER** overwrite `receipts.sqlite3` or `monitoring-dedupe.json` with older snapshots after live events have been processed (prevents duplicate emails / duplicate Telegram alerts!).
- **NEVER** delete or mutate immutable `intelligence_reports`, `intelligence_artifacts`, or `intelligence_deliveries` rows.

### 3.2 Phase-Specific Rollback Paths

| Phase | Trigger Condition | Safe Rollback Procedure |
|---|---|---|
| **P2 (Schema / RBAC)** | Migration failure, trigger breakage, or auth regression | 1. Halt traffic/workers. 2. Run `php artisan migrate:rollback --step=N` (ONLY if down migrations are verified safe & additive; note `six_factor_evaluations` & `email_snapshot` down guards). 3. If schema drift occurred, restore `joyumkm-v2` from pre-P2 verified `mysqldump` into clean DB and swap cleanly. 4. Checkout pre-P2 Git commit. |
| **P3 (458 Dataset Seeder)** | Seeder drift, collision with legacy records, or unexpected side effects | 1. Because P3 runs inside a single MySQL transaction with `GET_LOCK`, mid-run failures auto-rollback atomically. 2. Post-commit rollback: disable workers/monitoring, delete ONLY rows linked by `analytics_cohort_umkms` for the new cohort version (in FK order: `evaluation_answers` -> `evaluations` -> `six_factor_evaluations` -> `analytics_cohort_umkms` -> `umkms` -> `analytics_dataset_cohorts`) inside a verified transaction — **never** touching demo or operational rows — OR restore pre-P3 `mysqldump` if no operational writes occurred since backup. |
| **P4 / P5 (Backend APIs & Frontend UI)** | IDOR defect, broken assessment/report UI, or onboarding regression | 1. Keep `HERMES_WEBHOOK_ENABLED=false` and `HERMES_ONBOARDING_ENABLED=false`. 2. Revert application code to pre-P4/P5 commit and run `npm run build` + `php artisan config:clear`. 3. Additive P2 schema columns remain backward-compatible so no destructive DB downgrade is needed. |
| **P6 (Hermes Analytics Cutover & Monitoring)** | Analytics view error, RO grant leak, or monitoring alert anomaly | 1. Keep `workflow:umkm-monitoring` `paused`. 2. Restore `/opt/data/joyumkm-analytics/mysql.json` back to `joyumkm_p1_prod` RO credentials from pre-P6 backup. 3. Optionally `DROP VIEW` the 3 `joyumkm-v2.analytics_*` views and `REVOKE`/`DROP USER` the new `joyumkm-v2` RO account (`joyumkm_p1_prod` remains completely untouched as fallback!). 4. Follow `C:\laragon\investigation-docker\hermes-docker-consolidation\rollback.md` if Compose changes are ever touched. |
| **Emergency Live Outbound Kill-Switch** | Any suspected duplicate email, wrong recipient, or alert storm | 1. Set `HERMES_WEBHOOK_ENABLED=false` & `HERMES_ONBOARDING_ENABLED=false` in `.env` + `php artisan config:clear`. 2. Confirm Windows Scheduled Tasks `Disabled`. 3. Confirm Hermes `email.env` `ENABLED=0 / PAUSED=1` & all 3 Hermes crons `paused`. 4. Preserve all outbox/receipt/delivery records for forensic audit — never replay blindly! |
