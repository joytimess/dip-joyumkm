# Evidence: Step 9 — Configuration Rollback (Run 2)

- **Run:** `run2-20261127-2015`
- **Rollback performed:** 2026-11-27 20:26–20:28 WIB (13:26–13:28 UTC), after the live report and onboarding steps completed
- **Result:** `PASS`. Every temporary item restored to its snapshot. Checksums verified.

The rollback was performed while the live report and onboarding steps were already complete, as the mandate requires. Inbox confirmation was not needed to proceed.

## 1. Rollback Actions

| # | Target | Action | Verification |
|---|---|---|---|
| 1 | Laravel `.env` | Restored byte-for-byte from `laravel.env.pre-run2` | SHA-256 `71571d34…` = snapshot value. Key-level diff before restore showed exactly 5 changes (`OPENWA_ENABLED` plus 4 added keys), all reverted. |
| 2 | Laravel `storage/app/private/hermes-uat-scope.json` | Deleted | Absent |
| 3 | Hermes `state/delivery-status.env` | Restored from snapshot via `docker cp`, owner `hermes:hermes`, mode `0600` | Content `JMI_EMAIL_STATUS_SYNC_ENABLED=0`, `…_PAUSED=1` (equals snapshot). SHA-256 `d4495edd…` matches snapshot. |
| 4 | Hermes `state/uat-scope.json` | Deleted | Absent |
| 5 | Hermes `state/email.env` | No change needed | SHA-256 `c71926d9…` = snapshot |
| 6 | Hermes `state/dev-operation.env` | No change needed | `normal`; SHA-256 `fc7bba2b…` = snapshot |
| 7 | Hermes onboarding `settings.json` | No change needed | SHA-256 `944c0fd4…` = snapshot |
| 8 | Hermes `cron/jobs.json` | No change | SHA-256 `0a9b7d79…` = snapshot; 3/3 paused |
| 9 | Auth token issued for UAT | Revoked by `POST /api/auth/logout` (200), token file removed | Removed |
| 10 | Container guard script and log | Log copied to backup folder, script and log removed from `hermes-agent:/tmp` | Removed |
| 11 | Disposable diagnostic copy under `/tmp/p1_diag` | Removed | Removed |

## 2. Snapshot Checksum Re-verification

`sha256sum -c SHA256SUMS-pre-run2.txt` (in `C:\laragon\backups\run2-20261127-2015\`): all entries `OK`, for `laravel.env.pre-run2`, `cron_jobs.json`, `ingress_onboarding.py.ref`, `delivery-status.env`, `dev-operation.env`, `email.env`, `settings.json`.

## 3. Mandated End State

| Component | Expected (mandate) | Observed after rollback |
|---|---|---|
| `email.env` | Enabled=1, Paused=0 | `1/0` |
| `delivery-status.env` | Back to initial configuration | `0/1` (initial) |
| Laravel UAT scope | Back to initial configuration | `HERMES_WEBHOOK_UAT_SCOPE_ENABLED` absent (`false`) |
| Hermes UAT scope | Back to initial configuration | `uat-scope.json` absent |
| Onboarding allowlist | Back to initial configuration | Unchanged (already the authorized mailbox before Track C; see `temporary-configuration-diff.md` §3) |
| `JMI_DEV_OPERATION_MODE` | Back to initial configuration | `normal` (initial) |
| Windows Scheduled Tasks | Disabled | Both `Disabled` |
| Hermes cron | 3/3 paused | 3/3 `paused` |
| General Laravel workers | Not running | 0 `php.exe` queue or scheduler processes |
| Pending outbox `#23`/`#24` | Unchanged | `pending`, `attempts=0`, `queued_job_id` 30/31 |
| Pending jobs `#30`/`#31` | Unchanged | Present on `hermes-webhooks`, `attempts=0`, `reserved_at` NULL |

## 4. Fixture and Evidence Retention

Retained and not deleted (no cleanup authorization was given):

- `users.id = 8` (authorized onboarding account, `p1-…` fixture owner)
- `umkms.id = 457` (`p1-uat-20261127-2015`)
- `six_factor_evaluations.id = 1317`
- `webhook_outbox.id = 25` (`delivered`)
- `intelligence_reports.id = 11`, artifacts `13`, `14`, manifest `7`, delivery `2`
- Restricted backup folder `C:\laragon\backups\run2-20261127-2015\`, including the account password file, which should be deleted after P1 is accepted

## 5. Note on Email.env

The mandate says the rollback of `email.env` to `1/0` is not permission to run workers, recovery or cron. It was not changed in Run 2, and no worker was started for it.
