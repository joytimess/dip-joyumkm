# P3 Implementation Report — Account & Analytics Dataset Seeder

- **Window:** 2026-11-27 22:03:56 → (see Final Handoff for end/elapsed). Deadline 22:33:56 (+07:00).
- **Skills/agents actually used:** none of the optional Superpowers skills or subagents were available/needed; direct implementation from the PRD. Graphify hooks pointed at the seeder/planner files; source was read directly (graph is stale for these files, not rebuilt). No subagents.
- **Database:** local MySQL `joyumkm-v2` (live); tests only on isolated MySQL `joyumkm_testing`.

## 1. Design (membership is the source of truth)

| State (cohort `dataset_version`) | Seeder behavior |
|---|---|
| no cohort | create deterministic **458** (428 base plan + 30 expansion), membership, allocation 153/153/152; requires 3 Analyst accounts + synthetic-account password |
| `v1` (legacy 428) | verify 428 (strict verifier + stored fingerprint), append 30, one-time owner allocation, mark `v2` — all in one transaction under one lock |
| `v2` (completed) | **no-op**: membership-only verification (count, unique keys, no orphans, each member still has ≥3 final 3D/6F). No password needed; owner/name/fingerprint are never compared, so P4 transfers and later evaluations are not "drift" |
| other / inconsistent | fail closed, no repair |

Completion marker = existing cohort metadata `dataset_version = v2` (+ counts, fingerprint, `completed_at`); no schema change. Legacy `user_id` (historical account ID 7) is kept as provenance only.

## 2. Determinism / identity

- `generator_seed` unchanged (`joyumkm-analytics-v1`); base `plan()` unchanged except an internal count parameter, so the 428 never shift. Verified live: the stored v1 fingerprint matched the re-inspected 428 before any write.
- 30 new profiles = `AnalyticsDatasetPlanner::planExpansion()` (indices 428..457, own shuffle scopes `*-v2`). Sectors 8/7/6/5/4, 3 per city, 90 Enam Faktor totals (22/23/23/22 per band; 20 trend triples + 10 stable). Names `<Noun> <Brand> 429..458`; contact fields null (same as existing cohort: no email/phone/consent).
- Expected distributions are computed per count (`expectedCityCounts/SectorCounts/SixFactorCategoryCounts/PerMonthCounts`), so one verifier serves 428 and 458.
- Fresh create ≡ legacy + append: covered by a test that compares the content fingerprint of both routes.

## 3. Allocation

Members ordered by `umkm_id` (= plan index); owner = `analyst_emails[position % 3]` (sample1, sample2, sample3) → 153/153/152 for 458. Existing 428 are re-owned with a bounded `UPDATE umkms SET user_id` (no `updated_at` touch, guarded by `user_id = <cohort account>`, row count asserted). `UNIQUE(user_id,name)` collisions are pre-checked case-insensitively against the Analysts' non-cohort UMKM and among planned names; failure aborts without renaming. No redundant owner references exist (only `umkms.user_id`; consent-by-user is nullable and null for the cohort). Audit table `umkm_ownership_transfers` was **not** written: it requires a truthful `performed_by_user_id` and P4 semantics; the one-time allocation is recorded here and by the deterministic rule above.

## 4. Changed files (all uncommitted)

- `config/analytics_dataset.php` — `dataset_version=v2`, `legacy_version=v1`, `analyst_emails`, `auto_seed` (null/true/false), `password`.
- `app/Services/Analytics/AnalyticsDatasetPlanner.php` — `planExpansion`, expectation helpers.
- `app/Services/Analytics/AnalyticsDatasetVerifier.php` — selects by member IDs, parametrized by count; new `inspectMembership()`.
- `app/Services/Analytics/AnalyticsDatasetExporter.php` — membership-based, count from cohort (CSV format/order unchanged).
- `app/Console/Commands/AnalyticsDatasetBackfillMembersCommand.php` — verifies/no-ops once membership exists; owner-based backfill only for legacy v1 with zero members.
- `database/seeders/AnalyticsDatasetSeeder.php`, `database/seeders/Analytics/BusinessProfileSeeder.php` — state machine above; fixed lock name `joyumkm:analytics-dataset`.
- `database/seeders/DatabaseSeeder.php` — analytics integration (see §5).
- `tests/Feature/AnalyticsDatasetSeederTest.php` — updated to the v2 contract; new tests for allocation, fail-closed without Analysts, legacy→v2 equivalence, legitimate-change preservation, inconsistent membership, DatabaseSeeder policy.

## 5. Standard seeding

- Command (local): `php artisan db:seed` (Demo/Sample unchanged, then analytics) or narrowly `php artisan db:seed --class=AnalyticsDatasetSeeder --force`. Explicit wrapper: `php artisan analytics-dataset:seed --password-file=<file>`.
- Config: `ANALYTICS_DATASET_AUTO_SEED` (unset → on only when `APP_ENV=local`; `true`/`false` parsed as booleans, string `false` is false). Production default off; opting in is documented only, not executed. Outside local, errors are re-thrown; in local a missing prerequisite (e.g. no password) is a warning and the rest of `db:seed` continues.
- Prerequisites: 3 sample Analyst accounts (local: `joyumkm:map-sample-analysts` is invoked automatically **only while the cohort is not yet v2**), and for a *fresh* cohort a synthetic-account password via `--password-file/--password-stdin`, `ANALYTICS_DATASET_PASSWORD` (config `analytics_dataset.password`), or interactive prompt. Never printed. Existing/completed datasets need no password.

## 6. Deviations / not done

- `DemoSeederTest`/`SampleDataSeederTest` hygiene: see Verification Results (not modified unless stated there).
- Legacy owner-based verifier/exporter paths were replaced, not kept as fallback (the PRD forbids owner-based inclusion).
