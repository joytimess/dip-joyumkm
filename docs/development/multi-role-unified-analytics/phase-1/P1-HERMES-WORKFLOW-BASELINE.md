# P1-06 — Hermes Workflow Compatibility & Recovery Baseline

- **Gate Status (`G1-07`):** **`PARTIAL`** *(5/7 Visual Workflows have direct read-only/isolated/stateless functional evidence; `joyumkm-events` & `joyumkm-user-onboarding` live E2E `BLOCKED` pending Track C approval; RSK-06 reproduced live; RSK-12 live Telegram `/analytics` LLM `NOT RUN`)*
- **Gate Status (`G1-08`):** **`PARTIAL`** *(Isolated Laravel `273/274` & JMI `213/214` passed with 0 unexpected SMTP sends; live UAT duplicate/status-only retry `BLOCKED` pending Track C)*
- **Evidence:** [workflow-results.md](evidence/run-20261127-1918/workflow-results.md) · [retry-idempotency-results.md](evidence/run-20261127-1918/retry-idempotency-results.md)

---

## 1. Per-Workflow Baseline Matrix (All 7 Visual Workflows + Intelligence Pipeline)

| # | Workflow / Integration | Structural Check (SHA-256) | Functional Verification Method | Observed Result | Limitation / Gate Status |
|---|---|---|---|---|---|
| 1 | **`joyumkm-events(-dev)` (Intelligence Pipeline)** | `allowlist_proxy.py` + `joyumkm_intake.py` + `jmi` (`17 completed, 2 failed`) | Laravel A4 (`273/274`) + JMI `pytest` (`213/214` on recovery/delivery/email/sync; `test_email_status_sync` `1` failure returning `blocked`) | Isolated intake/callback/artifact/email/status-sync contracts PASS; `0` live sends | **Live UAT `BLOCKED`** (Track C approval required) |
| 2 | **`joyumkm-user-onboarding`** (`joyumkm-user-onboarding.json`) | `valid=True` (`7c716aaf83d2...`) | Laravel `AuthApiTest` (`17/17`) + `test_register.py` + `receipts.sqlite3` (`3` rows, `0` pending) | Registration/HMAC/receipt contracts PASS; `.env` `HERMES_ONBOARDING_*` unset | **Live UAT `BLOCKED`** (Track C approval required) |
| 3 | **`umkm-predict`** (`umkm-predict.json`) | `valid=True` (`4a8c37a19635...`) | Live `HermesPredictionService::predict()` → `POST :8651/webhook/predict` (`5` boundaries + invalid) + `test_prediction.py` | **PASS** — `min_6`, `12`, `13`, `19`, `max_30` matched `SixFactorEvaluation::categoryForTotal()`; invalid rejected `422`; `0` DB writes (`+5` `workflow-runs/`) | Verified live stateless (`T1-16`) |
| 4 | **`umkm-monitoring`** (`umkm-monitoring.json`) | `valid=True` (`3972bf899697...`), cron `[paused]` | Read-only `SixFactorMonitoringController` on `joyumkm-v2` + `test_monitoring*.py` (`50/50` PASS, `6` subtests) | **RSK-06 REPRODUCED LIVE:** `count=100`, `truncated=true`, **`28` non-synth (`1 < 13`) + `72` synth `user#7` (`26 < 13`)**; `0` Telegram alerts | **Operational safety `BLOCKED` until P2 fix** (`T1-17`) |
| 5 | **`umkm-analytics-assistant`** (`umkm-analytics-assistant.json`) | `valid=True` (`be35bfc7d3de...`) | Live RO MySQL TLS queries on `joyumkm_p1_prod` (`execute_query`) + AST/grant negative checks + plugin `pytest` | **RO Queries PASS:** `428 / 1,284 / 1,284`, `12` months, `5` sectors, top cities; **`7/7` unsafe SQL/table checks REJECTED**; `3` pre-existing `test_analytics_workflow.py` formatting failures (`KNOWN_BASELINE_FAILURE`) | **`RSK-12` Live Telegram `/analytics` LLM `NOT RUN`** (`T1-18`) |
| 6 | **`uji-persisten`** (`uji-persisten.json`) | `valid=True` (`55b47e497210...`) | In-memory `runner.run_plan()` (`0` `/opt/data/workflow-runs/` writes) | **PASS** (`run_status=done`, `nodes=1`) | Verified isolated |
| 7 | **`jobsheet3-email`** (`jobsheet3-email.json`) | `valid=True` (`bea9982668fc...`) | `validate_designer_graph()` + `DesignStore.load()` | Structural **PASS**; manual functional execution `NOT RUN` | Legacy manual workflow |
| 8 | **`jobsheet3-agent-dryrun`** (`jobsheet3-agent-dryrun.json`) | `valid=True` (`f42b4e2ba730...`) | `validate_designer_graph()` + `DesignStore.load()` | Structural **PASS**; manual functional execution `NOT RUN` | Legacy manual workflow |

---

## 2. Safety & Automation Preservation Confirmed

- `3/3` Hermes crons (`jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring`) remained `[paused]` throughout Run 1.
- `2/2` Windows Scheduled Tasks remained `Disabled`; `0` new containers created; `0` workflow JSON definitions modified (all `7` SHA-256 hashes match P0).
