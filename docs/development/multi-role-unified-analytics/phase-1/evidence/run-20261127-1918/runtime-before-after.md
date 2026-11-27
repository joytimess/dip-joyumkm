# Evidence: Runtime Before/After Comparison (`run-20261127-1918`)

- **Evidence ID:** `EV-P1-RUNTIME-001`
- **Before Baseline (P0 Anchor):** `2026-11-27T19:02:03+07:00` – `19:12:00+07:00` (`Asia/Jakarta`)
- **P1 Run 1 Window:** `2026-11-27T19:18:00+07:00` – `20:03:00+07:00` (`Asia/Jakarta`)
- **Mode:** Read-only inspection + isolated `joyumkm_testing` MySQL/Playwright + read-only Hermes analytics + stateless `:8651` prediction verification (no live email, no `joyumkm-v2` mutation)

---

## 1. Host, Git, Database & Hermes State Comparison

| Dimension | Before (P0 Closure / Run 1 Start) | After Run 1 (`2026-11-27 20:02 WIB`) | Drift Classification |
|---|---|---|---|
| **Git Branch & `HEAD`** | `main` @ `aaa05da4a1d88fa72ec95401c6ab6d80a35213f7` | `main` @ `aaa05da4a1d88fa72ec95401c6ab6d80a35213f7` | **Unchanged** (0 tracked file diffs; new `phase-1/` docs only) |
| **Laragon MySQL (`127.0.0.1:3306`)** | Online (`8.4.3`) | Online (`8.4.3`) | **Unchanged** |
| **`joyumkm-v2` Schema & Rows** | `28` tables, `0` views, `6` triggers, `22` migrations; `7` users, `456` UMKM (`428` cohort + `28` non-cohort), `1,339` 3D, `21,386` ans, `1,316` 6F, `24` outbox (`2` pending `#23/#24`), `2` jobs (`#30/#31`), `10` reports, `12` artifacts, `1` delivery | Identical (`7` users, `456` UMKM, `1,339` 3D, `21,386` ans, `1,316` 6F, `24` outbox `#23/#24` pending, `2` jobs `#30/#31`, `10` reports, `12` artifacts, `1` delivery) | **Zero Mutation** |
| **`joyumkm_p1_prod`** | `24` tables, `3` views (`428 / 1,284 / 1,284`), `1` user, `428` UMKM | Identical (`428 / 1,284 / 1,284`) | **Zero Mutation** |
| **`joyumkm_testing`** | `24` tables, `22` migrations, `0` users, `0` UMKM | `24` tables, `22` migrations, `0` users, `0` UMKM (reset cleanly after PHPUnit & Playwright) | **Clean** |
| **Windows Scheduled Tasks** | `JoyUMKM Hermes Webhook Worker`: `Disabled`; `JoyUMKM Laravel Scheduler`: `Disabled` | Both `Disabled` | **Unchanged (Safe)** |
| **Host `php.exe` Workers** | `0` persistent workers | `0` persistent workers (`0` queue/schedule processes) | **Unchanged (Safe)** |
| **Docker Compose (`jobsheet2`)** | `running(3)` (`hermes-agent`, `hermes-workflow-ingress`, `hermes-webhook-proxy`) | `running(3)` (0 restarts, all healthy) | **Unchanged** |
| **Hermes Cron Jobs** | `3/3` `[paused]` (`jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring`) | `3/3` `[paused]` | **Unchanged (Safe)** |
| **Hermes Visual Workflows (7 JSON SHA-256)** | All `7` valid | All `7` SHA-256 hashes identical (`f42b4e2ba730...`, `bea9982668fc...`, `7c716aaf83d2...`, `55b47e497210...`, `be35bfc7d3de...`, `3972bf899697...`, `4a8c37a19635...`) | **Unchanged** |
| **Hermes `workflow-runs/`** | `83` run files (at P0; `87` before `:8651` test) | `92` run files (`+5` stateless `umkm-predict` boundary runs from `T1-16` verification) | **Expected P1-06 Prediction Baseline Delta** |
| **Hermes JMI `state/jobs/`** | `19` dirs (`17 completed`, `2 failed`) | `19` dirs (`17 completed`, `2 failed`, `0` new) | **Unchanged** |
| **Hermes Onboarding `receipts.sqlite3`** | `3` rows (`0` pending, `2` `smtp_started`) | `3` rows (`0` pending, `2` `smtp_started`) | **Unchanged** |
| **Config / Flag Files (`.env`, `email.env`, `delivery-status.env`)** | `HERMES_WEBHOOK_ENABLED=true`, `HERMES_ONBOARDING_*` absent, `email.env` `1/0`, `delivery-status.env` `0/1`, `uat-scope.json` absent | Identical (zero flag modifications in Run 1) | **Unchanged** |
