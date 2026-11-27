# P1-01 — Runtime Safety & Backup Report

- **Gate Status (`G1-01`):** **`PASS`** *(Runtime safety verified; fresh restricted local backup captured; restore rehearsal marked `NOT RUN` pending operator schema authorization; Track C preflight decision sheet prepared)*
- **Execution Window:** `2026-11-27T19:18:00+07:00` – `20:06:00+07:00` (`Asia/Jakarta`)
- **Evidence:** [runtime-before-after.md](evidence/run-20261127-1918/runtime-before-after.md) · [backup-metadata.md](evidence/run-20261127-1918/backup-metadata.md) · [preflight-and-authorization.md](evidence/run-20261127-1918/preflight-and-authorization.md)

---

## 1. Repository, Environment & Safety Boundary Verification

- **Git Baseline:** Branch `main`, `HEAD` `aaa05da4a1d88fa72ec95401c6ab6d80a35213f7` (unchanged from P0), zero tracked modifications (`git status --short` shows only untracked `docs/prd/Enhanced Superadmin-Analyst-Owner/` and `docs/development/`).
- **Config Cache & Effective DBs:** `app()->configurationIsCached() === false`; normal Laravel app targets `mysql` `127.0.0.1:3306` `joyumkm-v2`; `.env.testing` is **absent** (CLI `migrate:fresh --env=testing` outside PHPUnit would target `joyumkm-v2`, strictly prohibited).
- **PHPUnit Isolation Proven:** `phpunit.xml` sets `DB_CONNECTION=mysql` and `DB_DATABASE=joyumkm_testing` with `force="true"`, plus `tests/TestCase.php:21-24` throws `RuntimeException` if effective DB is not `mysql` / `joyumkm_testing`. Verified `joyumkm_testing` returns to `0` users and `0` UMKM after PHPUnit and Playwright cleanup.
- **Automation & Workers:** Windows Scheduled Tasks `JoyUMKM Hermes Webhook Worker` and `JoyUMKM Laravel Scheduler` are both **`Disabled`**; `0` Laravel worker/scheduler `php.exe` processes running; all `3/3` Hermes crons (`jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring`) are **`[paused]`**; Docker Compose `jobsheet2` is **`running(3)`** (`hermes-agent`, `hermes-workflow-ingress`, `hermes-webhook-proxy`).

---

## 2. Backup & Restore Verification Status

- **Fresh Backup Captured (`2026-11-27 19:26:11 WIB`):** `C:\laragon\backups\multi-role-pre-p1-20261127-192611\` (NTFS ACL restricted via `icacls` to current user, `SYSTEM`, and `Administrators`).
- **Contents:** `joyumkm-v2.sql` (`1,991,686 B`, `28` `CREATE TABLE`, `Dump completed`), `joyumkm_p1_prod.sql` (`1,752,388 B`, `3` views), `laravel.env.bak` (`3,833 B`), `intelligence_artifacts\` (`12` files across `6` event folders), `hermes_state_snapshot.tar.gz` (`21,578,443 B` with online SQLite backups + workflows + cron + state configs), and `SHA256SUMS.txt`.
- **Restore Rehearsal:** **`NOT RUN`** — no disposable restore-rehearsal schema was pre-authorized in P1. Proposed target `joyumkm_p1_restore_rehearsal` is included in [preflight-and-authorization.md](evidence/run-20261127-1918/preflight-and-authorization.md) (`D6`).
