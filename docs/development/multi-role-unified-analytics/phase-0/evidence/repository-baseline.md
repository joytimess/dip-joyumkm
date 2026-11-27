# Evidence: Repository & Workspace Baseline

- **Evidence ID:** `EV-REPO-001`
- **Observed at:** `2026-11-27T18:35:20+07:00` (`Asia/Jakarta`)
- **Scope / Environment:** Local workspace `C:\laragon\www\joyumkm` (Windows 11, Laragon PHP 8.3.36 CLI)
- **Verification Mode:** Strictly read-only (Git status/ref/log inspection, filesystem inventory, sanitized `.env` key-presence check)

---

## 1. Git Identity & Working-Tree Snapshot (Pre-Audit)

| Field | Observed Value | Status |
|---|---|---|
| Local Path | `C:\laragon\www\joyumkm` | Verified (Live) |
| Remote (`origin`) | `https://github.com/joytimess/dip-joyumkm.git` (fetch & push) | Verified (Live) |
| Active Branch | `main` | Verified (Live) |
| Local `HEAD` SHA | `aaa05da4a1d88fa72ec95401c6ab6d80a35213f7` (`aaa05da`) | Verified (Live) |
| Local `origin/main` Ref | `aaa05da4a1d88fa72ec95401c6ab6d80a35213f7` (matches `HEAD`; no `git fetch` executed) | Verified (Local Ref) |
| `HEAD` Commit Date & Subject | `2026-11-26T22:56:41+07:00` — `docs(analytics): add jobsheet 6 SQL views and verification evidence` | Verified (Live) |
| Initial `git status --short` | `?? "docs/prd/Enhanced Superadmin-Analyst-Owner/"` (0 modified tracked files, 0 staged files) | Verified (Live) |

### Recent 10 Commits on `main`

```text
aaa05da docs(analytics): add jobsheet 6 SQL views and verification evidence
3778ab6 docs(analytics): add P1-P10 PRDs and dataset seeder runbook
94ff72a feat(analytics): add deterministic synthetic analytics dataset seeder and exporter
e614a39 feat(monitoring): add internal six-factor latest finals endpoint
46e1310 feat(evaluation): add Hermes six-factor health prediction endpoint and UI
58e2ea1 refactor(evaluation): extract categoryForTotal helper on SixFactorEvaluation
c009b2d feat(auth): queue signed Hermes onboarding webhooks on registration
c797742 refactor(intelligence): remove email test allowlist and test mode
6c1b229 chore(ignored)
010d0f8 docs(presentation): add DIP JoyUMKM presentation materials
```

---

## 2. Graphify Artifact Availability & Freshness

- **Directory:** `graphify-out/` — **Present** (not rebuilt or modified during P0).
- **Build State (`graphify-out/.graphify_build_state.json`):** `epoch: v0.9.104-s40-g5779fce85d4e`, `graph.json` size `5,126,559` bytes, last write `2026-11-26 11:08:38 WIB`.
- **Report Summary (`graphify-out/GRAPH_REPORT.md:1-16`):** 514 files, 4,646 nodes, 9,640 edges, 296 communities.
- **Built from Commit:** `c009b2dd` (`c009b2d`).
- **Freshness Verdict:** **STALE (6 commits behind `HEAD` `aaa05da`)**. Missing from graph:
  - `58e2ea1` (`SixFactorEvaluation::categoryForTotal`)
  - `46e1310` (Hermes six-factor prediction endpoint & UI)
  - `e614a39` (Internal six-factor monitoring endpoint `/api/internal/monitoring/six-factor/latest-finals`)
  - `94ff72a` (Deterministic synthetic analytics dataset seeder, planner, verifier, exporter, `analytics_dataset_cohorts`)
  - `3778ab6` (P1–P10 analytics PRDs & runbook)
  - `aaa05da` (Jobsheet 6 SQL views & verification evidence)
- **Action Taken:** None (rebuild prohibited in P0). All dependency findings in P0 were verified directly against live source code.

---

## 3. Runtime Stack & Package Versions (`composer.json`, `package.json`)

| Layer | Package / Runtime | Observed Version | Source |
|---|---|---|---|
| PHP CLI | PHP (NTS Visual C++ 2019 x64) | `8.3.36` | `php -v` |
| Framework | `laravel/framework` | `^13.17` | `composer.json:10` |
| Auth | `laravel/sanctum` | `^4.0` | `composer.json:11` |
| Testing | `phpunit/phpunit` | `^12.5.12` | `composer.json:22` |
| Formatting | `laravel/pint` | `^1.27` | `composer.json:19` |
| RBAC Packages | `spatie/laravel-permission`, Breeze, Fortify, Filament | **Absent** | `composer.json:8-23` |
| Frontend Build | `vite`, `laravel-vite-plugin`, `tailwindcss`, `@tailwindcss/vite` | `^8.0.0`, `^3.1`, `^4.0.0` | `package.json:11-15` |
| Frontend Libs | `apexcharts`, `leaflet`, `@fontsource/poppins` | `^8.7.0`, `1.9.4`, `^5.3.0` | `package.json:20-24` |

