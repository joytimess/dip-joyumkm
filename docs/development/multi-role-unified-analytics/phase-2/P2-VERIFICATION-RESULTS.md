# P2 Verification Results

Targeted only; no full suites, no SQLite, no Playwright. Laravel tests ran on the isolated `joyumkm_testing` connection via `php artisan test`. Live `joyumkm-v2` was only read (plus the authorized migration/backfill/role mapping).

| Check | Result | Evidence |
|---|---|---|
| V2-01 Schema & role | PASS | `migrate` applied 3 migrations. Roles in `joyumkm-v2`: `user` 5, `analyst` 3; sample1–3 = analyst; demo, synthetic owner and UAT users = user. Unknown role rejected by DB CHECK (test) and mapped to `User` in code. Users count 8 before and after; no password or email written by any command. |
| V2-02 Authorization | PASS (targeted) | `tests/Feature/RoleFoundationTest.php` 6/6, 32 assertions: public registration ignores `role=superadmin`; Superadmin reads other owners' UMKM, reports and summary; Analyst/User get 404 for foreign UMKM, nested report list and nested six-factor draft, and cannot PATCH foreign UMKM; Superadmin edit keeps `user_id`; Superadmin consent grant on another owner's UMKM = 403; `transfer`/`manageAnalysts` denied for all roles. Related existing suites: Ownership, Auth, Dashboard, SixFactorMonitoring, IntelligenceReport, ArtifactDownload, Umkm*, SixFactor*, ThreeDimension* → 213 passed, 0 failed. |
| V2-03 Cohort & monitoring | PASS | Members 428; orphans 0; members whose owner ≠ cohort owner 0; UMKM total 457, owner #7 = 428 (unchanged); 3D 1,339, 6F 1,317, answers 21,386 (unchanged vs. pre-migration read). Second backfill run: 0 new rows. Filtered monitoring query on `joyumkm-v2` (read-only, ordered, limit 101): 29 rows, 0 cohort members, IDs 1–28 and 457; eligible non-cohort total 29, so not truncated. Feature test proves 101 cohort members + 1 real UMKM returns only the real one with `truncated=false`. |
| V2-04 Hermes scoped guard | PASS (no-network, code applied to volume) | `tests.test_scope_mode_independent` 5/5 with `JMI_UAT_SCOPE_ENABLED` unset: no pin → intake created (ordinary normal preserved); malformed/closed/expired pin → `UAT_SCOPE_DENIED`; matching pin admits only the exact event; an older out-of-scope job is blocked in `engine.process`, `snapshot.run`, `engine.recover`; model and `callback.http_post` never called; intake route script exits 3 with no job created. Existing `test_uat_scope_denies_until_exact_pin_and_after_close` still passes; `test_pipeline`, `test_delivery`, `test_email_status_sync` 58 passed. The one error in that run was a module name I guessed (`test_email_delivery`) that does not exist, not a failure. |
| V2-05 Integrity | PASS | `php -l` clean on all changed/new PHP; Hermes `py_compile` OK; Pint clean. `webhook_outbox` #23/#24 pending attempts 0, #25 delivered; `jobs` #30/#31 attempts 0, not reserved; jobs total 2. Windows tasks both Disabled. Hermes crons `jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring` all paused. No `queue:work` process; running php: IDE language server, a `php artisan serve` (not started by this run) and an unrelated project's server on :8000. Protected contracts untouched: no change to webhook payload/HMAC, scoring, finalization, PDF/XLSX, callback, `business_email` or recipient logic (diff limited to files listed in the handoff). |

## Addendum — Superadmin provisioning (operator-reported)

The operator ran `joyumkm:provision-superadmin` manually; their database query showed exactly one `superadmin@joyumkm.test` with role `superadmin`. Not re-verified by the agent (no additional checks run). V2-01 role evidence above predates this account (3 analyst, 5 user). G2-02 closed as PASS.

## Not run

- Live UAT: Hermes was verified in isolation (no-network tests only), not through a live scoped UAT.
- Live scoped UAT through the Hermes gateway (no event sent, by design). The allowed path was shown at the `store.intake` level; the artifact/delivery enrollment flags in normal mode are verified by code review of `joyumkm_intake.py`, not by execution.
- Full PHPUnit, Hermes full suite, Playwright, seeder tests (known P1 baseline failures, not re-run).
- Positive Superadmin check on every nested endpoint; scope is one shared method, with detail, reports and summary exercised.
