# PRD P0 — Comprehensive Read-only Audit & Preflight

**Project:** JoyUMKM — Multi-Role Management, Unified Analytics & Workflow Preservation  
**Phase:** P0 of P0–P8  
**Version:** 1.0 · 27 November 2026  
**Repository:** `https://github.com/joytimess/dip-joyumkm`  
**Expected local workspace:** `C:\laragon\www\joyumkm`  
**Execution agent:** Gemini 4 Neon · High effort (operator-selected)  
**Phase authorization:** P0 audit only  
**Implementation status:** NOT STARTED / no runtime verification by the PRD author

---

## 1. Mandate

Act as a senior Laravel software architect, MySQL data engineer, security reviewer, and Hermes integration auditor. Perform an evidence-driven, comprehensive **read-only audit** of the current JoyUMKM system. Establish the factual baseline, identify dependencies, enumerate risks, and prepare an executable verification and migration plan for later phases.

Do not implement RBAC, alter the dataset, run seeders, migrate schema, change integrations, or remediate findings in P0. P0 is an investigation and planning gate, not a feature delivery or a live workflow UAT. P1 will separately establish functional regression baselines.

Use actual repository files, local working-tree state, read-only database queries, and read-only runtime inspection. Treat every historical claim in this PRD as a hypothesis until verified. Report mismatches; never manufacture evidence or silently substitute an assumption for an observed fact.

## 2. Background and approved target

JoyUMKM currently supports UMKM assessments, historical evaluations and automated workflows via Hermes Agent. Prior repository reviews identified an analytics dataset generator targeting 428 synthetic UMKM, separate from the ordinary Laravel seed path. Historical handoff evidence referred to `joyumkm_p1_prod` as an isolated analytics/testing database and `joyumkm-v2` as the main application database. Neither its current runtime usage nor the current data counts have yet been confirmed for this phase.

The development target is a backward-compatible extension of the current system, with `joyumkm-v2` as the single operational source of truth for application data and analytics. These requirements are approved for later phases, not authorization to implement them in P0.

| Area | Approved target |
|---|---|
| Database | `joyumkm-v2`; analytics also reads this database through restricted read-only views |
| Synthetic dataset | Exactly 458 identifiable synthetic UMKM: retain/reconcile 428 existing generator records and add 30 |
| Analyst 1 | `sample1@joyumkm.test`, initially 153 synthetic UMKM |
| Analyst 2 | `sample2@joyumkm.test`, initially 153 synthetic UMKM |
| Analyst 3 | `sample3@joyumkm.test`, initially 152 synthetic UMKM |
| Superadmin | Global access and management of all UMKM and Analyst accounts; no need to own the synthetic cohort |
| Analyst | CRUD own UMKM and evaluations, subject to existing domain rules; no cross-account access |
| User | Existing users and public registrants retain own-data access and normal workflows |
| Demo | Preserve `demo@joyumkm.test`, its existing data and existing credentials |
| Existing accounts/data | Preserve real users, credentials, operational data, and relationships; no destructive resets |
| Ownership transfer | Superadmin may transfer UMKM between Analysts; preserve identities, history and artifacts; audit each transfer |
| Cohort membership | Independent of owner email/ID; initial allocation is not reimposed on legitimate subsequent transfers |
| Seeder | Automatic intended local path via `db:seed`; production explicit opt-in; idempotent; no outbound side effects |
| Registration | Public registration defaults to User; creation of a real Analyst through Superadmin reuses existing onboarding workflow (proposed implementation route from prior review; verify contract in P0) |
| Dashboard | Superadmin global; Analyst and User scoped to own records; existing design system |
| Hermes analytics | Entire synthetic cohort across all three Analysts, not arbitrary operational records |
| Scheduled monitoring | Keep operational scope intact and prevent synthetic records from crowding out existing records or causing false notifications; exact rule to be verified |

**Important count semantics:** 458 is the initial synthetic cohort size, not a requirement to reduce the total number of `umkms` rows in `joyumkm-v2` to 458. Existing demo and operational records must not be deleted to reach the target. Existing sample records must be classified and reconciled explicitly; do not assume they are identical to the analytics cohort.

### 2.1 Dataset acceptance targets for later phases

