# Evidence: Hermes Runtime, Docker & Host Automation Baseline

- **Evidence ID:** `EV-HERMES-001` (Runtime Closure Update)
- **Observed at:** `2026-11-27T19:02:27+07:00` – `2026-11-27T19:07:06+07:00` (`Asia/Jakarta`)
- **Live Docker Daemon & Compose Status:** **RUNNING (`jobsheet2 running(3)` — `hermes-agent`, `hermes-workflow-ingress`, `hermes-webhook-proxy`)**
- **Live Host Services:** Laragon Apache (`httpd.exe` PID `21288` on `:80`, `:443`) & MySQL 8.4.3 (`mysqld.exe` PID `33104` on `:3306`) **RUNNING**; **0 `php.exe` worker/scheduler processes running**

---

## 1. Live Safety Boundary & Listener Verification (`2026-11-27 19:02–19:07 WIB`)

| Check | Command / Source (Read-Only) | Observed Live Result | Safety Verdict |
|---|---|---|---|
| Windows Scheduled Tasks | `Get-ScheduledTask` (`JoyUMKM*`) | `JoyUMKM Hermes Webhook Worker`: **`Disabled`**; `JoyUMKM Laravel Scheduler`: **`Disabled`** | **SAFE** (No Windows cron/worker automation) |
| Active Host Processes | `Get-Process` + `netstat -ano` | `httpd.exe` (`:80,:443`), `mysqld.exe` (`:3306`), `com.docker.backend.exe` (`:8642,:8644,:8650,:8651,:9119`). **0 `php.exe` processes.** | **SAFE** |
| Hermes Cron Jobs | `docker exec hermes-agent hermes cron list` & `/opt/data/cron/jobs.json` | All **3** jobs **`[paused]` (`enabled=False`)**: `jmi-recover` (`5fa98346fe3a`, last run `2026-11-25`), `jmi-recover-prod` (`7371e7373803`, last run `2026-11-25`), `workflow:umkm-monitoring` (`04ca49ee0a72`, last run `2026-11-26 10:10 WIB`) | **SAFE** (No cron firing) |
| Telegram Gateway | `docker logs --tail 25 hermes-agent` | `2026-11-27 12:01:52 UTC`: `Connected to Telegram (polling mode)` | **ACTIVE** (Read-only polling; no `/analytics` triggered) |

---

## 2. Live Docker Containers & Security Posture (`docker ps -a`, `docker inspect`)

| Container Name | Image | Live Status | Ports | Safe Security Fields & Mounts |
|---|---|---|---|---|
| `hermes-agent` | `d6746a084e93` | `Up` (started `12:01:21Z`, `restarts=0`) | `127.0.0.1:8642`, `127.0.0.1:9119` | `user=root`, `ro=false`, `cap_drop=[]`, **0 `docker.sock` mount**; mounts `hermes_data->/opt/data`, `C:/laragon/www->/workspace/laragon:ro`, `C:/laragon/www/joyumkm->/workspace/laragon/joyumkm:rw` |
| `hermes-workflow-ingress` | `d6746a084e93` | `Up` (started `12:01:21Z`, `restarts=0`) | `127.0.0.1:8650`, `127.0.0.1:8651` | **`user=10000:10000`, `ro=true`, `cap_drop=[ALL]`**; 6 scoped `hermes_data` subpath mounts (`workflow-designs:ro`, `workflow-runs:rw`, `plugins/visual-workflow:ro`, `email.env:ro`, `joyumkm-onboarding:rw`, `joyumkm-prediction:ro`) |
| `hermes-webhook-proxy` | `d6746a084e93` | `Up` (started `12:01:21Z`, `restarts=0`) | `127.0.0.1:8644` | `user=root`, `ro=false`; mounts `docs/deployment/hermes-proxy->/proxy:ro` |
| `dokumen-kita-redis`, `redis` | `redis:*` | `Exited (255)` (8d / 8w ago) | — | Out of scope (untouched) |

---

## 3. Live Endpoint, Workflow, Subscription & State Inspection

