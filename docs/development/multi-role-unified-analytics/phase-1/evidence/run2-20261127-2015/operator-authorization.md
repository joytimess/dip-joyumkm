# Evidence: Operator Authorization Usage (Run 2)

- **Run:** `run2-20261127-2015`
- **Source:** the consolidated operator authorization for Track C live UAT (pasted in the session, as referenced in `preflight-and-authorization.md`)
- **Authorization status:** Used within limits. Items marked "not used" were not exercised.

## 1. Parameters vs Actual Use

| Parameter | Authorized | Actual | Within limit |
|---|---|---|---|
| Report UAT `business_email` | `[REDACTED:AUTH_UAT_MAILBOX]` | Same | YES |
| Onboarding `users.email` | Same mailbox (deliberate) | Same | YES |
| Report fixture (new) | Max 1 | **1** (UMKM 457, prefix `p1-uat-`) | YES |
| Onboarding registration (new account) | Max 1 | **1** (`users.id=8`) | YES |
| Database | `joyumkm-v2` | `joyumkm-v2` (writes limited to the fixture); temp schema used for rehearsal only | YES |
| Real report email | Max 1 | **1** (SMTP accepted) | YES |
| Real onboarding email | Max 1 | **1** (SMTP accepted) | YES |
| JMI LLM invocation | Max 1 | **1** (`attempt=1`, `corrective=false`) | YES |
| Telegram notifications | 0 | **0** (monitoring cron paused, no Telegram path executed) | YES |
| WhatsApp notifications | 0 | **0** (OpenWA disabled during fixture creation; no WA message path executed) | YES |
| Temporary configuration | With backup and rollback | Snapshot before change; rollback verified | YES |
| Restore rehearsal | Temp schema only | `joyumkm_p1_restore_rehearsal` created, restored, verified, dropped | YES |
| Production modification | Not authorized | None | YES |
| P2 implementation | Not authorized | None | YES |

## 2. Items That Needed Judgement (and what was done)

1. **Report owner account.** The mandate did not name one. The only legitimately created account was the authorized onboarding account, so it was used as the fixture owner. No other account was created. Registration therefore ran before the report fixture. Both use the authorized mailbox, and both identities are still separate attributes (`users.email` vs `umkms.business_email`).
2. **`OPENWA_ENABLED=false` during fixture creation.** Not in the mandate's list. It was added to prevent a live WhatsApp gateway lookup, since WhatsApp notifications were authorized at 0. It was reverted in rollback.
3. **`JMI_DEV_OPERATION_MODE` left at `normal`.** Changing it would have removed PDF/XLSX generation. The mandate's rule ("do not activate normal globally without proof of isolation") was applied by proving isolation on the Laravel side and recording the Hermes-side gap. See `scoped-dispatch-verification.md`.
4. **Hermes `uat-scope.json` written but inert.** Written to match the mandate's list. Recorded as inert in `normal` mode.

## 3. Not Done (out of Track C scope)

- Inbox confirmation (pending operator).
- RBAC, Analyst creation, ownership transfer, 458-UMKM seeder, P2 implementation.
- Live Telegram, WhatsApp, and additional analytics LLM invocations.
- Changes to 428 synthetic or 28 non-cohort UMKMs.
- Deletion of any UAT account, evaluation, report, or artifact (no cleanup authorization).
