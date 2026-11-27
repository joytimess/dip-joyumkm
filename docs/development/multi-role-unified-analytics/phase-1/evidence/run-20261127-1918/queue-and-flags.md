# Evidence: P1-02 Queue, Failed JMI Jobs & Delivery Flag Reconciliation (`run-20261127-1918`)

- **Evidence ID:** `EV-P1-QUEUE-001`
- **Observed At:** `2026-11-27T19:22:00+07:00` – `19:29:25+07:00` (`Asia/Jakarta`)
- **Mode:** Strictly read-only inspection of `joyumkm-v2` (`webhook_outbox`, `jobs`, `intelligence_*`) and Hermes `/opt/data/joyumkm-intelligence/` & `/opt/data/joyumkm-onboarding/`

---

## 1. Pending & Dead `webhook_outbox` / `jobs` Disposition (`joyumkm-v2`)

| Outbox ID | Event UUID | Eval ID / UMKM / Owner | Status (`attempts`) | Linked `jobs` Row | Hermes JMI Job / Report / Delivery | Risk & Prior Delivery | Recommended Disposition & Auth State |
|---|---|---|---|---|---|---|---|
| **`#23`** | `9c4bb8e1-56fe-487a-9bf8-0e935534b370` | `eval #31` (`10`, Kurang Sehat) · `umkm #5` · `sample1@joyumkm.test` | `pending` (`0`, `2026-11-26 02:44:24 UTC`) | `jobs #30` (`hermes-webhooks`, `attempts=0`, `reserved_at=null`) | **Absent** in `/opt/data/joyumkm-intelligence/state/jobs/`; `report=null`; `delivery=null` | Never dispatched to Hermes (`umkm #5` has `business_email=SET`, `notification_consent_at=NULL` -> would be ineligible for email anyway) | **Leave untouched (`pending`)**. Do not run blanket `queue:work` (would pop `jobs #30`). Requires separate operator disposition if ever desired. |
| **`#24`** | `32f0a6e5-0722-4bea-b7a8-4625fc03e4be` | `eval #32` (`8`, Kurang Sehat) · `umkm #5` · `sample1@joyumkm.test` | `pending` (`0`, `2026-11-26 02:58:50 UTC`) | `jobs #31` (`hermes-webhooks`, `attempts=0`, `reserved_at=null`) | **Absent** in `/opt/data/joyumkm-intelligence/state/jobs/`; `report=null`; `delivery=null` | Never dispatched to Hermes (`umkm #5` has `business_email=SET`, `notification_consent_at=NULL`) | **Leave untouched (`pending`)**. Do not run blanket `queue:work` (would pop `jobs #31`). |
| `#9, #10` | `bec8fde3-...`, `7c0c9b34-...` | `eval #17, #18` · `umkm #27` · `sample1@joyumkm.test` | `dead` (`1`, `2026-11-25`, HTTP `404 http_rejected`) | None | Absent; `report=null` | Historical failed test events | **Leave untouched (`dead`)**. |
| `#13..#15` | `da39370b-...`, `e985422c-...`, `78a4bbc0-...` | `eval #21..#23` · `umkm #27` · `sample1@joyumkm.test` | `dead` (`1`, `2026-11-25`, HTTP `200 ack_ignored`) | None | Absent; `report=null` | Historical UAT-scoped rejections | **Leave untouched (`dead`)**. |
| **`#16`** | `3dba65ad-864f-4d55-b6a5-e7727a48f2cc` | `eval #24` · `umkm #27` · `sample1@joyumkm.test` | `dead` (`1`, `2026-11-25 04:33:56`, HTTP `200 ack_ignored`) | None | **Exists in JMI (`failed`)** at `04:37:16Z` (separate intake attempt; failed analysis validation); `report=null` | No report, no artifact, no email | **Leave untouched (`dead` in Laravel, `failed` in JMI)**. |

---

## 2. Two Failed Hermes JMI Jobs (`/opt/data/joyumkm-intelligence/state/jobs/`)

