# Evidence: Real Onboarding Email Delivery and Registration (Run 2)

- **Run:** `run2-20261127-2015`
- **Recipient:** `[REDACTED:AUTH_UAT_MAILBOX]` (authorized `users.email`, same mailbox as the report recipient by operator decision)
- **Status:** `SMTP ACCEPTED — INBOX CONFIRMATION PENDING`. Not a full email PASS.

## 1. Pre-Registration Verification

| Check | Result |
|---|---|
| `users` row with the authorized address before registration | 0 (verified twice, before and after the registration attempt that hit the wrong host) |
| Existing account reuse | None. No reset, no email change, no deletion. |

## 2. Registration

| Item | Value |
|---|---|
| Endpoint | `POST http://joyumkm.test/api/auth/register` (normal API) |
| Response | `201` |
| New `users.id` | **8** |
| Password | Generated, 24 characters. Stored only in the restricted backup folder, never printed or logged. |
| Token issued | Yes, revoked afterwards with `POST /api/auth/logout` (200). |

**Misdirected attempt (recorded):** the first registration request was sent to `localhost:8000`, which the workspace resolves to an unrelated `flow-ugc` application, and it returned `404`. No JoyUMKM row was written. The request was then repeated against `joyumkm.test`. This is also recorded in `preflight-safety-verification.md`.

## 3. Event and Queue

| Item | Value |
|---|---|
| Event | `user.registered` v1 (`DeliverHermesOnboardingWebhook::forRegistration`) |
| Queue | `hermes-onboarding` (database connection, `jobs` table, queue name filter) |
| Laravel job | `jobs.id = 32`, queue `hermes-onboarding` |
| Worker | `php artisan queue:work hermes-onboarding --queue=hermes-onboarding --once --tries=1` (one pass, only this queue) |
| Worker result | `DONE` in 80.43 ms. Job removed. `failed_jobs = 0`. |
| `jobs` `hermes-webhooks` rows `30`, `31` | Untouched (`attempts=0`, `reserved_at` NULL) |

The queue payload is encrypted (`ShouldBeEncrypted`), and the job used the existing mechanism without changes.

## 4. Hermes Receiver

| Item | Value |
|---|---|
| Route | `POST /joyumkm-onboarding` on port 8650 (`hermes-workflow-ingress`) |
| Signature | HMAC verified by the receiver; the Laravel job accepted `202 accepted` (run id `4ca001338046`) |
| Receipt | `receipts.sqlite3`, one row for the registration event id, `smtp_started=1` |
| Duplicate receipts for this event | 0 |
| Run state (`workflow-runs/4ca001338046.json`) | `status=done` |
| Node `recipient_policy` | `ok` (the authorized mailbox matched the live-recipient allowlist) |
| Node `welcome_email` | `status=sent`, finished `13:20:03Z` |

## 5. Email Count

| Metric | Value |
|---|---|
| Real onboarding emails | **1** (within the authorized maximum of 1) |
| Duplicates | 0 |
| SMTP result | Accepted (`sent`). `error` = null. |

`sent` here is SMTP acceptance. It is not an inbox receipt (see `inbox-confirmation.md`).

## 6. Authentication Check

| Step | Result |
|---|---|
| `POST /api/auth/login` (authorized account) | `200` |
| `GET /api/auth/me` with the issued token | `200`, `id=8` |
| `POST /api/auth/logout` | `200` (token revoked) |

The account works through the existing authentication mechanism.

## 7. RBAC Note

No Analyst role was assigned or tested. That is P4 scope, as the mandate says.
