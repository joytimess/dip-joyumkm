# P1 — Existing Workflow Baseline & Controlled UAT Report

- **Project:** JoyUMKM — Multi-Role Management, Unified Analytics & Workflow Preservation
- **Phase:** P1 of P0–P8 · Run ID: `run-20261127-1918`
- **Repository Anchor:** `main` @ `aaa05da4a1d88fa72ec95401c6ab6d80a35213f7`
- **Overall Run 1 Verdict:** **`BLOCKED / HOLD`** *(All isolated MySQL, Node, Playwright smoke, read-only Hermes analytics, stateless `:8651` prediction, monitoring defect reproduction, and backup capture completed cleanly; Track C live Report & Onboarding UAT gates `G1-04`, `G1-05`, `G1-06` and live `G1-07`/`G1-08` paths remain `BLOCKED` pending operator preflight authorization)*

---

## 0a. P1 Final Closure Update (2026-11-27)

- **Closure verdict:** `P1 CLOSED — WITH ACCEPTED LIMITATIONS`. P2 not started. Full record: [P1-FINAL-HANDOFF.md](P1-FINAL-HANDOFF.md), section "P1 Final Closure".
- **Email delivery:** G1-05 and G1-06 inbox receipt confirmed by the operator. Status moves from `SMTP ACCEPTED — INBOX CONFIRMATION PENDING` to **PASS**.
- **Reconciled gates:**
  - **G1-02:** closed as a limited baseline. Laravel dispatch isolation proven (`#25` only). Hermes-side isolation not met under `normal` mode. Mandatory P2 requirement.
  - **G1-03:** closed as known baseline. Seeder and legacy browser spec failures are `KNOWN_BASELINE_FAILURE`, not product regressions. Passing evidence is from Run 1 and was not re-run: 565 PHPUnit PASS, 46 JS PASS, 6 Playwright smoke PASS.
  - **G1-07:** accepted PARTIAL. Telegram, WhatsApp and analytics LLM live checks recorded as `NOT RUN`. RSK-06 must be fixed in P2 before P3 seeding.
  - **G1-08:** accepted PARTIAL. Ambiguous-outcome test is an environment-dependent defect (`JMI_HOME`), logged as P2 backlog. Live retry path not exercised.
- **Known baseline failures:** `DemoSeederTest` (2), `SampleDataSeederTest` (1 fail, 2 errors), 7 legacy `tests/js/*-browser.mjs` specs, `test_analytics_workflow.py` (3), `test_commands.py` (9), and the environment-dependent `test_email_status_sync.py` ambiguous-outcome test. None re-run in closure.
- **Test-count note:** Run 1 §3 totals (`595` / `589` pass) and the G1-03 per-track figures (`565/566`) are not reconciled. Cite per-track figures with their source.
- **Blockers:** no material P1 closure blocker. Open P2 entry conditions: RSK-06 and Hermes-side isolation.

## 0. Run 2 Update — Track C Live UAT (`run2-20261127-2015`)

- **Overall Run 2 Verdict:** **`PARTIAL / HOLD — NOT READY FOR P2 REVIEW`**. Live report and onboarding were executed. Both emails were accepted by SMTP. Inbox confirmation is pending. Gates G1-03, G1-07 and G1-08 remain `PARTIAL`.
- **Evidence folder:** [evidence/run2-20261127-2015/](evidence/run2-20261127-2015/) (14 files). Run 1 evidence in [evidence/run-20261127-1918/](evidence/run-20261127-1918/) is retained unchanged.
- **Final handoff:** [P1-FINAL-HANDOFF.md](P1-FINAL-HANDOFF.md) §0.

### Run 2 test and step matrix

