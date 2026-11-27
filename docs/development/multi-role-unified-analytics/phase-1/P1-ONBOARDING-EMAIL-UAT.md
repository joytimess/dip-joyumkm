# P1-05 — Registration & Real Onboarding Email UAT

- **Gate Status (`G1-06`):** **`BLOCKED`** *(Pending operator preflight authorization for unique approved mailbox, `.env` `HERMES_ONBOARDING_*`, and Hermes live-recipient gate)*
- **Current Run:** `run-20261127-1918` (Zero live onboarding emails sent; `receipts.sqlite3` unchanged at `3` historical rows, `0` pending).

---

## 1. Isolated Contract & Source Verification Completed

- `AuthApiTest.php` (`17/17` PASS on `joyumkm_testing`): validates public `POST /api/auth/register`, duplicate email rejection, invalid input validation, and `DeliverHermesOnboardingWebhook` transactional queue contract.
- `test_register.py` & `test_delivery.py` in `visual-workflow` (`37/37` PASS across analytics presentation, monitoring Telegram, register, delivery): verifies `user.registered` v1 schema, HMAC verification, SQLite receipt deduplication, and `onboarding.py` eligibility checks.
- **Verified `.env` / Hermes Preconditions Missing Today:**
  - `.env` lacks `HERMES_ONBOARDING_ENABLED`, `HERMES_ONBOARDING_WEBHOOK_URL`, and `HERMES_ONBOARDING_WEBHOOK_SECRET` (`DeliverHermesOnboardingWebhook::enabled()` currently returns `false`).
  - `/opt/data/joyumkm-onboarding/settings.json` has `enabled=true`, `mode="live"`, `app_url="http://127.0.0.1:8013/home"`, and `allowlist` = 1 redacted address (`[REDACTED:1_ALLOWLISTED_ADDRESS]`); `onboarding.py:106-111` requires BOTH `email in allowlist` AND `JOYUMKM_ONBOARDING_LIVE_ENABLED == "1"` AND `JOYUMKM_ONBOARDING_LIVE_RECIPIENT == email`.

---

## 2. Exact Blockers to Close `G1-06`

See [preflight-and-authorization.md](evidence/run-20261127-1918/preflight-and-authorization.md):
1. Operator designates an owned, unique test email address/alias for 1 new registration.
2. Operator authorizes temporary `.env` `HERMES_ONBOARDING_*` settings + Hermes `allowlist` / `JOYUMKM_ONBOARDING_LIVE_RECIPIENT` alignment and single `php artisan queue:work hermes-onboarding --once` execution (max `1` email).
3. Operator provides redacted inbox receipt confirmation after SMTP `250` acceptance.