| Entity | Analyst 1 | Analyst 2 | Analyst 3 | Total |
|---|---:|---:|---:|---:|
| Synthetic UMKM | 153 | 153 | 152 | 458 |
| Three Dimension evaluations | 459 | 459 | 456 | 1,374 |
| Six Factor evaluations | 459 | 459 | 456 | 1,374 |
| Evaluations total | 918 | 918 | 912 | 2,748 |
| Questionnaire answers (assuming existing 16-answer contract per Three Dimension evaluation) | 7,344 | 7,344 | 7,296 | 21,984 |

Retain the existing 12-month historical coverage and existing scoring/category rules. Verify the answer count contract and all generator assumptions before treating these projected counts as executable assertions. Preserve existing valid seeded history; identify deterministic strategy for the additional 30 profiles and the associated missing history. Report the actual current cohort counts separately from the approved target.

## 3. Non-negotiable protected scope

The development must not rewrite or regress the existing business logic. Specifically:

1. Preserve `umkms.business_email` as-is: schema, field semantics, existing values, recipient resolution, and normal application behavior. Do not replace it with `users.email`, Analyst email, or Superadmin email. Do not add a global allowlist or globally disable real email. Existing consent, suppression and eligibility rules must remain intact.
2. Preserve Three Dimension and Six Factor scoring, assessment lifecycle, finalization and immutable/final evaluation rules.
3. Preserve the Six Factor → webhook/outbox → Hermes → PDF/XLSX → artifact manifest/upload/callback → Laravel download and Intelligence UI pipeline, plus real email delivery and status sync. Preserve established security and validation boundaries.
4. Preserve real registration/onboarding email flow, event contract, queue semantics, retry behavior and current environment constraints. Future role additions must not break registration. Seed-created sample accounts must not trigger onboarding.
5. Preserve all existing Hermes workflows, including prediction, monitoring, analytics, registration, intelligence reporting, recovery and any other workflow discovered in the actual runtime. Do not reset workflow state, routes, queues, persistent volume, schedules, SMTP configuration or secrets.
6. Preserve IDs, foreign keys, reports, artifact manifests, legitimate consent records, historical evaluations and pending events. No duplicate dispatch/email due to later ownership changes or seed reruns.
7. Preserve existing HMAC/authentication, event versioning, replay protection, recipient fingerprint, artifact integrity, callback authentication, idempotency and retry limits as applicable. Verify each from code; do not presume its exact implementation.

P0 must identify the minimum future modifications at authorization, dataset, analytics views/connection and monitoring boundaries. Any proposed protected-code change must be explicitly justified by evidence and separately reviewed before implementation.

## 4. Execution rules and safety boundary

### 4.1 Allowed

- Read repository files, local Git status/diffs/logs, tracked and untracked file names, current local commit SHA and local branch. Use remote read-only inspection if available, without changing Git state or fetching into the working tree.
- Read existing Graphify reports/graph artifacts if installed and available. Use them for discovery, then verify every important edge against source code. Do not rebuild or update Graphify artifacts during P0 unless separately authorized.
- Inspect MySQL schema, metadata, indexes, foreign keys, grants as permitted, counts and bounded aggregate queries using a verified read-only account or a safe read-only transaction. Use timeouts/bounded results; avoid long scans on operational tables. Avoid `SELECT ... FOR UPDATE`, stored routines/functions of unknown side effects, and write-capable commands. Do not broaden privileges to gain access.
- Inspect Docker/container metadata, Compose definitions, logs (bounded), process/queue status, workflow definitions and configuration with secrets redacted. Read-only filesystem inspection through `docker exec` is acceptable only if the command itself is non-mutating.
- Review existing test definitions and historical results, design P1 tests and provide commands as proposed instructions. Do not treat historical PASS as current live verification.
- Create Markdown audit deliverables and sanitized evidence files only under the designated P0 documentation/evidence output directory; no other writes. If that path conflicts with existing files, use a new timestamped evidence subdirectory, never overwrite existing handoffs.

### 4.2 Prohibited

