# P2 Implementation Report — Database Schema & RBAC Foundation

Scope: PRD P2 only. No commit, push, deploy, seeding, email, workflow run or LLM call. P3 not started.

## 1. Schema delta (local `joyumkm-v2`, MySQL 8.4.3, applied 2026-11-27)

| Migration (order) | Delta | Rollback / recovery |
|---|---|---|
| `2026_11_27_134425_add_role_to_users_table` | `users.role varchar(20) NOT NULL DEFAULT 'user'` + CHECK `users_role_check` (`superadmin`/`analyst`/`user`). No index (small table, never a filter). Existing rows become `user` through the column default; no row rewritten otherwise. | `down()` drops the CHECK then the column. Safe: no other data touched. |
| `2026_11_27_134426_create_analytics_dataset_members_table` | New table. FK to `analytics_dataset_cohorts` (restrict), FK to `umkms` (cascade). `umkm_id` UNIQUE (one cohort per UMKM). `UNIQUE(cohort_id, synthetic_key)`; `synthetic_key` = generator identity (UMKM name, unique per owner). Old cohort row and its `user_id` untouched. | `down()` drops the table; the backfill command rebuilds it. |
| `2026_11_27_134427_create_umkm_ownership_transfers_table` | New append-only audit table (umkm, from/to/actor, reason, `transferred_at`). CHECK `from <> to`; BEFORE UPDATE/DELETE triggers reject changes. FKs restrict. No writer exists in P2. | `down()` drops triggers and table. Do not roll back once P4 writes history. |

MySQL DDL commits implicitly. Each migration is a separate file, so a partial failure leaves earlier migrations applied; recovery is `migrate:rollback --step=N` for these files only, or restore from the backup in the handoff. Backfill is a separate, idempotent command (`analytics-dataset:backfill-members`), not part of any migration.

## 2. Roles and accounts

- `app/Enums/UserRole.php`: backed enum; `fromStored()` maps any unknown value to `User` (fail closed, never privileged).
- `app/Models/User.php`: `userRole()`, `isSuperadmin()`, `accessibleUmkms()`. `role` is not in `#[Fillable]`; registration (`AuthService::register`) writes an explicit field list, so a `role` input is ignored and the DB default `user` applies.
- `joyumkm:map-sample-analysts`: exact three emails `sample1..3@joyumkm.test` → `analyst`. Never creates accounts, never touches passwords, skips a Superadmin. Result: 3 analysts, 5 users.
- `joyumkm:provision-superadmin`: local/testing only, idempotent, never overwrites an existing account, password only from env `JOYUMKM_SUPERADMIN_PASSWORD` (12–72 chars), bypasses the registration/onboarding path. The agent had no secure credential, so it created no account. The operator later ran the command manually and reported exactly one `superadmin@joyumkm.test` with role `superadmin` (provisioning complete, G2-02 PASS).

## 3. Authorization (single scope + policy)

- `User::accessibleUmkms()`: `Umkm::query()` for Superadmin, own `umkms()` otherwise. Replaced `$user->umkms()` at every read/update/lock boundary:
  `UmkmService` (list/detail/update lock/WhatsApp recheck), `SixFactorSummaryService` (portfolio + latest-final subquery), `ThreeDimensionHealthService`, `SixFactorEvaluationService` (row lock), `IntelligenceReportService::ensureOwned` (reports + artifact download), `SixFactorEvaluationRequest`, `SixFactorPredictionRequest`. Dashboard goes through `UmkmService::queryWithLatest`, so it is covered.
- Creation stays own-creation (`$user->umkms()->make`); no owner injection. `umkms.user_id`, `business_email`, snapshot identity unchanged by actor.
- `UpdateUmkmRequest`: name uniqueness is now evaluated against the resource owner, not the actor.
- `UmkmService::update`: granting notification consent by a non-owner → 403. Revoke stays allowed. Superadmin does not bypass consent.
- `app/Policies/UmkmPolicy.php`: view/update/delete = own or Superadmin; `transfer` and `manageAnalysts` return false for every role (P4 placeholders). No `Gate::before`.
- Existing 404 convention preserved (foreign and missing ids are indistinguishable). Hermes HMAC/internal routes untouched.

## 4. Cohort backfill and monitoring (RSK-06)

- `analytics-dataset:backfill-members`: provenance = UMKM owned by each cohort's `user_id` (the verifier's own key); aborts without writing if the count differs from `cohort.umkm_count`. Ran twice: first 428 rows, second 0 (idempotent).
- `SixFactorMonitoringController`: `whereDoesntHave('datasetMember')` added before `orderBy/limit` (`SixFactorMonitoringController.php:26`). Same shape, ordering, cap 100, status codes. `meta.count/truncated` come from the filtered set.

## 5. Hermes scoped isolation (P2-06)

Gap: normal-mode intake popped `JMI_UAT_SCOPE_ENABLED`, and every downstream handler only checked the env flag, so a pin file had no effect in `normal` mode.

Source of truth: `/opt/data` is the Docker volume `hermes_data` (persistent). Patched with a backup first.

- `jmi/store.py`: new `uat_scope_requested()` (env flag **or** existing `uat-scope.json`); `uat_allows()` uses it. A present but invalid/expired/closed pin file is "requested" and denies (fail closed).
- 11 call sites in `callback, delivery, email_delivery, email_status_sync, engine, pipeline, snapshot` changed from `env == "1" and not uat_allows(...)` to `not uat_allows(...)`, so process/recover/snapshot/callback/delivery/status-sync/email send all consult the same guard.
- `scripts/joyumkm_intake.py`: in `normal` mode, if a scope is requested it sets `JMI_UAT_SCOPE_ENABLED=1` and keeps `JMI_ARTIFACT_PIPELINE_ENABLED=1` / `JMI_ARTIFACT_DELIVERY_ENABLED=1`; otherwise behaviour is unchanged. Non-normal modes unchanged.
- Behaviour notes: scoped jobs no longer get the automatic corrective-authorization (same as other scoped modes). A leftover pin file blocks ordinary intake until it is renamed (existing convention `uat-scope.json.removed-after-*`).
- New test `tests/test_scope_mode_independent.py` (no network, mock model/post).

## 6. APP_URL

`joyumkm.test` serves JoyUMKM (`/up` 200, `/api/questionnaire` 401); `localhost:8000` serves another app (404). `.env` `APP_URL` changed `http://localhost:8000` → `http://joyumkm.test` (pre-change copy in the backup). No config cache present. Hermes state env files already use `joyumkm.test`.

## 7. Not done / out of scope

Seeder/browser/analytics test hygiene, `JMI_HOME` pinning in the ambiguous-outcome test (not directly relevant), P3–P6 work, production, secrets rotation.
