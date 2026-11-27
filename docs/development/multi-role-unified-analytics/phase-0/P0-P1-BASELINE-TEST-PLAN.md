# P0-07 — P1 Baseline Test Plan & P2–P8 Regression Gates

- **Audit Timestamp:** `2026-11-27T18:45:00+07:00` (`Asia/Jakarta`)
- **Execution in P0:** **NONE** (Zero PHPUnit, SQLite, JS, or live workflow tests executed during P0 per PRD §4.2)
- **Gate Status (`G0-07`):** **PASS**

---

## 1. Existing Test Suite Inventory & Known Pre-Existing Failures

### 1.1 Automated Test Assets (Verified Statically)
- **PHPUnit (`phpunit.xml`):** Forces **MySQL `joyumkm_testing`** (`DB_CONNECTION=mysql`, `DB_DATABASE=joyumkm_testing`, `OPENWA_ENABLED=false`, `HERMES_WEBHOOK_ENABLED=false`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`). `tests/TestCase.php:21-24` aborts if not running against MySQL `joyumkm_testing`.
  - **47 Feature test files** (`406` test methods) — covering Auth/Onboarding, UMKM CRUD/Summary/Ownership, 3D Evaluation/Health, 6F Evaluation/Summary/Monitoring/Prediction/Recap/Period/Concurrency/Database, Webhook Outbox/Worker/Cron, Intelligence Snapshot/Callback/Manifest/Artifacts/UploadAuth/Storage/Download/Consent/RecipientPull/DeliveryStatus/Concurrency, DemoSeeder, SampleDataSeeder, AnalyticsDatasetSeeder.
  - **4 Unit test files** (`12` test methods) — `AnalyticsDatasetPlannerTest`, `HermesWebhookSignatureTest`, `IntelligencePdfValidatorTest` (invokes host `python` + `qpdf`), `ExampleTest`.
- **Frontend JS (`package.json:8`):** `6` Node unit test files (`tests/js/*.test.js`) + `7` mocked Playwright browser specs (`tests/js/*-browser.mjs`).
- **Hermes Visual Workflow Plugin (`C:\laragon\jobsheet4\plugin\tests\` + `/opt/data/plugins/visual-workflow/tests/`):** `pytest` suites covering canvas, schema, runner, onboarding, prediction, monitoring, MySQL AST validator, analytics workflow, and Telegram handler (`102` analytics tests + `218` JS5 plugin tests historically passed).

### 1.2 Historical Pre-Existing Failures (Must NOT Be Misclassified as New Regressions)
1. **`DemoSeederTest` (2 methods, historical P1 Stabilization):** Failed when `SEED_PASSWORD` was set in environment during `DatabaseSeeder` (expected 1 user, got 4 because `SampleDataSeeder` also ran).
2. **`SampleDataSeederTest` (3 methods, historical P1 Stabilization):** Legacy docblock `@dataProvider` (L78) incompatible with PHPUnit 12; `DRAFT_EMPTY` produces up to 4 evaluations (`max_evaluations + 1 > 3`); `Sector::delete()` constraint error.
3. **P4 Historical Baseline (`docs/evidence/p4-baseline-failures.txt`):** 12 failing tests recorded out of 436 total in an earlier P4 snapshot (exact live baseline to be re-measured cleanly in P1 on `joyumkm_testing`).
4. **Hermes Plugin Pre-Existing Failures:** `test_run_through_the_router_returns_the_delivery_record` (missing LLM key on host, Jobsheet 5 handoff L54); `test_monthly` 3 legacy failures (P6 completion L45); 1 tooltip email test (Jobsheet 4 handoff L79).
5. **Jobsheet 6 Conversational Analytics P7/P8/P10 PARTIAL:** Live Telegram `/analytics` message & `openai-codex` quota reset still pending.

---

## 2. Executable P1 Baseline Verification Plan (Proposed Commands — DO NOT RUN IN P0)

### 2.1 Prerequisites Before Starting P1
1. Confirm Laragon MySQL 8.4.3 (`joyumkm_testing` verified clean: `0` users/umkms, `22` migrations) and Docker Desktop `jobsheet2 running(3)` are healthy while keeping `JoyUMKM Hermes Webhook Worker`, `JoyUMKM Laravel Scheduler` **Disabled**, and all `3` Hermes crons **`[paused]`**.
2. **Safety Check Before Track C / Any Worker Activation:** Reconcile `.env` (`HERMES_WEBHOOK_ENABLED=true`), Hermes `email.env` (`JMI_EMAIL_DELIVERY_ENABLED=1 / PAUSED=0`), and the `2` pending `webhook_outbox` / `jobs` rows (`#23/#24` in `joyumkm-v2`) so no unintended live webhook or email dispatch occurs.
3. For **Track C (Controlled Real E2E UAT)** ONLY: obtain explicit written operator approval of: (a) test recipient email address(es), (b) single-event UAT scope pin (`hermes-uat-scope.json`), (c) approved Telegram test chat (`6293298092` or dry-run), and (d) explicit post-UAT cleanup/disable steps.

### 2.2 Track A — Isolated MySQL (`joyumkm_testing`) Automated Regression Baseline (No External Network)

Run targeted suites (never `migrate:fresh --env=testing` outside PHPUnit!):

```powershell
Set-Location C:\laragon\www\joyumkm

# A1. Auth, Public Registration & Onboarding Queue Contract
php artisan test --compact tests/Feature/AuthApiTest.php

# A2. UMKM CRUD, Ownership & Dashboard Baselines
php artisan test --compact tests/Feature/UmkmApiTest.php tests/Feature/UmkmSummaryApiTest.php tests/Feature/OwnershipApiTest.php tests/Feature/DashboardApiTest.php tests/Feature/ThreeDimensionHealthApiTest.php tests/Feature/SixFactorSummaryApiTest.php tests/Feature/EvaluationApiTest.php tests/Feature/ScoringServiceTest.php

# A3. Six Factor Lifecycle, Immutability, Prediction & Monitoring
php artisan test --compact tests/Feature/SixFactorEvaluationApiTest.php tests/Feature/SixFactorEvaluationServiceTest.php tests/Feature/SixFactorEvaluationDatabaseTest.php tests/Feature/SixFactorEvaluationConcurrencyTest.php tests/Feature/SixFactorPredictionApiTest.php tests/Feature/SixFactorMonitoringApiTest.php tests/Feature/SixFactorMonthlyRecapTest.php tests/Feature/SixFactorPeriodMonthTest.php

# A4. Protected Outbox, Snapshot, Callback, Artifact, Consent, Recipient & Delivery Status Contracts
php artisan test --compact tests/Feature/HermesWebhookOutboxTest.php tests/Feature/HermesWebhookCronTest.php tests/Feature/HermesWebhookWorkerTest.php tests/Unit/HermesWebhookSignatureTest.php tests/Feature/IntelligenceSnapshotApiTest.php tests/Feature/IntelligenceSnapshotServiceTest.php tests/Feature/IntelligenceCallbackTest.php tests/Feature/IntelligenceCallbackConcurrencyTest.php tests/Feature/IntelligenceManifestCallbackTest.php tests/Feature/IntelligenceArtifactUploadAuthTest.php tests/Feature/IntelligenceArtifactStorageTest.php tests/Feature/IntelligenceArtifactSchemaTest.php tests/Feature/IntelligenceArtifactDownloadTest.php tests/Feature/IntelligenceArtifactConcurrencyTest.php tests/Feature/IntelligenceRecipientConsentTest.php tests/Feature/IntelligenceRecipientPullTest.php tests/Feature/IntelligenceDeliverySchemaTest.php tests/Feature/IntelligenceDeliveryStatusTest.php tests/Feature/IntelligenceReportApiTest.php tests/Unit/IntelligencePdfValidatorTest.php

# A5. Analytics Dataset Planner & Seeder Baseline
php artisan test --compact tests/Unit/AnalyticsDatasetPlannerTest.php tests/Feature/AnalyticsDatasetSeederTest.php

# A6. Frontend Node Unit Tests
npm test
```

### 2.3 Track B — Hermes Read-Only & Plugin Automated Baseline (Docker Running, Zero Live Sends)

```powershell
Set-Location C:\laragon\jobsheet2
docker compose ps
docker exec hermes-agent hermes cron list   # Assert 3/3 jobs [paused]
Invoke-WebRequest -Uri http://127.0.0.1:9119/api/health -UseBasicParsing
Invoke-WebRequest -Uri http://127.0.0.1:8650/health -UseBasicParsing

# Plugin targeted tests (read-only against joyumkm_p1_prod views)
docker exec -u hermes hermes-agent sh -c 'cd /opt/data/plugins/visual-workflow && PYTHONPATH=/tmp/pytest-lib HERMES_ANALYTICS_TEST_LIVE=1 /opt/hermes/.venv/bin/python3 -m pytest -q tests/test_mysql.py tests/test_analytics_workflow.py tests/test_analytics_telegram.py tests/test_prediction.py'
```

### 2.4 Track C — Controlled Real E2E UAT (Requires Separate Operator Approval)

| UAT Scenario | Preconditions & Safety Controls | Expected Evidence | Cleanup |
|---|---|---|---|
| **UAT-01: Public Registration → Real Onboarding Email** | Approved test email address added to Hermes `allowlist` & `JOYUMKM_ONBOARDING_LIVE_RECIPIENT`; `HERMES_ONBOARDING_*` temporarily configured in `.env`; single `queue:work hermes-onboarding --once` run | HTTP `201` register, `jobs` row enqueued & processed, Hermes `202 accepted`, `receipts.sqlite3` `smtp_started=1`, SMTP DATA `250`, 0 duplicate sends on replay; optional inbox receipt if accessible | Remove test user (if dedicated UAT schema) or retain labeled UAT account; verify `0` pending receipts |
| **UAT-02: Six Factor Finalize → PDF/XLSX → Real Email to `business_email`** | Dedicated approved UAT UMKM with `business_email = <approved_test_recipient>` (distinct from `users.email`) & `notification_consent = grant`; UAT UUID pin (`hermes-uat-scope.json`) armed; `email.env` & `delivery-status.env` opened ONLY for pinned UUID | `webhook_outbox` `delivered` (202), snapshot 200, report callback 200, PDF+XLSX upload 200 (`artifact_status=complete`), recipient pull returns `business_email` (NOT `users.email`), SMTP DATA `250` (1 send), status sync `sent` + `provider_message_id`, UI download SHA-256 match | Return `email.env` / `delivery-status.env` to `ENABLED=0 / PAUSED=1`, remove `uat-scope.json`, verify Windows tasks Disabled & crons paused |
| **UAT-03: Six Factor Prediction Preview (`:8651`)** | `HERMES_PREDICTION_ENABLED=true`, `hermes-workflow-ingress` Up | `POST /api/umkms/{id}/six-factor-evaluations/predict` returns `200` matching `SixFactorEvaluation::categoryForTotal`; **0** new rows in `six_factor_evaluations` or `webhook_outbox` | None (stateless read-only) |
| **UAT-04: Monitoring Endpoint Read-Only Check** | `umkm-monitoring` cron stays `paused`; signed GET `/api/internal/monitoring/six-factor/latest-finals` | HTTP `200`, `meta.truncated=false`, operational count verified, zero Telegram messages sent | None |

---

## 3. Regression Gates for P2–P8 & Mandatory Stop Conditions

| Phase | Mandatory Verification Gates | Stop Condition (Halt & Remediate Immediately) |
|---|---|---|
| **P2 (Schema & RBAC)** | Migration up/down tests on `joyumkm_testing`; `UmkmPolicy` + `UserRole` unit/feature tests (`RBAC-01..05`); **implement & test monitoring isolation (`SixFactorMonitoringController` excludes synthetic cohort) as mandatory pre-P3 gate**; verify 0 regressions in Track A1–A5 | Any existing user loses credentials/data, `role` mass-assignable, immutability trigger weakened, `joyumkm-v2` modified without backup, or monitoring isolation incomplete |
| **P3 (458 Seeder)** | **Precondition: Monitoring isolation against synthetic dataset verified complete BEFORE running seeder!** Run `AnalyticsDatasetPlannerTest` + updated 458 `AnalyticsDatasetSeederTest`; verify 153/153/152 split, 1,374 3D / 1,374 6F / 21,984 answers, idempotent rerun preserving transferred `user_id`, **`0` outbox rows, `0` jobs rows, `0` emails/WA**, `demo@joyumkm.test`, `user#6` & `23` legacy `sample1/2/3` records untouched | Any outbound side effect during seeding, any deletion/modification of demo/operational/legacy rows, or `SixFactorMonitoringController` returning any synthetic record |
| **P4 (Superadmin & APIs)** | `RBAC-01..05`, `XFER-01..04`, `ACCT-01` (incl. multi-process concurrency MySQL tests); Analyst onboarding reuse test (no Sanctum token leaked, `user.registered` v1 intact) | Horizontal IDOR between Analysts, false 409 on `recheckWhatsapp`, wrong uniqueness scope on `UpdateUmkmRequest`, or duplicate email on transfer |
| **P5 (Frontend & Dashboard)** | Node `npm test` + Playwright browser specs (Superadmin global dashboard, Analyst/User scoped views, transfer UI, Analyst management, `403` handling, zero PII in Blade shells) | Console/page errors, broken Intelligence download/tab UI, or missing API-level enforcement behind UI controls |
| **P6 (Hermes Cutover)** | Cutover analytics 3 views + RO MySQL user (TLS CA, exact 3 `SELECT` grants) to `joyumkm-v2`; verify `analytics_umkms = 458`, `analytics_evaluations = 1,374`, `analytics_six_factor_evaluations = 1,374`, `out_of_cohort = 0`, 0 operational rows visible in views; verify `umkm-monitoring` excludes 458 synthetic UMKM; verify 7/7 workflows valid & 3/3 crons paused | Operational/PII rows visible to analytics RO user, `SELECT users` permitted, monitoring includes synthetic UMKM, or any workflow/container proliferation |
| **P7 (E2E & Security)** | Full targeted MySQL regression (Track A1–A6) + Security/IDOR/Concurrency matrix + Approved Track C controlled live E2E (public register, Superadmin real Analyst onboarding, 6F→PDF/XLSX→`business_email` real email, transfer during processing, no duplicate send, all Hermes workflows) | ANY regression in protected flows (`business_email` substitution, duplicate email, broken PDF/XLSX download, broken onboarding, cross-account leak) |
| **P8 (Controlled Rollout)** | Pre-cutover verified backup + checksums, maintenance/rollout checklist, post-rollout smoke checks, zero secret leaks, final handoff | Backup unverified, restore untested, or any open CRITICAL/HIGH regression |