- No edits to application code, models, routes, policies, tests, migrations, seeders, `.env`, Compose, gateway, Hermes scripts/workflows, SQL views or grants; no package installation or generated dependency updates.
- No `migrate`, `migrate:fresh`, `db:wipe`, `db:seed`, import, restore, SQL DML/DDL, database mutations or fixtures against any database.
- No registration, evaluation finalization, webhook replay/dispatch, workflow execution, AI invocation through Hermes, live SMTP send, WhatsApp/Telegram notifications, test email or callback invocation. P1 will handle controlled live testing with explicit scope and recipient approval.
- No full SQLite suite; no runtime test suite in P0 if it could create data, files, queue jobs or external side effects. Plan targeted MySQL testing for later phases.
- No container creation, restart, stop, removal, prune, compose up/down, volume mutation, service reset, queue restart, cron modification, network changes, or deployment.
- No Git checkout/reset/stash/clean/commit/push, and no production changes. Do not treat a database name containing `prod` as proof of production or non-production status.
- Never print, export or commit raw secrets (passwords, API tokens, SMTP credentials, HMAC keys, signed URLs, full env dumps, authorization headers), personal contact data or raw customer payloads. Redact logs and use aggregate evidence/opaque IDs. No raw `docker inspect` environment dumps or `.env` copies in reports.
- Maximum **3 subagents total**, only if supported; otherwise execute sequentially. Suggested division: Laravel/RBAC & business flows, MySQL/dataset, Hermes/runtime. The primary agent owns the final reconciliation and verdict.

If runtime access, safe DB access, source files or necessary permissions are missing, mark the relevant gate BLOCKED/PARTIAL with exact evidence and a proposed safe next step. Do not bypass safeguards. A missing runtime connection is not permission to alter configuration.

## 5. Audit work packages

### P0-01 — Repository and workspace baseline

- [ ] Verify repository remote, branch, commit SHA, local path and dirty working tree. Compare local source and referenced `main` revision where available, distinguishing local/uncommitted and remote facts.
- [ ] Inventory relevant Laravel architecture: auth, roles, controllers, middleware, policies, service/query scope, UMKM/evaluations, dashboard, seeders, integrations, queues, config, tests, README/PRDs/handoffs.
- [ ] Inspect Graphify availability and relevant dependency graph freshness; report missing/stale graph without generating a new one.
- [ ] Identify existing architecture constraints, module boundaries and files likely touched in P2–P6. Distinguish verified code from proposed additions.

**Evidence:** source inventory with file paths, line ranges, SHA/working-tree state; concise dependency map. Do not capture secret-bearing content.

### P0-02 — Database and seeder reconciliation

- [ ] Confirm configured Laravel DB connection and effective database name through non-mutating inspection; distinguish local, testing and production targets. Verify existence and actual purpose of `joyumkm-v2` and `joyumkm_p1_prod` if accessible.
- [ ] Inventory actual schema, migration state, `users`, `umkms`, Three Dimension and Six Factor tables, answer tables, sectors, outbox, report/artifact and suppression tables; use actual names discovered. Record constraints, relationships, indexes and storage engines.
- [ ] Read `DatabaseSeeder.php`, `DemoSeeder.php`, `SampleDataSeeder.php`, `AnalyticsDatasetSeeder.php`, analytics child seeders, planner, config, command, runbook and tests; resolve renamed paths if any.
- [ ] Confirm the default seed call graph, local/production gates, explicit flags, password inputs, ownership distribution, deterministic IDs/keys, seed profiles, history/answer generation and current safety guards. Report whether the existing analytics seeder can run against `joyumkm-v2` and why/why not; do not execute it.
- [ ] Verify sample1/2/3, demo, analytics synthetic account and any existing Superadmin through minimal redacted account metadata. Verify role/ownership state and whether role infrastructure already exists. Never disclose passwords or hashes.
- [ ] Count each known account's UMKM, evaluation totals, final/draft counts and cohort membership using read-only aggregate queries; distinguish synthetic, sample/demo, operational and unclassified records. Confirm current 428/458 claims or report differences.
- [ ] Determine whether a stable cohort identifier exists, whether ownership alone is currently the cohort boundary, and whether membership can be preserved after transfer. Identify overlap, orphan relations and uniqueness collisions without dumping raw user data.
- [ ] Assess data-preserving 428→458 reconciliation, existing sample records, potential reassignment, history preservation, seed rerun and rollback. Propose a deterministic plan, including how existing valid data and credentials are retained.
- [ ] Inspect seeding side effects: model observers, events, jobs, webhooks, notifications, scheduler and application services. Identify future per-operation suppression/isolation mechanism without changing global settings.

