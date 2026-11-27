# P1-07 — P2–P8 Regression Matrix

- **Baseline Anchor:** `main` @ `aaa05da4a1d88fa72ec95401c6ab6d80a35213f7` · Run `run-20261127-1918` (`2026-11-27`)
- **Rule:** Future phases must preserve every `PASS` invariant below and must NOT attribute P1's pre-existing `KNOWN_BASELINE_FAILURE` items (`DemoSeederTest`, `SampleDataSeederTest`, `7` legacy `tests/js/*-browser.mjs`, `test_analytics_workflow.py` `3`, `test_commands.py` `9`, `test_email_status_sync.py` `1`) to new changes unless newly regressed.

---

## 00. P1 Final Closure Update (2026-11-27)

- **Closure:** `P1 CLOSED — WITH ACCEPTED LIMITATIONS`. Full record: [P1-FINAL-HANDOFF.md](P1-FINAL-HANDOFF.md), section "P1 Final Closure". No P2 work started.
- **Invariant status at closure:**

| Flow / Invariant | Closure status | Mandatory P2 action |
|---|---|---|
| DB isolation & backup safety (`T1-01`) | **PASS** | Rerun on any migration, seeder, cutover or deploy. |
| Queue / flag isolation (`T1-02`) | **PASS (Laravel-side) · PARTIAL (Hermes-side, accepted limitation)** | Hermes-side isolation under `normal` mode is mandatory. Until then, do not rely on Hermes-side pinning. |
| Public registration & auth (`T1-03`) | **PASS** | Keep. |
| UMKM CRUD & ownership (`T1-04`) | **PASS** | Keep `business_email` and consent behavior. |
| 3D scoring (`T1-05`) | **PASS** | Keep. |
| 6F scoring, finalization & outbox (`T1-06`, `T1-07`) | **PASS** | Keep. |
| Intelligence pipeline & report email (`T1-08`–`T1-13`) | **PASS** (report email inbox confirmed by operator) | Tab Intelligence UI click-through still open (P2). |
| Onboarding webhook & email (`T1-14`, `T1-15`) | **PASS** (onboarding email inbox confirmed by operator) | Keep. |
| UMKM predict (`T1-16`) | **PASS** (Run 1) | Keep. |
| Scheduled monitoring (`T1-17`) | **OPEN — RSK-06** | **Mandatory pre-P3 gate.** Exclude synthetic cohort before P3 seeding and before unpausing the cron. |
| Conversational analytics (`T1-18`) | **PARTIAL** (Telegram, WhatsApp and LLM live checks `NOT RUN`) | Authorize and run separately if required. |
| Frontend UI baseline (`T1-10`) | **PARTIAL** (smoke `6/6` PASS; legacy specs baseline failure) | Tab Intelligence click-through and legacy spec repair. |
| Ambiguous terminal outcome (`T1-19`) | **PARTIAL — environment-dependent defect** | Pin `JMI_HOME` in the test. |
| Seeder baseline (`T1-20`) | **KNOWN_BASELINE_FAILURE** (not product regression) | Repair test defects in P2 test hygiene. |
| Git / DB / Hermes / task integrity (`T1-21`) | **PASS** | Keep. |

- **Rule (unchanged):** future phases must not attribute the known baseline failures above to new changes unless newly regressed.

## 0. Run 2 Update — Track C Live UAT (`run2-20261127-2015`, 2026-11-27 20:04–20:28 WIB)

Run 1 rows in §1 are retained as the historical baseline. The Run 2 status for each protected invariant is below. Evidence folder: [evidence/run2-20261127-2015/](evidence/run2-20261127-2015/).

