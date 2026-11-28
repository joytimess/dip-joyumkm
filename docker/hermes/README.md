# JoyUMKM — Reproducible Hermes dependency (local development only)

Laravel, Vue, PHP and MySQL keep running **natively in Laragon**. Docker manages **only Hermes**
(`hermes-agent`, `hermes-workflow-ingress`, `hermes-webhook-proxy`, Compose project `jobsheet2`, volume `hermes_data`).
Hermes upstream is not forked: JoyUMKM versions only its own deployment definition and integration assets.

| Item | Location |
|---|---|
| Canonical Compose entrypoint | `compose.yaml` (repo root; project name `jobsheet2`, image pinned by digest) |
| Setup / check / backup | `scripts/setup-hermes.ps1`, `scripts/check-hermes.ps1`, `scripts/backup-hermes.ps1` (shared: `scripts/hermes-common.ps1`) |
| Manifest (pins + SHA256) | `docker/hermes/manifest.json` |
| Integration assets | `docker/hermes/scripts/jmi` (JMI engine/intake incl. P2 scope guard), `…/visual-workflow-overlay`, `…/jmi-knowledge`, `docker/hermes/workflows` (7) |
| Sanitized templates | `docker/hermes/config` (no secrets; placeholders `__FROM_LARAVEL_ENV__`, `__MANUAL__`, `__GENERATED_BY_SETUP__`) |
| Analytics SQL (canonical, not duplicated) | `docs/jobsheets-6/evidence/analytics-views-v2.sql` |
| Proxy allowlist (existing, tracked) | `docs/deployment/hermes-proxy/allowlist_proxy.py` |

Minimum: Windows PowerShell 5.1, Docker Desktop with Compose v2 (subpath volume mounts), Laragon MySQL 8.4, `git` and `python` on PATH (Fresh only).

## Prerequisites (standard Laravel steps — NOT run by these scripts)

Only for a new workstation, as authorized by your normal workflow:
`composer install`, `npm ci && npm run build`, copy `.env.example` → `.env`, `php artisan key:generate`, `php artisan migrate`,
and the dataset seeder under its existing controls. The scripts never run `migrate:fresh`, `db:seed --force`, or create/reset users.
Laravel `.env` must have `APP_ENV=local`, `DB_DATABASE=joyumkm-v2` and the `HERMES_*` / `INTELLIGENCE_*` secrets.

## Commands

```powershell
# Existing deployment: verify compose.yaml == running containers + assets; NO up/down/pull/restart, writes nothing
.\scripts\setup-hermes.ps1 -Mode Adopt          # (-Mode Auto picks this automatically when the 3 containers exist)

# Dry run for any mode: read-only checks and the plan, creates/writes/imports nothing
.\scripts\setup-hermes.ps1 -Mode Fresh -Plan

# New workstation (requires explicit mode; refuses if any hermes container/volume/port already exists)
.\scripts\setup-hermes.ps1 -Mode Fresh

# Read-only health check (safe to repeat; exit 0 only if INFRA_READY and INTEGRATION_READY)
.\scripts\check-hermes.ps1

# Config + inventory backup to a folder OUTSIDE the repo (owner-only ACL, SHA256SUMS)
.\scripts\backup-hermes.ps1 -Destination C:\Users\<you>\backups\hermes
```

Exit codes: `0` ok · `1` checks failed · `2` blocked/conflict/error. There is no reset/destroy option by design.

### Secrets

* Fresh mode prompts for the MySQL administrator password as a **masked** `SecureString`; it is written only to a temporary
  owner-only option file (never on a command line) that is deleted in `finally`. The reader password is generated and written only to
  `mysql.json` (mode 600) inside the volume. Dashboard secrets are generated into `docker/hermes/.env` (git-ignored, owner-only ACL).
* Adopt mode reads the legacy `C:\laragon\jobsheet2\.env` **in place, in memory** for comparison; it is never copied or printed.
* Required: MySQL admin password (Fresh), Laravel `HERMES_*` secrets. Optional / activation-only: SMTP, Telegram/WhatsApp, LLM provider credentials (not needed for smoke).

## What `-Mode Adopt` proves (and does not)

It validates `compose.yaml` and compares its resolved config with the live containers (image digest, restart, privileged/user/read-only/cap_drop/security_opt,
ports, mounts incl. volume subpaths, networks, env key/values — only names are reported), then verifies versioned assets against the manifest and runs the
read-only check. **Live containers keep their old labels** (`config_files` = `C:\laragon\jobsheet2\docker-compose.yml`); labels are not migrated.

> **Never run `docker compose up` / `down` / `pull` against the existing deployment** from `compose.yaml` without an approved maintenance window:
> the pinned reference string, working-directory label and config hash differ from the running containers, so Compose may recreate them. Use the scripts.

## Fresh mode — what it does and what stays manual

Implemented but **not run** in the authoring session (no clean-room environment was authorized). Steps: create network+volume if absent → inspect, then create only absent
SQL objects → stage pinned plugin (clone at manifest commit + overlay), JMI code, 7 workflows, pinned pure-Python deps, and templates with **all side effects off**
(`JMI_EMAIL_DELIVERY_ENABLED=0/PAUSED=1`, `STATUS_SYNC_ENABLED=0/PAUSED=1`, onboarding `enabled=false, mode=dry-run`, empty callback registry) → one transient
`docker run --rm` copies the stage into `hermes_data` → `docker compose up -d` once → `check-hermes.ps1`.

Still manual after Fresh (see `manifest.json › manual_prerequisites_fresh`): Hermes provider login, `hermes plugins enable visual-workflow`, webhook subscriptions
(`joyumkm-events`, `joyumkm-events-dev`), the three cron jobs installed **paused**, filling `state/*.env` placeholders (`__MANUAL__`) and SMTP values.
Old operational state (outbox, jobs, audit, runs, sessions, cron state, live config files) is never imported.

## Conflicts, partial failure, rollback

* Unknown/partial topology, busy ports, existing reader account with unknown password, views not matching the v2 contract → the script **stops without changing anything**.
* Partial Fresh failure keeps created resources (network, volume, SQL objects, `docker/hermes/generated/stage`) and is re-runnable; delete nothing automatically.
* SQL rollback (DDL commits implicitly — do it step by step, as a DBA): the reverse commands are at the end of `analytics-views-v2.sql`. Never restore the whole database dump; never `docker volume rm hermes_data` or `compose down -v`.
* Original `C:\laragon\jobsheet2\docker-compose.yml` and its `rollback.md` remain the rollback reference until the operator authorizes removal.
* Backups contain configuration/inventory only. A consistent `hermes_data` snapshot needs stopped services (downtime) and is not done silently. **Restore has not been rehearsed.**

## Current limitations (carried, not fixed here)

`ssl_verify_identity=false` (CA verified, hostname not); monitoring cap 100 without pagination; methodology `partial`; 8651 `/health` path unverified (reported as WARN);
`callback_registry.json` and live `state/*.env`, `webhook_subscriptions.json`, cron state are operational state and are not versioned;
three plugin test files with token-like fixtures and `.hermes-catalog.json` are excluded from the overlay.

## Operational activation is separate

Not changed or solved by this tooling: pending outbox #23/#24 and jobs #30/#31; dev email delivery enabled/unpaused while status-sync disabled/paused; paused Hermes cron
jobs; two Disabled Windows tasks; no active UAT scope pin; secret rotation; production authorization. Any activation or reconciliation needs its own authorized milestone.