**Evidence:** actual-vs-target matrix; DB and seeder inventory; redacted account/ownership counts; dependency map; data reconciliation plan. If a DB cannot be queried, list unverified counts as UNKNOWN, never zero.

### P0-03 — RBAC and ownership impact audit

- [ ] Verify actual roles, registration defaults and role management; review `User` model, auth service, routes, middleware, policies and existing authorization checks.
- [ ] Map every affected read/write boundary: UMKM CRUD, evaluation CRUD/finalization, dashboard, reports/history, artifact downloads, monitoring and internal integration routes. Inventory route/method, guard, role, owner filter, actor identity and service dependency.
- [ ] Identify direct `user_id` checks and owner-based queries; separate authenticated actor from resource owner. Identify IDOR and privilege-escalation risks of future global Superadmin access.
- [ ] Examine ownership transfer feasibility, dependent FK relationships, evaluation/report ownership assumptions, consent provenance, soft deletion and active event races. Determine whether transfer should be blocked, deferred or otherwise safely coordinated if an in-flight event cannot survive ownership changes; no implementation or policy change in P0.
- [ ] Recommend minimal additive schema and centralized authorization approach. Existing evaluation finalization/immutability rules remain in force for every role.
- [ ] Determine safe onboarding path for real Analyst accounts without granting self-selected privileged roles through public registration. Flag discrepancies between actual behavior and approved target.

**Evidence:** access matrix (current vs target), route/service authorization inventory, ownership impact and proposed transaction/race test matrix.

### P0-04 — Protected flow: evaluation, PDF/XLSX and email

- [ ] Trace actual Three Dimension and Six Factor assessment lifecycle, persistence, scoring, validation and finalization.
- [ ] Trace the current finalization event and outbox: actual event name/version, event identity, transaction/dispatch boundary, queue, destination, authentication/HMAC, retries and deduplication. Historical `evaluation.finalized` v2 is a hypothesis to verify.
- [ ] Trace Hermes intake/snapshot, generation and validation of PDF/XLSX, upload/callback, artifact manifests/checksums, download authorization, report UI and delivery status. Record dependencies and contract fields (names/types only, not raw PII).
- [ ] Trace `business_email` resolution, notification consent, recipient eligibility, recipient fingerprint and suppressions. Verify no email recipient is implicitly substituted with the logged-in actor. Record existing handling of absent consent, invalid recipient, missing business email and changes during processing.
- [ ] Identify where role/ownership changes could affect snapshot reads, artifact access, callbacks, retries, pending delivery, or consent checks. Do not change business_email or its behavior. If a current consent rule affects a transfer, document the existing rule and propose a safe later-phase decision rather than bypassing it.
- [ ] Inspect historical and current queue/outbox/report/email states using aggregate read-only evidence. Distinguish queued, failed, accepted by SMTP and confirmed inbox delivery; do not claim receipt without evidence.
- [ ] Review tests and historical UAT evidence; map coverage and missing scenarios for P1 and P7 (including transfer during report processing and no duplicate email).

**Starting source pointers (verify actual paths):** `SixFactorEvaluationService.php`, `HermesWebhookOutbox.php`, `IntelligenceReportService.php`, `IntelligenceRecipientEligibilityService.php`, `IntelligenceRecipientFingerprint.php`, `IntelligenceSnapshotService.php`, `IntelligenceArtifactDownloadController.php`, `Umkm.php`.

**Evidence:** actual sequence diagram, protected contract inventory, dependency/race risks, pending-state summary, P1 baseline test matrix.

### P0-05 — Protected flow: registration and real onboarding email