| Event UUID | Eval / UMKM | Timestamps (UTC) | Stage & Error Code | Root Cause (`attempts/001.json`) | Related to `#23/#24`? | Prior Email / Report? |
|---|---|---|---|---|---|---|
| `3dba65ad-864f-4d55-b6a5-e7727a48f2cc` | `eval #24` / `umkm #27` | `2026-11-25T04:37:16Z` – `04:38:14Z` | `stage=analysis`, `attempt_log=EVIDENCE_POLICY_VIOLATION`, final `error_code=ANALYSIS_UNKNOWN`, `retryable=false` | `financial_claim_unverifiable in top_priorities[0].rationale: financial statement without a verifiable shape [pemasukan dan pengeluaran]` | **NO** (`eval #24`, 2026-11-25) | `report=null`, `email_delivery=waiting_package`, `sent_at=null` |
| `6e05a476-7eca-4f1c-9291-1de331a40559` | `eval #28` / `umkm #28` | `2026-11-25T04:57:58Z` – `04:58:49Z` | `stage=analysis`, `attempt_log=SCHEMA_MISMATCH`, final `error_code=ANALYSIS_UNKNOWN`, `retryable=false`, `corrective_authorization=halted_ambiguous` | `monthly_analysis fields, references, prose or action phases invalid` | **NO** (`eval #28`, 2026-11-25) | `report=null`, `email_delivery=waiting_package`, `sent_at=null` |

---

## 3. Flag, Route & Scope Reconciliation Matrix

| Layer / File | Setting / Key | Observed Live Value | Effective Semantics & Safety Implication |
|---|---|---|---|
| Laravel `.env` | `HERMES_WEBHOOK_ENABLED` | `true` | `HermesWebhookOutbox::recordFinalization()` WILL push `DeliverHermesWebhook` to `jobs` on finalize, and `hermes:webhook-scan` is armed; currently inert only because workers/scheduler are Disabled. |
| Laravel `.env` | `HERMES_WEBHOOK_UAT_SCOPE_ENABLED` | Unset (`false`) | `HermesWebhookSender::allows()` returns `true` for ALL events when unset. Must be set `true` + `storage/app/private/hermes-uat-scope.json` written before any live UAT. |
| Laravel `.env` | `HERMES_WEBHOOK_URL` / `ALLOWED_HOST` | `http://127.0.0.1:8644/webhooks/joyumkm-events-dev` / `127.0.0.1` | Targets dev route via `allowlist_proxy.py` (`POST /webhooks/joyumkm-events` and `POST /webhooks/joyumkm-events-dev` only). |
| Laravel `.env` | `HERMES_ONBOARDING_ENABLED` / `_URL` / `_SECRET` | **Unset** (`enabled=false`) | `DeliverHermesOnboardingWebhook::enabled()` returns `false`; `0` onboarding jobs queued on registration. |
| Hermes `dev-operation.env` | `JMI_DEV_OPERATION_MODE` | `normal` | **CRITICAL FINDING:** In `/opt/data/scripts/joyumkm_intake.py` & `jmi_recover.py`, `JMI_DEV_OPERATION_MODE=normal` **pops `JMI_UAT_SCOPE_ENABLED`** and sets `JMI_ARTIFACT_PIPELINE_ENABLED=1` & `JMI_ARTIFACT_DELIVERY_ENABLED=1`. Conversely, any non-`normal` value sets `JMI_UAT_SCOPE_ENABLED=1` **without** setting those two pipeline flags in `joyumkm_intake.py`! Must be explicitly handled in Track C preflight. |
| Hermes `email.env` | `JMI_EMAIL_DELIVERY_ENABLED` / `_PAUSED` | `"1"` / `"0"` | Email delivery is **armed/active** whenever `jmi_recover.py` or JMI worker runs on an enrolled job with `uat-scope` satisfied! |
| Hermes `delivery-status.env` | `JMI_EMAIL_STATUS_SYNC_ENABLED` / `_PAUSED` | `0` / `1` | Status sync callback to Laravel is **paused**. Must be temporarily set `1`/`0` during approved Track C Report UAT to verify `G1-05` status sync. |
| Hermes `state/uat-scope.json` | File presence | **Absent** (`removed-after-bounded-run` & `removed-after-eval24` exist as history) | Currently no UAT pin active. |
| Hermes `onboarding/settings.json` | `enabled`, `mode`, `app_url`, `allowlist` | `enabled=true`, `mode="live"`, `app_url="http://127.0.0.1:8013/home"`, `allowlist_count=1` (`[REDACTED:1_ALLOWLISTED_ADDRESS]`) | `onboarding.py` requires BOTH `email in allowlist` AND `JOYUMKM_ONBOARDING_LIVE_ENABLED=="1"` AND `JOYUMKM_ONBOARDING_LIVE_RECIPIENT==email`. |
| Hermes `receipts.sqlite3` | Onboarding receipts | `3` rows (`0` pending, `2` `smtp_started=1`) | Clean; no uncompleted onboarding work. |