---

## 4. Sanitized `.env` Configuration Presence Check (No Secrets Disclosed)

Checked via key-presence & strict non-secret allowlist inspection of `.env` (compared against `.env.example` and `config/services.php`):

| Key | Observed State / Safe Value | Notes |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` / `APP_URL` / `APP_LOCALE` | `local` / `true` / `http://localhost:8000` / `id` | Local dev mode |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` | `mysql` / `127.0.0.1` / `3306` / `joyumkm-v2` | Single local app DB target |
| `DB_USERNAME` / `DB_PASSWORD` | `[REDACTED:SET]` / `[REDACTED:SET]` | Present |
| `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` | `database` / `file` / `file` | DB queue active in config |
| `MAIL_MAILER` / `MAIL_SCHEME` / `MAIL_PORT` | `smtp` / `smtps` / `465` | Mail credentials `[REDACTED:SET]` |
| `DEMO_PASSWORD` / `SEED_PASSWORD` / `SEED_PROFILE` | `[REDACTED:SET]` / `[REDACTED:SET]` / `small` | Local seeders armed for `db:seed` |
| `OPENWA_ENABLED` / `OPENWA_*` | `[REDACTED:SET]` | Present in `.env` |
| `HERMES_WEBHOOK_ENABLED` | `true` | **Note:** `true` in `.env`, whereas historical P6 handoff reported `false` after UAT; Windows workers/scheduler are Disabled |
| `HERMES_WEBHOOK_URL` / `HERMES_WEBHOOK_ALLOWED_HOST` | `http://127.0.0.1:8644/webhooks/joyumkm-events-dev` / `127.0.0.1` | Targets dev route via proxy |
| `HERMES_WEBHOOK_SECRET` | `[REDACTED:SET]` | Present |
| `HERMES_INTELLIGENCE_CALLBACK_SECRET` (+ tolerance `300`) | `[REDACTED:SET]` | Present |
| `HERMES_INTELLIGENCE_SNAPSHOT_SECRET` (+ tolerance `300`) | `[REDACTED:SET]` | Present (shared with monitoring endpoint) |
| `HERMES_INTELLIGENCE_ARTIFACT_SECRET` (+ tolerance `300`) | `[REDACTED:SET]` | Present |
| `HERMES_INTELLIGENCE_RECIPIENT_SECRET` (+ tolerance `300`, TTL `60`) | `[REDACTED:SET]` | Present |
| `INTELLIGENCE_RECIPIENT_PEPPER` | `[REDACTED:SET]` | Present |
| `HERMES_INTELLIGENCE_DELIVERY_STATUS_SECRET` (+ tolerance `300`, domain `gmail.com`) | `[REDACTED:SET]` | Present |
| `HERMES_PREDICTION_ENABLED` / `HERMES_PREDICTION_URL` / `HERMES_PREDICTION_SECRET` | `true` / `http://127.0.0.1:8651/webhook/predict` / `[REDACTED:SET]` | Enabled locally |
| **`HERMES_ONBOARDING_ENABLED` / `_WEBHOOK_URL` / `_WEBHOOK_SECRET`** | **NOT DEFINED IN `.env`** | **Discrepancy:** Exists in `.env.example` & `config/services.php:58-62` (defaults `enabled=false`), absent from active `.env` |
| `HERMES_WEBHOOK_UAT_SCOPE_ENABLED` | **NOT DEFINED IN `.env`** | Defaults to `false` (`config/services.php:48`) |
| `INTELLIGENCE_PDF_PYTHON` / `INTELLIGENCE_PDF_QPDF` | `[REDACTED:SET]` | Configured for local Python 3.13 & QPDF 12.4.2 |

### Workspace Hygiene Observations (Read-Only)
- `.env.bak-pre-testmode-off` exists in repository root (untracked/ignored; not read).
- `storage/app/private/p6-uat-laravel.env.backup` (`3,501 B`, modified `2026-11-25 22:12`) exists on disk (secret-bearing backup; not read; flagged in Risk Register).
- `storage/app/analytics-dataset/umkm-analytics-dataset.csv` (`111,923 B`, modified `2026-11-26 11:06`) exists on disk.
- `storage/app/private/intelligence/` contains 6 event UUID directories, each holding 1 `.pdf` and 1 `.xlsx` named by SHA-256 hash (`a424c677-...` from `2026-11-25 07:15` and 5 events from `2026-11-25 11:47–12:06`).
- `storage/logs/laravel.log` (`1,250,476 B`, last write `2026-11-26 11:04`): level summary only shows `2026-11-26` entries (`8 testing.ERROR`, `2 production.ERROR`, `2 local.ERROR`, `1 testing.WARNING`, `1 testing.INFO`). No raw log payloads exported.