- [ ] Trace actual public registration path, account persistence and onboarding dispatch. Identify any existing role selection or default behavior.
- [ ] Verify historical `user.registered` v1, `hermes-onboarding` queue and environment/receiver restrictions against current source and runtime configuration (masked).
- [ ] Trace Hermes onboarding receiver/workflow and real SMTP configuration presence, without exposing credentials or sending mail.
- [ ] Review existing idempotency, dispatch-after-commit behavior, retry and error handling. Record current evidence and limitations.
- [ ] Determine how future Superadmin-created Analyst accounts can reuse the existing onboarding pipeline without changing public registration contract, triggering seeded-account emails, or exposing privileged role assignment to the public.
- [ ] Identify controlled P1 baseline test plan for current registration and P4/P7 future Analyst creation; only approved test recipients, never arbitrary real addresses.

**Evidence:** actual sequence diagram, event/queue contract, configuration presence checks, current-vs-target registration matrix.

### P0-06 — Hermes runtime, Docker and all workflow dependencies

- [ ] Identify actual Docker topology, running/stopped services, Compose project, container names/images, mounts, networks, published ports, health, persistent state and ownership. Verify rather than assuming historical topology or a single container. Historical `C:\laragon\jobsheet2\docker-compose.yml`, `hermes-agent`, `hermes-workflow-ingress`, and `hermes-webhook-proxy` are discovery hints only.
- [ ] Inventory every actual workflow, trigger, enabled/paused state, dependencies, route, queue/scheduler, data source, output channel, authentication contract and last run evidence. Include unlisted workflows, plugins and relevant scripts. Do not run workflows.
- [ ] Inspect Laravel↔Hermes endpoint and gateway configuration safely; verify current routes, listener status, signature verification, callback paths, queue state and scheduled/recovery jobs. Report any known old issue as historical until reconfirmed.
- [ ] Verify configured SMTP/key presence and channel status from safe metadata/logs, never dump secret values. Distinguish configuration evidence from delivery proof.
- [ ] Inspect Conversational Analytics target DB, SQL views, allowed scope, SQL validator, read-only MySQL grant boundaries, and use of owner-based vs cohort-based filters. Plan controlled change to `joyumkm-v2` only in P6.
- [ ] Inspect Scheduled Monitoring actual query and capacity. Verify historical `MAX_UMKMS = 100` observation, ordering and pagination behavior; assess whether adding 458 synthetic UMKM would crowd out operational records or cause unwanted notifications. Propose compatible isolation/pagination only if evidence supports it.
- [ ] Review UMKM Predict, scheduled monitoring, registration, report generation/delivery, analytics, recovery/retry and all other discovered dependencies. Classify each as protected, controlled change or unaffected with rationale.
- [ ] Review storage/volume persistence and rollback feasibility. No container or workflow changes in this phase.

**Evidence:** actual runtime topology diagram; complete workflow inventory; integration/configuration contract matrix; analytics and monitoring impact assessment; logs/status references with timestamps and redactions.

### P0-07 — Preflight, evidence and rollback design

- [ ] Inventory existing tests, latest known results and missing cases. Separate historical failures from verified current runtime issues and later regression claims. Do not run full SQLite or side-effectful tests.
- [ ] Define P1 executable baseline plan: isolated MySQL, mocked contracts/queues where appropriate, and separately approved controlled real end-to-end UAT. Specify prerequisites, safe test fixtures, recipient authorization, expected evidence and cleanup approach. Do not create any fixtures now.
- [ ] Define regression gates for P2–P8; identify tests applicable per phase (targeted MySQL, concurrency, E2E and security), protected-flow contract comparisons and stop conditions.
- [ ] Design backup/rollback plan for database, Laravel config/schema, Hermes state/config/volume and workflow definitions. Inspect existing backup metadata if safely accessible, but do not create/restore operational snapshots or change runtime in P0. A real backup and restore rehearsal require separate authorization before mutating phases.
- [ ] Identify unresolved decisions, blockers, pre-existing failures, severity, owner, evidence and remediation recommendation. Do not fix them.
- [ ] Reconcile final local source state and observable runtime configuration against initial read-only snapshot. Distinguish naturally changing operational logs/queue counts from changes caused by audit actions. Record command/action log and confirm no unauthorized changes.

**Evidence:** P1 regression matrix, rollback plan, risk register, final integrity report and handoff.

## 6. Required inventory and evidence format

For each observation record: unique ID, timestamp (Asia/Jakarta or explicit timezone), scope/environment, source file/path and line range or sanitized command, observed result, interpretation, and status. If inspecting runtime, note whether data is live or historical and capture database/host labels safely.

