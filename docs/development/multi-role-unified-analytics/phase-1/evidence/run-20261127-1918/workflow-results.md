# Evidence: P1-06 Hermes Workflow, Prediction, Monitoring & Analytics Results (`run-20261127-1918`)

- **Evidence ID:** `EV-P1-WORKFLOW-001`
- **Observed At:** `2026-11-27T19:29:41+07:00` – `19:58:30+07:00` (`Asia/Jakarta`)

---

## 1. Visual Workflow Plugin `pytest` Suites (`hermes-agent`)

Using cached `pytest 9.2.0` (`PYTHONPATH=/opt/data/cache/uv/archive-v0/uVLuF8MF9kEvSWeA/lib/python3.14/site-packages`, zero package installs):

| Test Selection | Passed | Failed / Error | Subtests | Notes / Classification |
|---|---:|---:|---:|---|
| `test_mysql.py`, `test_analytics_workflow.py`, `test_analytics_telegram.py`, `test_prediction.py`, `test_monitoring.py`, `test_monitoring_cron.py` (`HERMES_ANALYTICS_TEST_LIVE=1`) | **83** | **3 failed** | 6 | All 3 failures are in `test_analytics_workflow.py` (`test_ambiguity_discards_generated_sql`, `test_aggregate_with_zero_observations_is_no_data`, `test_formatting_preserves_sql_decimal_and_ignores_invented_number`) where legacy plain-text expectations differ from newer Telegram HTML formatting (`<b>Klarifikasi Diperlukan</b>`, `Observasi: 2`). `KNOWN_BASELINE_FAILURE`. |
| `test_analytics_presentation.py`, `test_monitoring_telegram.py`, `test_register.py`, `test_delivery.py` (`HERMES_ANALYTICS_TEST_LIVE=1`) | **37** | **0** | — | **PASS** |
| `test_monitoring.py`, `test_monitoring_cron.py`, `test_monitoring_telegram.py` (standalone) | **50** | **0** | 6 | **PASS** (`T1-17` isolated fixture classification, dedupe, Telegram payload formatting) |
| `test_schema.py`, `test_convert.py`, `test_runner.py`, `test_store.py`, `test_commands.py`, `test_triggers.py`, `test_hooks.py`, `test_models.py`, `test_channels.py`, `test_schedule.py` | **200** | **9 errors** | — | All 9 errors are in `test_commands.py` (`FakeCtx` lacks `register_auxiliary_task` added by `analytics_workflow.py`). `KNOWN_BASELINE_FAILURE`. |

---

## 2. Live Read-Only / Stateless Functional Probes

### 2.1 `umkm-predict` (`T1-16` — Laravel `HermesPredictionService::predict()` → `POST :8651/webhook/predict`)
- `min_6` (`[1,1,1,1,1,1]`): `total=6`, `category="Kurang Sehat"`, matches `SixFactorEvaluation::categoryForTotal(6)` = `YES`
- `boundary_12` (`[2,2,2,2,2,2]`): `total=12`, `category="Kurang Sehat"`, matches = `YES`
- `boundary_13` (`[3,2,2,2,2,2]`): `total=13`, `category="Cukup Sehat"`, matches = `YES`
- `boundary_19` (`[4,3,3,3,3,3]`): `total=19`, `category="Sehat"`, matches = `YES`
| `max_30` (`[5,5,5,5,5,5]`): `total=30`, `category="Sangat Sehat"`, matches = `YES`
- `invalid_6` (`[6,3,3,3,3,3]`): rejected with `ValidationException` (`422`)
- Zero DB writes to `joyumkm-v2`; `+5` stateless run files recorded in `/opt/data/workflow-runs/` (`87 -> 92`).

### 2.2 `umkm-monitoring` (`T1-17` — Read-Only `SixFactorMonitoringController` on `joyumkm-v2`)
- Executed inside `SET SESSION TRANSACTION READ ONLY; START TRANSACTION READ ONLY; ... ROLLBACK;`:
  - `meta`: `{"count": 100, "truncated": true}`
  - `non_synthetic_count`: **`28`** (`1` with `total_skor < 13`)
  - `synthetic_count`: **`72`** (`user#7` IDs `29..100`, **`26`** with `total_skor < 13`)
  - `first_non_synth_id`: `1`, `last_returned_id`: `100` (`356` synthetic rows & `id > 456` crowded out)
- **RSK-06 Reproduced Live.** `workflow:umkm-monitoring` cron kept `[paused]`; `0` Telegram alerts sent.

### 2.3 `umkm-analytics-assistant` (`T1-18` — Live RO MySQL over TLS + AST Validator)
- Executed via `visual_workflow_hermes.mysql.execute_query()` using `joyumkm_analytics_ro@host.docker.internal` + `mysql-ca.pem` against **`joyumkm_p1_prod`**:
  - `analytics_umkms`: **`428`** (`Makanan & Minuman=120`, `Grosir & Retail=100`, `Jasa=85`, `Fashion & Kriya=75`, `Pertanian=48`)
  - `analytics_evaluations`: **`1,284`**
  - `analytics_six_factor_evaluations`: **`1,284`** (`107/month` across `2025-11`..`2026-10`; monthly avg `16.99`..`19.00`; top cities `Tangerang Selatan 19.02`, `Bandung 19.00`, `Jakarta Selatan 18.57`, `Jakarta Timur 18.22`, `Malang 17.86`)
- Negative AST/MySQL-grant tests (`select_users`, `write_delete`, `multi_stmt`, `info_schema`, `subquery`, `unlisted_col`, and direct `execute_query("SELECT COUNT(*) AS cnt FROM users LIMIT 1")`): **7 / 7 `REJECTED:MysqlError`**.
- **Limitation (`RSK-12`):** Live Telegram `/analytics` LLM invocation `NOT RUN` (requires operator approval & AI quota).

### 2.4 Manual Workflows (`T1-20`)
- `uji-persisten.json` (`sha256: 55b47e497210...`): `validate_designer_graph() = []`; executed in memory via `runner.run_plan()` -> `run_status=done`, `nodes=1` (0 writes to `/opt/data/workflow-runs/`).
- `jobsheet3-email.json` (`bea9982668fc...`) & `jobsheet3-agent-dryrun.json` (`f42b4e2ba730...`): `validate_designer_graph() = []` (structural PASS; functional execution `NOT RUN` in Run 1).