| Step / Test | Gate | Run 2 action | Observed | Status | Classification |
|---|---|---|---|---|---|
| Step 1 Preflight | G1-01, G1-02 | Container, DB, task, cron, worker, flag and checksum checks | All as expected; flags identified | **PASS** | — |
| Step 2 Restore rehearsal | G1-01 | Temp schema `joyumkm_p1_restore_rehearsal`: checksum, restore, structure, data, FK, drop | Structure and data equal live; 0 non-NULL FK orphans; temp schema dropped | **PASS** | — |
| Step 3 Scoped dispatch | G1-02 | Gate proof, targeted `deliver(25)`, queue state before and after | Only `#25` delivered (202). `#23`/`#24`/`#30`/`#31` untouched. Hermes-side pin inert under `normal` | **PASS** (Laravel) · **PARTIAL** (Hermes) | Mode trade-off recorded |
| Step 4 Temp config | G1-09 | Snapshot, temporary keys, key-level diff | Applied and recorded; `OPENWA_ENABLED=false` added (not in list, reverted) | **PASS** | — |
| Step 5 Report live UAT | G1-04, G1-05 | Fixture `457`, evaluation `1317`, outbox `#25`, Hermes job, callback, artifacts, email, status sync | 1 LLM; PDF/XLSX verified; SMTP accepted; status synced | **PASS** (G1-04 API-verified) · **PARTIAL** (G1-05 inbox) | — |
| Step 6 Onboarding live UAT | G1-06 | Pre-check: `clussy14` absent. Registration `201` (`users.id=8`). Queue `hermes-onboarding` one pass. Login. | 1 onboarding email SMTP accepted; receiver `done`; login `200` | **PARTIAL** (G1-06 inbox) | — |
| Step 7 Inbox evidence | G1-05, G1-06 | Consolidated handoff requested | Not received | **PENDING** | `SMTP ACCEPTED — INBOX CONFIRMATION PENDING` |
| Step 8 Baseline reconciliation | G1-03, G1-07, G1-08 | Re-run seeders (same failures). Root-cause ambiguous outcome test (disposable copy, two home cases) | Seeder failures unchanged. Ambiguous test: env-dependent defect; no submit, no duplicate | **PARTIAL** | `KNOWN_BASELINE_FAILURE` (seeders, legacy specs, analytics, FakeCtx) · `ENVIRONMENT_DEPENDENT_TEST_DEFECT` (ambiguous outcome) |
| Step 9 Config rollback | G1-09 | Restore `.env`, pin, Hermes state, token, container guard files | All checksums match snapshot; end state as mandated | **PASS** | — |

### Run 2 limitations (explicit)

- Telegram alerts, live WhatsApp checks, and additional analytics LLM invocations were **not** executed (not authorized).
- Tab Intelligence was verified through its download endpoint only. No browser click-through was performed.
- Inbox receipt is unconfirmed for both emails.
- Carried Run 1 items (legacy browser specs, analytics formatting, FakeCtx, intelligence 273/274) were not re-executed in Run 2.
- Hermes-side isolation is not enforced under `normal` mode, which is the mode needed for artifact generation.

---

## 1. Mandatory Test Matrix (`T1-01` through `T1-21`) — Run 1 baseline, retained