### 6.1 Hermes workflow inventory fields

| Field | Requirement |
|---|---|
| Workflow ID/name | Actual runtime name, not invented |
| Trigger and state | Webhook/manual/scheduled; enabled/paused/unknown |
| Laravel dependency | Route/service/event/queue, if applicable |
| Data source | Database/view/API, no secret values |
| Output | Report/email/Telegram/other channel |
| Contract | Event version, auth, response/state dependencies |
| Evidence | Timestamped sanitized definition/log/status |
| Change classification | Protected / controlled / unaffected / unknown |
| Risk and P1 validation | Specific assertion and test requirement |

### 6.2 Risk register fields

`Risk ID | Severity | Verified evidence | Affected flow | Failure mode | Proposed mitigation | Phase | Status | Blocker/owner`

Use CRITICAL for data loss, cross-account leakage, unauthorized live sends, exposed secrets or destructive changes; HIGH for failure of protected workflows, unsafe ownership/consent or dataset migration; MEDIUM for bounded operational compatibility risks; LOW for minor maintainability/documentation gaps. Assign severity based on observed facts, not on unverified scenarios alone.

### 6.3 Proposed commands (read-only examples; adapt to actual access)

```powershell
Set-Location C:\laragon\www\joyumkm
git status --short
git rev-parse HEAD
git branch --show-current
git log -1 --format="%h %ad %s" --date=iso-strict
# Review git diff locally; redact sensitive content before writing evidence.
docker ps -a --format "table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}"
docker compose ls --all
# Use a verified project path and bounded, redacted logs only.
```

Use narrowly scoped `docker inspect --format` fields; never capture `.Config.Env`. For MySQL use an authorized read-only connection and read-only transactions; verify the selected database before aggregate queries. No credentials in commands or evidence. Do not execute Artisan commands that could boot observers/providers or dispatch side effects without verifying safety; prefer static inspection and direct read-only database metadata.

```sql
-- Illustrative only: run via an authorized read-only connection.
SELECT DATABASE();
START TRANSACTION READ ONLY;
SELECT COUNT(*) AS total_umkm FROM umkms;
SELECT user_id, COUNT(*) AS umkm_count FROM umkms GROUP BY user_id;
ROLLBACK;
```

Only run example SQL after confirming table/column names, target and acceptable load. Grouped owner IDs are for restricted audit evidence; do not export raw contact data. The queries above do not establish cohort identity by themselves.

## 7. P0 acceptance gates

| Gate | Mandatory acceptance criteria |
|---|---|
| G0-01 Repository baseline | Actual repo/branch/SHA, local changes, architecture and relevant dependencies documented; Graphify status recorded |
| G0-02 Database/seeder baseline | Actual DB targets and schema verified; counts and owner/cohort mapping supported by safe queries; seeder entrypoint, 428 vs 458 discrepancy and safe migration strategy documented |
| G0-03 RBAC impact | Existing authorization and target role matrix documented; critical route/owner/evaluation/report access and transfer risks identified |
| G0-04 Report protection | Actual finalization→Hermes→PDF/XLSX→Laravel/email contract mapped, business_email and consent behavior verified in code, runtime evidence/limitations recorded; future tests defined |
| G0-05 Onboarding protection | Existing registration/event/queue/environment rules documented, real-email path identified and future Analyst onboarding plan defined without weakening public role security |
| G0-06 Hermes audit | Actual topology, every accessible workflow and dependencies, analytics/monitoring scope and recovery/state risks inventoried; no runtime modifications |
| G0-07 Preflight | P1 test plan, backup/rollback plan, risk register, scope boundaries and required decisions documented; existing issues and missing evidence explicitly classified |
| G0-08 Read-only integrity | Action log and before/after verification show no unauthorized code/config/data/runtime changes; only approved audit documentation outputs created; secret handling verified |

**Verdict rules:** PASS = all mandatory audit criteria evidenced, with identified existing risks clearly recorded and no unaddressed blocker to safe P1 planning. PARTIAL = useful audit evidence exists, but important scope remains unverified or a pre-existing blocker requires a decision. BLOCKED = necessary access/evidence prevents a safe baseline or continuation. FAIL = unsafe action/unauthorized change, or audit output cannot establish the required integrity. Record gate-level status and distinguish evidence availability from functional test status. An audit PASS never means PDF/XLSX, SMTP or all workflows have passed live regression tests; that is P1/P7 work.

