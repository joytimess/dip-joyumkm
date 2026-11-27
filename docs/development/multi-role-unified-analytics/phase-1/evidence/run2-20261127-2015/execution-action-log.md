# Evidence: Execution Action Log (Run 2)

- **Run:** `run2-20261127-2015`
- **Timezone:** times are shown in WIB (`+07:00`). UTC is given where it matters for correlation.
- **Order:** chronological. Each row is one action. Secrets and token values are never recorded.
- **Time precision:** rows that cite a system timestamp (registration, queue job, pin, dispatch, Hermes run, SMTP send, status sync, rollback) are exact to the second from the system record. Other rows show the minute the action was taken and are approximate to within about two minutes.

| # | Time (WIB / UTC) | Step | Action | Target | Result |
|---|---|---|---|---|---|
| 1 | 20:04 / 13:04 | 1 | Read Run 1 preflight, handoff, runtime safety docs | docs | OK |
| 2 | 20:06 / 13:06 | 1 | Container, scheduler, cron, DB and queue checks | runtime (read-only) | All as expected |
| 3 | 20:08 / 13:08 | 1 | Inspect Hermes state flags, UAT scope absence, job states | Hermes (read-only) | As expected |
| 4 | 20:10 / 13:10 | 3 | Read Laravel scope code, Hermes intake and JMI gate code | code (read-only) | Normal mode drops Hermes UAT gate (finding) |
| 5 | 20:12 / 13:12 | 1 | Verify backup checksums and dump safety | backup (read-only) | MATCH; no schema statements |
| 6 | 20:15 / 13:15 | 2 | Create temp schema `joyumkm_p1_restore_rehearsal` | DB (temp only) | Created |
| 7 | 20:15 / 13:15 | 2 | Restore dump into temp schema | DB (temp only) | Exit 0 |
| 8 | 20:16 / 13:16 | 2 | Structural, data and FK integrity checks | DB (temp + read-only live) | PASS |
| 9 | 20:17 / 13:17 | 2 | Write `restore-rehearsal.md` | docs | Written |
| 10 | 20:17 / 13:17 | 2 | `DROP DATABASE joyumkm_p1_restore_rehearsal` | DB (temp only) | Dropped; count = 0 |
| 11 | 20:18 / 13:18 | 4 | Snapshot `.env`, Hermes config, cron, onboarding settings | backup folder | Checksums recorded |
| 12 | 20:19 / 13:19 | 4 | Apply Laravel `.env` temp keys + `OPENWA_ENABLED=false` | `.env` | Verified keys (no secret printed) |
| 13 | 20:19 / 13:19 | 4 | Set Hermes `delivery-status.env` `1/0` | Hermes state | Verified |
| 14 | 20:19 / 13:19 | 6 | Verify `clussy14` absent from `users` | DB (read-only) | 0 |
| 15 | 20:19 / 13:19 | 6 | Generate password, store in restricted file | backup folder | Stored; not displayed |
| 16 | 20:19 / 13:19 | 6 | First register attempt to `localhost:8000` | HTTP | 404 from unrelated `flow-ugc`; no JoyUMKM write |
| 17 | 20:19 / 13:19 | 6 | Verify `joyumkm.test` host is JoyUMKM | HTTP (GET 401) | JoyUMKM response confirmed |
| 18 | 20:19:52 / 13:19:52 | 6 | Register authorized account | `POST /api/auth/register` | 201, `users.id=8` |
| 19 | 20:19:59 / 13:19:59 | 6 | `queue:work hermes-onboarding --queue=hermes-onboarding --once` | queue `hermes-onboarding` | Job 32 DONE 80 ms, removed |
| 20 | 20:20 / 13:20 | 6 | Read receiver receipts, run state | Hermes (read-only) | `done`, `welcome_email=sent` |
| 21 | 20:20 / 13:20 | 6 | Login and me | `POST /api/auth/login`, `GET /api/auth/me` | 200, 200 |
| 22 | 20:21 / 13:21 | 5 | Create UMKM `457` (`p1-uat-…`) | `POST /api/umkms` | 201, `notification_consent_active=true`, WA `unchecked` |
| 23 | 20:22 / 13:22 | 5 | Save six-factor draft (scores) | `PUT …/draft` | 201, draft `1317` |
| 24 | 20:22:30 / 13:22:30 | 5 | Finalize evaluation `1317` | `POST …/finalize` | 200, `total_score=22`, outbox `#25` created, not enqueued |
| 25 | 20:22 / 13:22 | 3 | Verify jobs `#30`,`#31` untouched after finalize | DB (read-only) | Untouched |
| 26 | 20:22 / 13:22 | 3 | Verify outbox `#25` payload fields vs gate | DB (read-only) | All match |
| 27 | 20:22:43 / 13:22:43 | 3 | Write Laravel pin and Hermes `uat-scope.json` (0600) | Laravel storage, Hermes state | Written |
| 28 | 20:22 / 13:22 | 3 | Tinker read-only gate check | Laravel (read-only) | `#25` allowed; `#23`,`#24` denied |
| 29 | 20:23:02 / 13:23:02 | 3 | Targeted `deliver(25)` | Laravel → Hermes proxy | 202, `delivered`, 1 attempt |
| 30 | 20:23:10 / 13:23:10 | 5 | Start guard (background, copy-out) | Hermes container | Running |
| 31 | 20:23:03–20:23:57 / 13:23:03–13:23:57 | 5 | JMI analysis submission (single) | Hermes job `5dd7efbf-…` | `accepted`, `corrective=false` |
| 32 | 20:24:00 / 13:24:00 | 5 | Completed; callback | Hermes → Laravel | Stored, `report_id=11` |
| 33 | 20:24:01 / 13:24:01 | 5 | Artifact upload rows | Laravel | pdf 13, xlsx 14 |
| 34 | 20:24:07 / 13:24:07 | 5 | Report email SMTP DATA | Gmail SMTP (1 message) | `sent` (SMTP accepted) |
| 35 | 20:24:07 / 13:24:07 | 5 | Delivery status sync | Hermes → Laravel | `accepted`, `synced` |
| 36 | 20:24 / 13:24 | 5 | Guard log read; guard exited on terminal state | Hermes container | No trip; `completed` |
| 37 | 20:25 / 13:25 | 5 | Artifact checksum verification (manifest, disk, Laravel, download) | read-only + authenticated GET | All match |
| 38 | 20:25 / 13:25 | 5 | Read delivery and status-sync records | DB and Hermes (read-only) | Consistent |
| 39 | 20:26 / 13:26 | 9 | Rollback `.env` from snapshot | `.env` | Checksum equals snapshot |
| 40 | 20:26 / 13:26 | 9 | Delete Laravel pin; delete Hermes `uat-scope.json`; restore `delivery-status.env` | files | Verified absent / restored |
| 41 | 20:27 / 13:27 | 9 | Logout (revoke token), remove token file | HTTP | 200, removed |
| 42 | 20:27 / 13:27 | 9 | Copy guard log to backup; remove guard script and log from container | Hermes container | Done |
| 43 | 20:27 / 13:27 | 8 | Read ambiguous-outcome test and email code | Hermes container (read-only) | Root cause identified |
| 44 | 20:27 / 13:27 | 8 | Copy `jmi`/`tests` to `/tmp/p1_diag`, run single test with scratch `JMI_HOME` and unusable `JMI_HOME` | Hermes container copy only | Reproduced both outcomes |
| 45 | 20:28 / 13:28 | 8 | Remove `/tmp/p1_diag` | Hermes container copy | Removed |
| 46 | 20:28 / 13:28 | 8 | Re-run `DemoSeederTest`, `SampleDataSeederTest` | Laravel `joyumkm_testing` | Same 3 fails and 2 errors as Run 1 |
| 47 | 20:28 / 13:28 | 9 | Final runtime snapshot | DB, tasks, containers, git | Matches authorized end state |

## Actions Not Taken (deliberate)

- No `queue:work` on `hermes-webhooks`. No `hermes:cron`. No `hermes:webhook-scan`.
- No retry of failed JMI jobs. No replay or deletion of outbox `#23`/`#24` or jobs `#30`/`#31`.
- No Telegram, WhatsApp or additional analytics LLM call.
- No new Docker container. No source-code edit. No commit, push or deploy.
- No mailbox access, no password reset for any existing account, no deletion of any UAT record.
- No second registration, no second UMKM, no second report send.
