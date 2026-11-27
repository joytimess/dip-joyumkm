# Evidence: Step 4 — Temporary Configuration Diff (Run 2)

- **Run:** `run2-20261127-2015`
- **Snapshot folder (restricted ACL):** `C:\laragon\backups\run2-20261127-2015\`
- **Snapshot checksums:** `SHA256SUMS-pre-run2.txt` in that folder
- **Rollback result:** `configuration-rollback.md`

Secret values are never recorded. Only key names, non-secret values, and checksums appear here.

## 1. Snapshot Before Any Change

| File | Pre-run SHA-256 (prefix) | Note |
|---|---|---|
| `JoyUMKM/.env` | `71571d34…` | Same as Run 1 `laravel.env.bak` |
| Hermes `state/delivery-status.env` | `d4495edd…` | `0/1` before |
| Hermes `state/email.env` | `c71926d9…` | `1/0`, unchanged |
| Hermes `state/dev-operation.env` | `fc7bba2b…` | `normal`, unchanged |
| Hermes `joyumkm-onboarding/settings.json` | `944c0fd4…` | unchanged |
| Hermes `cron/jobs.json` | `0a9b7d79…` | all 3 paused, unchanged |
| Hermes `state/uat-scope.json` | absent | absent before, absent after rollback |
| Storage `private/hermes-uat-scope.json` | absent | absent before, absent after rollback |

## 2. Changes Applied (Temporary)

| # | Target | Key | Before | During Track C | Reason |
|---|---|---|---|---|---|
| 1 | Laravel `.env` | `HERMES_WEBHOOK_UAT_SCOPE_ENABLED` | absent (`false`) | `true` | Scoped dispatch gate (Step 3) |
| 2 | Laravel `.env` | `HERMES_ONBOARDING_ENABLED` | absent (`false`) | `true` | Onboarding webhook (Step 6) |
| 3 | Laravel `.env` | `HERMES_ONBOARDING_WEBHOOK_URL` | absent | `http://127.0.0.1:8650/joyumkm-onboarding` | Local receiver, same route Hermes exposes |
| 4 | Laravel `.env` | `HERMES_ONBOARDING_WEBHOOK_SECRET` | absent | copied from the existing onboarding receiver setting (64 chars, value not recorded) | Existing verified credential, not a new one |
| 5 | Laravel `.env` | `OPENWA_ENABLED` | `true` | `false` | **Added during Track C (not in the mandate's list).** Creating a UMKM calls `openWa->check()`, a live WhatsApp gateway lookup. WhatsApp notifications were authorized at 0. Disabling OpenWA makes the existing fail-open policy save the UMKM with `whatsapp_registration_status=unchecked` and makes no outbound call. Reverted in rollback. |
| 6 | Hermes `state/delivery-status.env` | `JMI_EMAIL_STATUS_SYNC_ENABLED` | `0` | `1` | Status sync (Step 4) |
| 7 | Hermes `state/delivery-status.env` | `JMI_EMAIL_STATUS_SYNC_PAUSED` | `1` | `0` | Status sync unpaused (Step 4) |
| 8 | Laravel `storage/app/private/hermes-uat-scope.json` | pin file | absent | created, single event pin | Step 3 |
| 9 | Hermes `state/uat-scope.json` | pin file | absent | created, same pin (inert in normal mode) | Step 4 |

## 3. Items Verified Unchanged (Not Modified by Run 2)

| Item | Status | Why not changed |
|---|---|---|
| `JMI_DEV_OPERATION_MODE` | `normal` (unchanged) | Switching to a non-normal mode disables artifact pipeline enrollment, so PDF/XLSX cannot be produced (see `scoped-dispatch-verification.md` §4) |
| `email.env` | `JMI_EMAIL_DELIVERY_ENABLED=1`, `PAUSED=0` | Already in the authorized end state |
| Onboarding `settings.json` | `enabled=true`, `mode=live`, `allowlist` = 1 entry = authorized UAT mailbox | Already aligned. The file predates Run 2 (mtime 2026-11-25 18:50). No allowlist change needed. |
| Ingress container env | `JOYUMKM_ONBOARDING_LIVE_ENABLED=1`, `JOYUMKM_ONBOARDING_LIVE_RECIPIENT` = authorized UAT mailbox | Container env, not changed (no container restart or recreate) |
| Cron (`jobs.json`) | 3/3 paused | Not changed |
| Windows Scheduled Tasks | Both Disabled | Not changed |
| `consent` validation, email suppression | Active | Not disabled. The fixture was created with `notification_consent=true` through the normal API. |

## 4. Integrity After Rollback

All snapshot files were re-checked after rollback. Checksums match `SHA256SUMS-pre-run2.txt` (see `configuration-rollback.md`).
