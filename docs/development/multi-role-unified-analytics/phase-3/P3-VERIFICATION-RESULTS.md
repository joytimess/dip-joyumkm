# P3 Verification Results

Evidence labels: **LIVE** = read-only/targeted query on local `joyumkm-v2`; **ISOLATED** = PHPUnit on MySQL `joyumkm_testing` (never the live DB); **CODE** = source review. Whole-database counts are not cohort counts.

## Before → after (LIVE)

| Metric | Before (P2) | After P3 | Target |
|---|---:|---:|---:|
| Cohort members / cohort UMKM | 428 | **458** (428 original + 30 new, keys distinct, 0 orphans) | 458 |
| Cohort 3D / 6F / answers | 1,284 / 1,284 / 20,544 | **1,374 / 1,374 / 21,984** | same |
| New members: evaluations each | – | 3 × 3D, 3 × 6F per UMKM (set of counts = {3}) | 3 / 3 |
| Non-cohort UMKM | 29 | **29**, signature of id/owner/name/updated_at identical (md5 unchanged by the run) | 29 |
| Whole DB UMKM / 3D / 6F / answers | 457 / 1,339 / 1,317 / 21,386 | **487 / 1,429 / 1,407 / 22,826** | 487 / 1,429 / 1,407 / 22,826 |
| Cohort `dataset_version` | v1 | **v2** (counts 458/1374/1374/21984, fingerprint `672a28aa…9726`) | – |

## Checks

| Check | Result | Evidence |
|---|---|---|
| V3-01 Membership & history | **PASS (LIVE)** | strict verifier on 458 members passed all 31 integrity checks (distributions, per-month 115/115/114/114 ×3, scores recomputed, WIB periods, no future timestamps, 0 outbox/job rows for cohort). Before any write, the same verifier on the 428 matched the stored v1 fingerprint, so existing histories/answers/names were unchanged. |
| V3-02 Allocation & preservation | **PASS (LIVE)** | owners: `sample1` (id 2) **153**, `sample2` (id 3) **153**, `sample3` (id 4) **152**; legacy account id 7 owns **0** UMKM (account/cohort row retained, cohort `user_id` still 7). `UNIQUE(user_id,name)` duplicates: 0. Non-cohort ownership by account unchanged (`{1:4, 2:11, 3:5, 4:7, 6:1, 8:1}`). User role/password/updated_at hash per user identical before/after reruns; cohort UMKM with non-null `business_email` or consent: 0. Mapping rule: members by `umkm_id`, position p → `sample{(p % 3)+1}`. |
| V3-03 Integration & compatibility | **PASS** | Verifier/exporter use member IDs (CODE); `analytics-dataset:export` wrote 458 rows (LIVE, file in scratchpad, not committed); `analytics-dataset:backfill-members` → "0 baris baru, total anggota 458" (LIVE, no repopulation); DatabaseSeeder policy test: `auto_seed` null → off outside local, `false` off, `true` on, local → on (ISOLATED). No production invocation. |
| V3-04 Idempotency | **PASS** | Live reruns of the exact path (`db:seed --class=AnalyticsDatasetSeeder`): twice, plus once more after the final code — "terverifikasi (no-op)", full state JSON (counts, owners, cohort row incl. `updated_at`, user hashes, outbox/jobs) **byte-identical** (LIVE). ISOLATED tests: legitimate rename + ownership change survive a rerun and are not reverted; missing member row fails closed without repair; legacy 428 + 30 reproduces the **same content fingerprint** as a fresh 458 seed; wrong/absent password irrelevant on a completed cohort. No live transfer was performed. |
| V3-05 Protected runtime | **PASS** | Monitoring selector `whereDoesntHave('datasetMember')`: eligible **29**, cohort members selected **0** (LIVE). Outbox 25 rows unchanged, pending **#23/#24** still pending, #25 delivered; jobs **#30/#31** unchanged; ownership transfers 0 (LIVE). Windows tasks "JoyUMKM Hermes Webhook Worker" and "JoyUMKM Laravel Scheduler" **Disabled**; Hermes crons not touched (not re-queried). Zero emails/WhatsApp/Telegram/LLM/workflow calls (seeder has no such path; counts above). `php -l` on all changed PHP and Pint pass. |

## Targeted tests (ISOLATED, `joyumkm_testing`)

- `AnalyticsDatasetSeederTest`: **25/25 pass** on the final code (updated to the v2 contract; 6 new; ~2.5 min).
- `DemoSeederTest` + `SampleDataSeederTest`: **29/29 pass** after hygiene fixes (baseline at P1/P2 start: Demo 2 failures; Sample 1 failure + 2 errors). Fixes: demo assertions scoped to the demo account (DatabaseSeeder in local also seeds the sample group, which was the overlap); `SampleDataSeeder` small-profile cap now counts the extra empty draft; the two Sample errors were test-definition defects (docblock `@dataProvider` ignored by this PHPUnit → `#[DataProvider]`; message passed as the connection argument of `assertDatabaseCount`). No live sample data touched.
- `RoleFoundationTest` (P2 protected RBAC): 6/6 pass.

## NOT RUN

Full PHPUnit, browser/Playwright, live report/onboarding UAT, any Hermes workflow or LLM call, live `php artisan db:seed` (full) on `joyumkm-v2` (deliberately narrowest path instead), fresh-DB local `db:seed` end-to-end with a real password, real transfer test on live data, backup restore rehearsal (P1 evidence reused).