| Test ID | Gate ID | Actual Test / Command | DB / Env | Isolation | Expected | Observed | Exit / Status | Evidence Path | Failure Classification | Timestamp (WIB) |
|---|---|---|---|---|---|---|---|---|---|---|
| `T1-01` | `G1-01` | `inspect_runtime.php` + `backup_p1.py` | `joyumkm-v2`, `joyumkm_p1_prod`, `joyumkm_testing` | Read-only + restricted backup outside repo | Local targets proven, fresh backup with SHA-256 | `joyumkm-v2` `456` UMKM, `joyumkm_testing` `0`, backup `21.6MB` Hermes + `1.99MB` SQL; restore `NOT RUN` | `0` / **PARTIAL** (restore `NOT RUN`) | [backup-metadata.md](evidence/run-20261127-1918/backup-metadata.md) | — | `2026-11-27 19:26` |
| `T1-02` | `G1-02` | Read-only outbox/jobs/JMI state & script audit | `joyumkm-v2` + `hermes-agent` | Read-only | `#23/#24` & `#30/#31` preserved, flags reconciled, scope proven | `#23/#24` & `#30/#31` untouched; `2` JMI failed jobs unrelated; `dev-operation.env` `normal` nuance found | `0` / **PASS** (hard stop active for Track C) | [queue-and-flags.md](evidence/run-20261127-1918/queue-and-flags.md) | — | `2026-11-27 19:24` |
| `T1-03` | `G1-03` | `php artisan test --compact tests/Feature/AuthApiTest.php` + `p1_ui_smoke.mjs` | `joyumkm_testing` | Isolated MySQL + ephemeral server | Register/login, duplicate/invalid input rejected | `17/17` PHPUnit PASS (`114` assertions); UI `/register` & `/login` PASS | `0` / **PASS** | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) | — | `2026-11-27 19:26` |
| `T1-04` | `G1-03` | Track A2 (`UmkmApiTest`, `OwnershipApiTest`, etc.) + `p1_ui_smoke.mjs` | `joyumkm_testing` | Isolated MySQL | Own-data CRUD works; cross-account `404`; `business_email` intact | `82/82` PHPUnit PASS (`887` assertions); UI UMKM flow PASS | `0` / **PASS** | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) | — | `2026-11-27 19:28` |
| `T1-05` | `G1-03` | Track A2 (`EvaluationApiTest`, `ScoringServiceTest`) + `npm test` | `joyumkm_testing` + Node | Isolated MySQL + Node | 16 Qs, score/category boundaries, draft/final rules | Included in A2 `82/82` PASS + Node `46/46` PASS | `0` / **PASS** | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) | — | `2026-11-27 19:28` |
| `T1-06` | `G1-03` | Track A3 (`SixFactorEvaluation*` 8 files) | `joyumkm_testing` | Isolated MySQL | 6F bounds, summary, finalization, 1 outbox event | `140/140` PHPUnit PASS (`1,398` assertions) | `0` / **PASS** | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) | — | `2026-11-27 19:29` |
| `T1-07` | `G1-03` | `SixFactorEvaluationConcurrencyTest`, `HermesWebhookOutboxTest` | `joyumkm_testing` | Isolated MySQL | Duplicate finalization idempotent, 0 extra event | Included in A3 (`140/140`) & A4 (`273/274`, `1` skip) PASS | `0` / **PASS** | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) | — | `2026-11-27 19:35` |
| `T1-08` | `G1-04` | Track C Live Report Event (`evaluation.finalized` v2 -> Hermes) | `joyumkm-v2` + Hermes | Live controlled UAT | Correlated fresh webhook & snapshot without old queue consumption | Not executed (awaiting Track C preflight approval) | — / **BLOCKED** | [preflight-and-authorization.md](evidence/run-20261127-1918/preflight-and-authorization.md) | `ENVIRONMENT_BLOCKER` (auth pending) | `2026-11-27 20:03` |
| `T1-09` | `G1-04` | Track C Live PDF/XLSX + Isolated A4 Artifact Tests | `joyumkm-v2` / `joyumkm_testing` | Isolated A4 + Live UAT | Live PDF/XLSX generation, manifest, SHA-256 upload/callback | Isolated A4 `273/274` PASS; Live Track C not executed | — / **BLOCKED** (live) | [P1-INTELLIGENCE-REPORT-UAT.md](P1-INTELLIGENCE-REPORT-UAT.md) | `ENVIRONMENT_BLOCKER` (auth pending) | `2026-11-27 20:03` |
| `T1-10` | `G1-04` | `IntelligenceArtifactDownloadTest` + `p1_ui_smoke.mjs` + `intelligence-report-browser.mjs` | `joyumkm_testing` | Isolated MySQL + Playwright | Authorized download PASS, unauthorized rejected, UI clean | A4 download PASS; UI smoke PASS; `7` legacy `*-browser.mjs` FAIL (`KNOWN_BASELINE_FAILURE`); live UAT download `BLOCKED` | `1` / **PARTIAL** | [ui-test-results.md](evidence/run-20261127-1918/ui-test-results.md) | `KNOWN_BASELINE_FAILURE` (legacy specs) | `2026-11-27 20:00` |
| `T1-11` | `G1-05` | Track C Live Report Email | `joyumkm-v2` + Gmail SMTP | Live controlled UAT | 1 email to `business_email`, SMTP 250 + inbox receipt | Not executed (awaiting approved mailbox & preflight approval) | — / **BLOCKED** | [P1-INTELLIGENCE-REPORT-UAT.md](P1-INTELLIGENCE-REPORT-UAT.md) | `ENVIRONMENT_BLOCKER` (auth pending) | `2026-11-27 20:03` |
| `T1-12` | `G1-05` | Track C Delivery Status Sync | `joyumkm-v2` + Hermes | Live controlled UAT | Synchronized `sent` status + UI reflection | Not executed (awaiting Track C preflight approval) | — / **BLOCKED** | [P1-INTELLIGENCE-REPORT-UAT.md](P1-INTELLIGENCE-REPORT-UAT.md) | `ENVIRONMENT_BLOCKER` (auth pending) | `2026-11-27 20:03` |
| `T1-13` | `G1-08` | `IntelligenceRecipientConsentTest`, `IntelligenceRecipientPullTest` | `joyumkm_testing` | Isolated MySQL | Missing consent/invalid recipient/suppression rejected, 0 real mail | Included in A4 (`273/274`, `1` skip) PASS | `0` / **PASS** | [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) | — | `2026-11-27 19:35` |
| `T1-14` | `G1-06` | Track C Live Onboarding Email | `joyumkm-v2` + `:8650` + SMTP | Live controlled UAT | 1 registration -> `user.registered` v1 -> 1 real email | Not executed (awaiting unique mailbox & `.env` approval) | — / **BLOCKED** | [P1-ONBOARDING-EMAIL-UAT.md](P1-ONBOARDING-EMAIL-UAT.md) | `ENVIRONMENT_BLOCKER` (auth pending) | `2026-11-27 20:03` |
| `T1-15` | `G1-06` | `AuthApiTest` + `test_register.py` + `test_delivery.py` | `joyumkm_testing` + `hermes-agent` | Isolated MySQL + `tmp_path` | Duplicate/invalid/rollback/unauthorized recipient rejected | `AuthApiTest` `17/17` PASS; `test_register.py` & `test_delivery.py` in `37/37` PASS | `0` / **PASS** | [workflow-results.md](evidence/run-20261127-1918/workflow-results.md) | — | `2026-11-27 19:36` |
| `T1-16` | `G1-07` | `SixFactorPredictionApiTest` + `verify_predict_monitoring.php` (`:8651`) | `joyumkm_testing` + `:8651` | Stateless live ingress | Valid boundary scores match domain categories; invalid `422` | `5/5` boundaries + `422` PASS (`0` DB writes) | `0` / **PASS** | [workflow-results.md](evidence/run-20261127-1918/workflow-results.md) | — | `2026-11-27 19:51` |
| `T1-17` | `G1-07` | `verify_monitoring_only.php` + `test_monitoring*.py` | `joyumkm-v2` RO + `tmp_path` | Read-only + isolated | Isolated logic PASS; reproduce `100`-row synthetic leak; `0` TG alerts | Isolated `50/50` PASS; live RO `28` non-synth + `72` synth (`26 < 13`), `truncated=true` | `0` / **PASS** (reproduced RSK-06; ops unsafe) | [workflow-results.md](evidence/run-20261127-1918/workflow-results.md) | Pre-existing defect `RSK-06` | `2026-11-27 19:52` |
| `T1-18` | `G1-07` | `verify_hermes_p1_safe.py` (`execute_query` + `validate_query`) + `test_mysql.py` | `joyumkm_p1_prod` RO TLS | Read-only + AST validator | `428/1,284/1,284` counts, aggregates, `7/7` unsafe SQL rejected | RO queries PASS; `7/7` negative checks rejected; `3` `test_analytics_workflow` formatting fails; live TG/LLM `NOT RUN` | `0` / **PARTIAL** | [workflow-results.md](evidence/run-20261127-1918/workflow-results.md) | `KNOWN_BASELINE_FAILURE` (`3`) + `RSK-12` | `2026-11-27 19:55` |
| `T1-19` | `G1-08` | Track A4 + JMI `pytest` (`8` recovery/email/sync suites) | `joyumkm_testing` + `tmp_path` | Isolated MySQL + `tmp_path` | Idempotent dedupe, bounded retry, status-only no-resend, old failed jobs intact | Laravel `273/274` PASS; JMI `213/214` PASS (`1` `blocked` vs `delivery_unknown`, `0` SMTP calls); live retry `BLOCKED` | `1` / **PARTIAL** | [retry-idempotency-results.md](evidence/run-20261127-1918/retry-idempotency-results.md) | Pre-existing / uninvestigated JMI test `1` | `2026-11-27 19:55` |
| `T1-20` | `G1-07` | All `7` Visual Workflows + `DemoSeederTest` + `SampleDataSeederTest` | `hermes-agent` + `joyumkm_testing` | Structural + isolated + RO | All workflows inventoried with functional evidence where safe | `5/7` functional/RO/isolated evidence; `2` manual structural PASS; `2` Track C live `BLOCKED`; `DemoSeederTest`/`SampleDataSeederTest` `5` known fails | — / **PARTIAL** | [P1-HERMES-WORKFLOW-BASELINE.md](P1-HERMES-WORKFLOW-BASELINE.md) | `KNOWN_BASELINE_FAILURE` | `2026-11-27 19:58` |
| `T1-21` | `G1-09` | Post-Run-1 Git/DB/Hermes/Task verification | Host + all DBs + Docker | Read-only check | Approved changes only; old jobs, cohort, cron/tasks untouched | `joyumkm-v2` unchanged (`456` UMKM, `#23/#24` pending, `#30/#31` queued), `3/3` crons `[paused]`, `2/2` tasks `Disabled`, `0` secret leaks | `0` / **PASS** | [runtime-before-after.md](evidence/run-20261127-1918/runtime-before-after.md) | — | `2026-11-27 20:02` |
