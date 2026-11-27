# Evidence: Step 1 — Runtime Preflight Safety Verification (Run 2)

- **Run:** `run2-20261127-2015`
- **Recorded at:** 2026-11-27 20:21 WIB (`+07:00`), baseline reads taken 20:04–20:21 WIB
- **Baseline:** `run-20261127-1918` (Run 1) and `P1-FINAL-HANDOFF.md`

## 1. Result Summary

| Check | Run 1 expectation | Observed at Run 2 start | Result |
|---|---|---|---|
| MySQL reachable (`127.0.0.1:3306`) | Available | Available (root login via environment secret, not printed) | PASS |
| Hermes containers | 3 running | `hermes-agent`, `hermes-workflow-ingress`, `hermes-webhook-proxy` — Up | PASS |
| Backup checksums | Valid | `joyumkm-v2.sql` and `joyumkm_p1_prod.sql` match `SHA256SUMS.txt` | PASS |
| Pending outbox `#23/#24` | Untouched, `pending` | `pending`, `attempts=0`, `queued_job_id` 30/31 | PASS |
| Laravel jobs `#30/#31` | Untouched, `hermes-webhooks` | Present, `attempts=0`, `reserved_at` NULL | PASS |
| Failed JMI jobs (`3dba65ad…`, `6e05a476…`) | Untouched | Both `failed`, `retryable=False` (Hermes side) | PASS |
| General queue worker | None | 0 `queue:work` processes; only dev `php artisan serve` (PID 26336) and unrelated `flow-ugc` server | PASS |
| Windows Scheduled Tasks | Disabled | `JoyUMKM Hermes Webhook Worker` = Disabled; `JoyUMKM Laravel Scheduler` = Disabled | PASS |
| Hermes cron (3 jobs) | Paused | `jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring` = `paused` | PASS |
| Docker containers created | None | No new container created in Run 2 | PASS |
| Source code changes | None | `git status`: only untracked `docs/` folders (pre-existing); no tracked file modified by code work | PASS |
| UMKM population | 456 (428 cohort + 28 non-cohort) | 456 before Track C | PASS |
| Users | 7 | 7 before Track C | PASS |

## 2. Runtime Configuration Identified

| Item | Value (non-secret) | Source |
|---|---|---|
| Laravel `APP_ENV` | `local` | `.env` |
| Laravel DB | `127.0.0.1:3306` `joyumkm-v2` | `.env` |
| Laravel `QUEUE_CONNECTION` | `database` | `.env` |
| Laravel `MAIL_MAILER` | `smtp` | `.env` |
| Laravel `HERMES_WEBHOOK_ENABLED` | `true` | `.env` |
| Laravel `HERMES_WEBHOOK_URL` host | `127.0.0.1:8644` (proxy) → dev route | `.env` (URL path not reproduced with secrets) |
| Laravel `HERMES_WEBHOOK_UAT_SCOPE_ENABLED` | absent (= `false`) before Track C | `.env` |
| Laravel `HERMES_ONBOARDING_*` | absent before Track C | `.env` |
| Laravel `OPENWA_ENABLED` | `true` before Track C (remote gateway) | `.env` |
| Hermes `email.env` | `JMI_EMAIL_DELIVERY_ENABLED=1`, `JMI_EMAIL_DELIVERY_PAUSED=0` | Hermes state |
| Hermes `delivery-status.env` | `JMI_EMAIL_STATUS_SYNC_ENABLED=0`, `…_PAUSED=1` before Track C | Hermes state |
| Hermes `dev-operation.env` | `JMI_DEV_OPERATION_MODE=normal` | Hermes state |
| Hermes `uat-scope.json` | Absent before Track C | Hermes state |
| Ingress container env | `JOYUMKM_ONBOARDING_LIVE_ENABLED=1`, `JOYUMKM_ONBOARDING_LIVE_RECIPIENT` equals the authorized mailbox | container env |
| Onboarding `settings.json` | `enabled=true`, `mode=live`, `allowlist` has exactly 1 entry = the authorized mailbox | Hermes onboarding state (file mtime 2026-11-25 18:50, predates Track C) |

**Observation (not a Run 2 change):** the Run 1 handoff recorded the onboarding allowlist address as redacted (`[REDACTED:1_ALLOWLISTED_ADDRESS]`). At Run 2 start the allowlist and `JOYUMKM_ONBOARDING_LIVE_RECIPIENT` both already equal the operator-authorized mailbox. The file predates this run, so the value was set before Track C. This does not change the authorized scope, and it is recorded here so the discrepancy with Run 1 is visible.

**Observation:** `APP_URL` in the JoyUMKM `.env` is `http://localhost:8000`, but port 8000 is served by an unrelated `flow-ugc` project. JoyUMKM is served by Apache at `http://joyumkm.test` (port 80). A first registration attempt was sent to `localhost:8000` by mistake and returned `404` from `flow-ugc`; it created nothing in JoyUMKM (users stayed at 7 until the correct registration). All later requests used `joyumkm.test`.

## 3. Hermes Scheduling and Process State

- Hermes JMI job records (`state/jobs/`, 19 directories) are all terminal: `completed` (17) or `failed` with `retryable=False` (2: `ANALYSIS_UNKNOWN`, one with corrective `halted_ambiguous`). No `pending`, `processing` or retryable job existed, so nothing could be resumed by a restart or recovery cron.
- No `jmi process` worker was running before Track C.

## 4. Verdict

Preflight **PASS**. Live execution was allowed to proceed because no runtime change endangered UAT isolation. The mode and gate findings are carried into `scoped-dispatch-verification.md`.