| Flow / Invariant | Run 2 observed | Run 2 status |
|---|---|---|
| DB isolation & backup safety (`T1-01`) | Backup SHA-256 re-verified. Restore rehearsal into temp schema: 28 tables, 6 triggers, 22 migrations, counts equal live, 0 non-NULL FK orphans. Temp schema dropped. | **PASS** |
| Queue / flag isolation (`T1-02`) | `#23`/`#24` remain `pending` (`queued_job_id` 30/31). `#30`/`#31` untouched. New event `#25` was dispatched by targeted `deliver()`, never enqueued. Hermes-side pin **not enforced** under `JMI_DEV_OPERATION_MODE=normal` (artifact generation requires normal mode). | **PASS** (Laravel-side) · **PARTIAL** (Hermes-side, documented) |
| Public registration & auth (`T1-03`) | Live registration `201` (`users.id=8`), login `200`, `me` `200`, logout `200`. | **PASS** (live) |
| UMKM CRUD & ownership (`T1-04`) | Fixture `457` created through normal API. `business_email` and `notification_consent_active` preserved. | **PASS** (live) |
| 6F scoring, finalization & outbox (`T1-06`, `T1-07`) | Finalize `200`, `total_score=22`. Exactly one `evaluation.finalized` v2 row (`#25`). | **PASS** (live) |
| Intelligence pipeline, PDF/XLSX, consent & delivery (`T1-08`–`T1-13`) | 1 LLM invocation. PDF and XLSX checksums match manifest, Laravel row and download. Callback `201`, report `11`. Report email SMTP accepted. Status sync accepted and synced. Inbox **pending**. | **PARTIAL** (G1-05) |
| Onboarding webhook & email (`T1-14`, `T1-15`) | `user.registered` v1 processed once; 1 onboarding email SMTP accepted; receiver run `done`. Inbox **pending**. | **PARTIAL** (G1-06) |
| UMKM predict (`T1-16`) | Not re-run in Run 2. | Unchanged (Run 1 `PASS`) |
| Scheduled monitoring (`T1-17`) | Cron stays `paused`. RSK-06 synthetic leak still present. Mandatory P2 fix before P3 seeding and before unpause. | **Unchanged (RSK-06 open)** |
| Conversational analytics (`T1-18`) | Not re-run. Live Telegram / LLM analytics not authorized. | Unchanged (Run 1 `PARTIAL`) |
| Frontend UI baseline (`T1-10`) | Tab Intelligence click-through **not performed**. Download endpoint verified byte-for-byte. | **PARTIAL** |
| Ambiguous terminal outcome (`T1-19`) | Failing test reproduced: env-dependent. Test does not pin `JMI_HOME`. Unusable home gives `blocked`/`EMAIL_STATE_INVALID` before any submit. Terminal states are never re-sent. | **Classified: environment-dependent test defect** (not a product regression) |
| Seeder baseline (`T1-20`) | `DemoSeederTest` and `SampleDataSeederTest` re-run: same 3 failures and 2 errors as Run 1, plus 2 test-side defects (argument signature, undefined connection). | **KNOWN_BASELINE_FAILURE** (unchanged) |
| Git / DB / Hermes / task integrity (`T1-21`) | Tracked git changes: none. Tasks `Disabled`. Cron `3/3` paused. No worker. Config restored and checksum-verified. | **PASS** |

**Changed invariant:** the Rule above must now also list `test_email_status_sync.py::test_ambiguous_terminal_outcome_and_legacy_enrollment_do_not_submit` as an environment-dependent failure until its `JMI_HOME` isolation is fixed in P2.

---

## 1. Protected Flow & Phase Regression Matrix (Run 1 baseline, retained)

