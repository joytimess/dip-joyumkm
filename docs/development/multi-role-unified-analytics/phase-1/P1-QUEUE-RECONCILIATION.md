# P1-02 — Pending Queue, Failed Jobs & Delivery Reconciliation

- **Gate Status (`G1-02`):** **`PASS (Safety Isolation Analyzed; Hard Stop Active for Live Track C Until Operator Preflight Approval)`**
- **Evidence:** [queue-and-flags.md](evidence/run-20261127-1918/queue-and-flags.md) · [preflight-and-authorization.md](evidence/run-20261127-1918/preflight-and-authorization.md)

---

## 1. Pending `webhook_outbox` (`#23/#24`) & `jobs` (`#30/#31`) Reconciliation

- Both `#23` (`9c4bb8e1-56fe-487a-9bf8-0e935534b370`, `eval #31`, `umkm #5`, `sample1@joyumkm.test`) and `#24` (`32f0a6e5-0722-4bea-b7a8-4625fc03e4be`, `eval #32`, `umkm #5`) remain `pending` with `attempts=0` since `2026-11-26 02:44–02:58 UTC`.
- Neither event exists in Hermes `/opt/data/joyumkm-intelligence/state/jobs/`, `intelligence_reports`, or `intelligence_deliveries`; moreover `umkm #5` currently has `notification_consent_at=NULL` (`has_consent=false`).
- **Critical Queue Isolation Finding:** Although `HERMES_WEBHOOK_UAT_SCOPE_ENABLED=true` + `storage/app/private/hermes-uat-scope.json` prevents `HermesWebhookOutbox::scan()` from scanning `#23/#24` and prevents `HermesWebhookSender::allows()` from sending them (`uat_scope_denied`), running a blanket `php artisan queue:work --queue=hermes-webhooks` **would still dequeue and delete `jobs #30` and `#31`** from the `jobs` table (`tries=1`, `claim()` returns `null`). Therefore, Track C MUST NOT run a blanket `queue:work --queue=hermes-webhooks`; it must process ONLY the new UAT `outbox_id` (or operator must authorize `#23/#24` & `#30/#31` disposition).
- **Default Disposition:** Leave `#23/#24`, `#30/#31`, and all `6` historical `dead` rows (`#9, #10, #13..#16`) completely untouched.

---

## 2. Two Failed JMI Jobs Classification

- `3dba65ad-864f-4d55-b6a5-e7727a48f2cc` (`eval #24`, `umkm #27`, `2026-11-25T04:37:16Z`): failed at `stage=analysis` (`EVIDENCE_POLICY_VIOLATION` -> `ANALYSIS_UNKNOWN`, `retryable=false`), no report/email. **Unrelated** to `#23/#24`.
- `6e05a476-7eca-4f1c-9291-1de331a40559` (`eval #28`, `umkm #28`, `2026-11-25T04:57:58Z`): failed at `stage=analysis` (`SCHEMA_MISMATCH` -> `ANALYSIS_UNKNOWN`, `retryable=false`), no report/email. **Unrelated** to `#23/#24`.
- Both preserved untouched.

---

## 3. Delivery Flags, Onboarding Gate & Critical `dev-operation.env` Finding

- **Laravel `.env`:** `HERMES_WEBHOOK_ENABLED=true`, `HERMES_WEBHOOK_UAT_SCOPE_ENABLED` unset (`false`), `HERMES_ONBOARDING_*` unset (`false`).
- **Hermes JMI State:** `email.env` has `JMI_EMAIL_DELIVERY_ENABLED="1"` / `JMI_EMAIL_DELIVERY_PAUSED="0"` (armed!), `delivery-status.env` has `ENABLED="0"` / `PAUSED="1"`, `uat-scope.json` is absent, and `dev-operation.env` has `JMI_DEV_OPERATION_MODE=normal`.
- **Critical Script Finding (`/opt/data/scripts/joyumkm_intake.py` & `jmi_recover.py`):** When `JMI_DEV_OPERATION_MODE=normal`, the script **pops `JMI_UAT_SCOPE_ENABLED`** and sets `JMI_ARTIFACT_PIPELINE_ENABLED=1` & `JMI_ARTIFACT_DELIVERY_ENABLED=1`. When `JMI_DEV_OPERATION_MODE != normal`, it sets `JMI_UAT_SCOPE_ENABLED=1` **without** setting those two artifact/delivery pipeline env vars! Track C preflight must account for this exact behavior.
- **Hermes Onboarding (`onboarding.py` & `settings.json`):** `enabled=true`, `mode="live"`, `app_url="http://127.0.0.1:8013/home"`, `allowlist` contains 1 redacted address (`[REDACTED:1_ALLOWLISTED_ADDRESS]`), and `onboarding.py` enforces exact match against both `allowlist` and container env `JOYUMKM_ONBOARDING_LIVE_RECIPIENT`.
- **Isolated Negative Controls Verified:** Laravel PHPUnit A4 (`273/274` passed, 1 Windows skip) + Hermes `analytics` AST/MySQL negative tests (`7/7` rejected) + `umkm-predict` validation (`422`) verify out-of-scope, missing-consent, and invalid payloads fail closed.