### 3.1 Read-Only Endpoint & Socket Probes
- `GET http://127.0.0.1:9119/api/health` → **`HTTP 200`** (`{"ok":true,"version":"0.23.2","commit":"ae2a81cc...","auth_required":true}`)
- `GET http://127.0.0.1:8650/health` → **`HTTP 200`** (`{"status": "ok"}`)
- `GET http://127.0.0.1:8651/webhook/predict` → **`HTTP 405`** (server active; GET rejected without running workflow)
- `GET http://127.0.0.1:8644/health` → **`HTTP 404`** (proxy active; enforces allowlist)
- `hermes-webhook-proxy` → `http://hermes-agent:8644/health` → **`HTTP 200`** (`{"status": "ok", "platform": "webhook"}`)
- Inside `hermes-agent` (`socket.connect_ex`): `8642` listening (`connect_ex=0` on `127.0.0.1` & `0.0.0.0`? Wait: both `127.0.0.1:8642` and `0.0.0.0:8642` returned `0` — note `0.0.0.0` in Linux `connect_ex` connects to loopback! Container host-port probe not needed since `:8642` is out of JoyUMKM scope), `8644` (`connect_ex=0`), `9119` (`connect_ex=0`).

### 3.2 Live Visual Workflow Registry (`/opt/data/workflow-designs/`, `7/7` Valid)

| Filename | Workflow Name | Nodes | `validate_designer_graph()` | Trigger Summary | SHA-256 (12-char) |
|---|---|---:|---|---|---|
| `jobsheet3-agent-dryrun.json` | `jobsheet3-agent-dryrun` | 5 | `True` (`errs=[]`) | `manual` | `f42b4e2ba730` |
| `jobsheet3-email.json` | `jobsheet3-email` | 5 | `True` (`errs=[]`) | `manual` | `bea9982668fc` |
| `joyumkm-user-onboarding.json` | `joyumkm-user-onboarding` | 4 | `True` (`errs=[]`) | `webhook` (`user.registered`) | `7c716aaf83d2` |
| `uji-persisten.json` | `uji-persisten` | 3 | `True` (`errs=[]`) | `manual` | `55b47e497210` |
| `umkm-analytics-assistant.json` | `umkm-analytics-assistant` | 9 | `True` (`errs=[]`) | `telegram` | `be35bfc7d3de` |
| `umkm-monitoring.json` | `umkm-monitoring` | 7 | `True` (`errs=[]`) | `schedule` (`*/10 * * * *`, `Asia/Jakarta`) | `3972bf899697` |
| `umkm-predict.json` | `umkm-predict` | 5 | `True` (`errs=[]`) | `webhook` (`POST /predict`, `active=True`, `synchronous=True`) | `4a8c37a19635` |

- **Workflow Runs & Dedupe State:** `/opt/data/workflow-runs/` has `83` run JSON files; `monitoring-dedupe.json` has `2` entries.

### 3.3 Live Webhook Subscriptions, Onboarding, JMI & Analytics Configs (Secrets Redacted)

- **`/opt/data/webhook_subscriptions.json`:**
  - `joyumkm-events`: `enabled=true`, `events=["evaluation.finalized"]`, `deliver="log"`, `signature="generic"`, `script="joyumkm_intake_prod.py"`, `cron_job="7371e7373803"` (`jmi-recover-prod`), `has_secret=true`.
  - `joyumkm-events-dev`: `enabled=true`, `events=["evaluation.finalized"]`, `deliver="log"`, `signature="generic"`, `script="joyumkm_intake.py"`, `cron_job="5fa98346fe3a"` (`jmi-recover`), `has_secret=true`.
- **`/opt/data/joyumkm-onboarding/`:**
  - `settings.json`: `enabled=True`, `mode="live"`, `app_url="http://127.0.0.1:8013/home"`, `allowlist_count=1`, `secret_len=64`.
  - `receipts.sqlite3`: `total=3`, **`pending=0`**, `smtp_started=2`.
- **`/opt/data/joyumkm-intelligence/state/`:**
  - **`email.env` (LIVE DISCREPANCY vs P6 HANDOFF):** `JMI_EMAIL_DELIVERY_ENABLED="1"`, `JMI_EMAIL_DELIVERY_PAUSED="0"` (P6 handoff claimed `ENABLED=0 / PAUSED=1`; safe currently ONLY because `jmi-recover*` crons are paused and `uat-scope.json` is absent, with `19` job dirs: `17 completed, 2 failed, 0 active`).
  - `delivery-status.env`: `JMI_EMAIL_STATUS_SYNC_ENABLED="0"`, `JMI_EMAIL_STATUS_SYNC_PAUSED="1"`.
  - `snapshot.env`, `callback.env`, `artifact.env`, `recipient.env`: all target `http://host.docker.internal` with `HTTP_HOST="joyumkm.test"`; `uat-scope.json` exists = `False`.
- **`/opt/data/joyumkm-analytics/mysql.json`:** `host="host.docker.internal"`, `port=3306`, `database="joyumkm_p1_prod"`, `user="joyumkm_analytics_ro"`, `ssl_ca="/opt/data/joyumkm-analytics/mysql-ca.pem"`, `ssl_verify_identity=false`, `has_password=true`.