| Flow / Invariant | P1 Test ID(s) | Exact Verification Command / Procedure | Fixture Prerequisites | P1 Observed Baseline | Protected Invariant | Affected Phases | Rerun Triggers | Evidence Ref |
|---|---|---|---|---|---|---|---|---|
| **DB Isolation & Backup Safety** | `T1-01`, `T1-21` | `TestCase.php` guard + `backup_p1.py` (`mysqldump` + `SHA256SUMS.txt`) + row-count check | `joyumkm_testing` clean; backup outside repo | `joyumkm-v2` `456` UMKM unchanged; fresh backup verified structurally (`NOT RUN` restore rehearsal) | Never mutate `joyumkm-v2` in tests; preserve `28` non-cohort + `428` cohort | **P2–P8** | Any migration, seeder, DB cutover, or deploy | [P1-RUNTIME-SAFETY.md](P1-RUNTIME-SAFETY.md) |
| **Queue / Flag Isolation & Old Events** | `T1-02` | Read-only check of `webhook_outbox`, `jobs`, `email.env`, `uat-scope.json` | Workers `Disabled`, crons `paused` | `#23/#24` & `#30/#31` preserved untouched; `joyumkm_intake.py` `dev-operation.env` nuance documented | Never consume unrelated events; no blanket `queue:work` over stale jobs | **P2, P4, P7, P8** | Any worker/cron/flag change or Track C run | [P1-QUEUE-RECONCILIATION.md](P1-QUEUE-RECONCILIATION.md) |
| **Public Registration & Auth** | `T1-03` | `php artisan test --compact tests/Feature/AuthApiTest.php` | `joyumkm_testing` | **PASS** (`17/17`, `114` assertions) | Public registration always assigns `user` (never `superadmin`/`analyst`), returns Sanctum token | **P2, P4, P5, P7** | `User`, `AuthService`, `RegisterRequest`, middleware changes | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) |
| **UMKM CRUD & Current Ownership** | `T1-04` | `php artisan test --compact tests/Feature/UmkmApiTest.php tests/Feature/UmkmSummaryApiTest.php tests/Feature/OwnershipApiTest.php` | `joyumkm_testing` | **PASS** (within `A2` `82/82`) | `business_email` & consent preserved; `user`/`analyst` isolated (`404`); `Superadmin` global in P4 | **P2, P4, P5, P7** | `UmkmPolicy`, `UmkmService`, `Store/UpdateUmkmRequest`, transfer API | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) |
| **3D Scoring & Questionnaire** | `T1-05` | `php artisan test --compact tests/Feature/EvaluationApiTest.php tests/Feature/ScoringServiceTest.php tests/Feature/ThreeDimensionHealthApiTest.php tests/Feature/DashboardApiTest.php` | Master sectors & 16 active questions | **PASS** (within `A2` `82/82`) + `npm test` `46/46` | 16 answers, exact normalized scores (`precision=4`/`1`), categories & immutability preserved | **P2, P3, P4, P5, P7** | `EvaluationService`, `ScoringService`, `DashboardService` | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) |
| **6F Scoring, Finalization & Outbox** | `T1-06`, `T1-07` | `php artisan test --compact tests/Feature/SixFactorEvaluationApiTest.php tests/Feature/SixFactorEvaluationServiceTest.php tests/Feature/SixFactorEvaluationDatabaseTest.php tests/Feature/SixFactorEvaluationConcurrencyTest.php` | `joyumkm_testing` | **PASS** (within `A3` `140/140`) | 6 scores `1..5`, `total 6..30`, single active draft, final/cancel immutable, 1 `evaluation.finalized` v2 event | **P2, P3, P4, P5, P7** | `SixFactorEvaluation*`, `HermesWebhookOutbox`, transfer locks | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) |
| **Intelligence Pipeline, PDF/XLSX, Consent & Delivery** | `T1-08`..`T1-13`, `T1-19` | Laravel A4 (`20` files) + JMI `pytest` + Track C Live UAT (when approved) | Isolated `joyumkm_testing` + Track C approved mailbox | Isolated A4 **PASS** (`273/274`, `1` skip); JMI `213/214`; **Live Track C `BLOCKED`** | `umkms.business_email` only (never `users.email`), consent/suppression enforced, SHA-256 artifacts, monotonic status sync | **P2, P4, P5, P7, P8** | Any intelligence/mail/transfer/download UI change | [P1-INTELLIGENCE-REPORT-UAT.md](P1-INTELLIGENCE-REPORT-UAT.md) |
| **Onboarding Webhook & Email** | `T1-14`, `T1-15` | `AuthApiTest` + `test_register.py` + Track C Live UAT (when approved) | Approved unique mailbox + `.env` `HERMES_ONBOARDING_*` | Isolated **PASS**; **Live Track C `BLOCKED`** | `user.registered` v1, encrypted `hermes-onboarding`, `beforeCommit`, reused by Analyst creation in P4 without token leak | **P4, P7, P8** | `AuthService`, `DeliverHermesOnboardingWebhook`, `AnalystProvisioningService` | [P1-ONBOARDING-EMAIL-UAT.md](P1-ONBOARDING-EMAIL-UAT.md) |
| **UMKM Predict (`:8651`)** | `T1-16` | `SixFactorPredictionApiTest.php` + live `:8651` boundary check | `HERMES_PREDICTION_ENABLED=true`, `:8651` Up | **PASS** (`5/5` boundaries + `422`, `0` DB writes) | Schema, HMAC, categories match `SixFactorEvaluation::categoryForTotal()` | **P4, P5, P6, P7** | Prediction request/controller/auth changes | [workflow-results.md](evidence/run-20261127-1918/workflow-results.md) |
| **Scheduled Monitoring (`umkm-monitoring`)** | `T1-17` | `SixFactorMonitoringApiTest.php` + `test_monitoring*.py` + read-only `SixFactorMonitoringController` check | `joyumkm_testing` + `joyumkm-v2` RO | **RSK-06 REPRODUCED** (`28` non-synth + `72` synth / `26 < 13`); isolated `50/50` PASS | **MANDATORY PRE-P3 GATE:** P2 MUST exclude all synthetic cohort UMKM from monitoring before P3 seeding & before cron unpause | **P2 (Pre-P3), P3, P6, P7** | **Must pass synthetic exclusion before P3 seeding** | [P1-HERMES-WORKFLOW-BASELINE.md](P1-HERMES-WORKFLOW-BASELINE.md) |
| **Conversational Analytics** | `T1-18` | `AnalyticsDatasetPlannerTest.php` + `AnalyticsDatasetSeederTest.php` + `test_mysql.py` + RO TLS query check | `joyumkm_p1_prod` (`P1..P5`) / `joyumkm-v2` (`P6+`) | **RO Queries PASS** (`428/1,284/1,284`, `7/7` rejections); `3` legacy `test_analytics_workflow` fails; live Telegram LLM `NOT RUN` | Cutover 3 `SQL SECURITY DEFINER` views + RO TLS grants to `joyumkm-v2` in P6 (`458/1,374/1,374`, `0` operational rows) | **P2, P3, P6, P7** | Cohort table/seeder, SQL views, RO user grants | [workflow-results.md](evidence/run-20261127-1918/workflow-results.md) |
| **Frontend UI Baseline** | `T1-03`..`T1-06`, `T1-10` | `npm test` + isolated Playwright UI smoke (`1440x900` & `390x844`) | Ephemeral `php artisan serve` on `joyumkm_testing` | `npm test` **46/46 PASS**; UI smoke **6/6 PASS** (`0` console/page errors); `7` legacy `*-browser.mjs` **FAIL** (drift) | PII-free Blade shells, role-aware SPA navigation, zero console/page errors | **P5, P7** | Any Blade/JS UI change | [ui-test-results.md](evidence/run-20261127-1918/ui-test-results.md) |