Do not proceed to P1 or remediate blockers without operator approval. For known baseline defects, propose disposition and safe next steps; do not silently mark them as new regressions.

## 8. Deliverables (Markdown only, sanitized evidence as needed)

Proposed repository-relative directory (documentation outputs only):

```text
docs/development/multi-role-unified-analytics/
└── phase-0/
    ├── P0-AUDIT-REPORT.md
    ├── P0-REPOSITORY-AND-DEPENDENCY-MAP.md
    ├── P0-DATABASE-SEEDER-AUDIT.md
    ├── P0-RBAC-AND-OWNERSHIP-AUDIT.md
    ├── P0-PROTECTED-FLOW-CONTRACTS.md
    ├── P0-HERMES-WORKFLOW-INVENTORY.md
    ├── P0-RISK-REGISTER.md
    ├── P0-P1-BASELINE-TEST-PLAN.md
    ├── P0-BACKUP-AND-ROLLBACK-PLAN.md
    ├── P0-FINAL-HANDOFF.md
    └── evidence/
        ├── repository-baseline.md
        ├── database-readonly-results.md
        ├── hermes-runtime-baseline.md
        └── audit-action-log.md
```

Consolidate repetitive content by linking relative evidence references. Include Mermaid diagrams in Markdown where useful: existing topology, seed call graph, finalization/report/email sequence, registration/onboarding sequence, and dependency impact map. Mark proposed diagrams as proposals, not current system facts. No screenshots are mandatory if sanitized text evidence is sufficient. Do not overwrite prior PRDs or jobsheet handoffs.

### Final handoff structure

1. Overall P0 verdict and evidence timestamp.
2. Gate matrix G0-01 to G0-08: status, evidence path, outstanding gaps.
3. Actual repository SHA/branch/dirty state and exact files written by the audit.
4. Actual data count and owner/cohort reconciliation vs target; distinguish total DB rows from synthetic cohort.
5. Actual protected business flows and the full Hermes workflow status inventory.
6. Prioritized risks and blockers; clear division of verified facts, hypotheses and proposed changes.
7. P1 preconditions, tests, expected evidence, approval requirements and rollback considerations.
8. Confirmation of no unauthorized mutations, no live messages sent, no workflow runs triggered and no secrets exposed by the audit (report exceptions explicitly).
9. Recommendation: READY FOR P1 REVIEW / NOT READY, with rationale. Stop here.

## 9. Phase boundary and roadmap reference

| Phase | Name | Status at P0 start |
|---|---|---|
| P0 | Comprehensive Audit & Preflight | Authorized — read-only |
| P1 | Existing Workflow Baseline | Awaiting approval |
| P2 | Database Schema & RBAC | Out of scope |
| P3 | Account & Dataset Seeder | Out of scope |
| P4 | Superadmin Management & Backend API | Out of scope |
| P5 | Role-Based Frontend & Dashboard | Out of scope |
| P6 | Hermes Integration Reconciliation | Out of scope |
| P7 | E2E Regression & Security | Out of scope |
| P8 | Controlled Rollout & Final Handoff | Out of scope |

Mandatory future regression targets include: current real registration email, real Analyst onboarding, Six Factor finalization→PDF/XLSX→real email to business_email, status sync, artifact download, no duplicate dispatch, all existing Hermes workflows, operational monitoring isolation, and analytics of the initial 458 synthetic cohort in `joyumkm-v2`. Preserve current Three Dimension functionality and existing account data. A regression introduced by a later phase is a stop-and-remediate condition.

## 10. Agent execution instruction

Begin P0 immediately using the actual local repository and accessible read-only runtime. Work through P0-01 to P0-07, evidence every gate, reconcile contradictions, and produce the Markdown deliverables. Do not ask the operator to repeat already-approved requirements. Where facts are missing, attempt safe inspection and report the limitation. Do not request or print secrets. Stop after the P0 final handoff. Do not start P1, implement fixes, run live flows, commit/push or deploy. The operator will review the audit and authorize subsequent phases separately.
