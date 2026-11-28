# P4+P5 Verification Results

**Status akhir (konfirmasi operator): P4+P5 CLOSED — WITH ACCEPTED LIMITATIONS — READY FOR P6 REVIEW.** Operator menyelesaikan manual browser UAT 12/12 dengan hasil PASS (login tiga role, dashboard global, filter Analyst, manajemen Analyst, dialog transfer, riwayat transfer, Tab Intelligence). UAT dilaporkan operator; tidak ada bukti mentah (screenshot/log) yang disimpan di repositori.

Target for all writes: isolated MySQL `joyumkm_testing` (`TestCase` aborts unless database is `joyumkm_testing`). Live `joyumkm-v2` received only the additive `is_active` migration. No live Analyst creation, transfer, consent edit, credential reset, worker, mail, HTTP call to Hermes or report generation.

## Commands and results

| Check | Command | Result |
|---|---|---|
| New focused suite | `php artisan test tests/Feature/SuperadminManagementTest.php` | 3 tests PASS |
| Related regression (existing auth, UMKM, dashboards, summary, 3D, 6F, seeder/cohort, role foundation, ownership) | `php artisan test` on 14 files | **199 passed, 6,631 assertions** (before the delete-lock tweak) |
| After delete-lock tweak | `UmkmApiTest`, `SuperadminManagementTest`, `SixFactorEvaluationDatabaseTest` | 41 passed |
| Frontend build | `npm run build` | built OK (only the pre-existing chunk-size warning) |
| JS unit tests | `npm test` | 46 pass / 0 fail |
| Page shells | `curl http://joyumkm.test/{home,umkms,admin/analysts,admin/transfers}` | all HTTP 200 (static shells; data comes from authenticated API) |
| Style | `vendor/bin/pint --dirty` | passed |

Not run (by PRD): full PHPUnit suite, Playwright, full seeder, live UAT.

## Evidence per requirement

| ID | Evidence | Status |
|---|---|---|
| V45-01 | `/auth/me` returns `role`+`is_active`; unauthenticated 401, Analyst/User 403 on `/admin/*`; Analyst list shows no `owner` and no `portfolio`; own-scope intact; `analyst_id` rejected for Analyst (403) and for non-Analyst ids (422); registration with `role=analyst` stored as `user`; deactivation → old token 401, login 403 `account_inactive`; reactivation does **not** revive the old token; Superadmin cannot be addressed via status route (404). | PASS |
| V45-02 | Create → 201, role `analyst` server-side, email normalized, no password in response, exactly **1** `hermes-onboarding` job, no outbound HTTP (`Http::preventStrayRequests`); `role` in body → 422 with no job; duplicate email → 422 with no new job; name edit, status change → job count unchanged; email edit with queued onboarding → 409; public registration adds its own single job. | PASS |
| V45-03 | Happy path: owner changes, exactly 1 audit row (umkm, from, to, real actor), `business_email` unchanged, old owner 404, new owner 200, repeat request → `stale_owner` with still 1 audit row; denied with 409 codes: `stale_owner`, `same_owner`, `target_inactive`, `invalid_target` (User account), `name_collision`, `consent_active`, `three_dimension_draft`; non-Superadmin 403; invalid payload 422; forced audit-insert failure → 500 and owner unchanged / 0 audit rows (rollback); history endpoint filtered by `umkm_id`, Analyst 403; delete after transfer → 409. Branches `six_factor_draft`, `report_in_flight`, `delivery_in_flight`, `invalid_source`, deadlock retry verified by **code review only**. Lock order reviewed (see Implementation Report §4); no concurrency harness. | PASS (grouped assertions + review) |
| V45-04 | Build OK, JS tests pass, routes/shell 200. **No interactive browser session** (no safe local credentials; no password reset allowed; no Playwright). Intelligence tab click-through **not performed**. | build/source (agen) + manual browser UAT operator 12/12 PASS, termasuk Tab Intelligence | PASS (UAT operator) |
| V45-05 | Read-only live counts after changes identical to P3 baseline (below); outbox #23/#24 pending, #25 delivered, jobs #30/#31 (`hermes-webhooks`) untouched; monitoring-exclusion test (`RoleFoundationTest`) passes; migration + backup recorded. | PASS |

## Before / after (live `joyumkm-v2`, read-only)

| Item | P3 baseline | After P4+P5 |
|---|---|---|
| Users (superadmin / analyst / user) | 1 / 3 / 5 | 1 / 3 / 5, all `is_active=1` |
| UMKM / 3D / 6F / answers | 487 / 1,429 / 1,407 / 22,826 | 487 / 1,429 / 1,407 / 22,826 |
| Cohort members | 458 | 458 |
| sample1 / sample2 / sample3 UMKM | 164 / 158 / 159 | 164 / 158 / 159 |
| Ownership transfers | 0 | 0 |
| Outbox #23 / #24 / #25 | pending / pending / delivered | pending / pending / delivered |
| Jobs #30 / #31 | present | present (`jobs` total 2) |

## Gate matrix

| Gate | Status | Basis |
|---|---|---|
| G45-01 Authentication & RBAC | PASS | V45-01; frontend role routing present in source |
| G45-02 Analyst Management | PASS | V45-02 (fake/no-network dispatch) |
| G45-03 Ownership Transfer | PASS | V45-03; unverified branches listed above |
| G45-04 Role-Based Dashboard | PASS (API) | list + summary filter consistency tested; dashboard chart endpoints share the same scope by construction but are not separately asserted; UI not exercised |
| G45-05 Management Frontend | PASS | build + shells + source oleh agen; manual browser UAT 12/12 PASS oleh operator (termasuk Tab Intelligence) |
| G45-06 Workflow Preservation | PASS | 199 existing/related tests, protected state unchanged, no outbound events |
| G45-07 Final Integrity | PASS | backup, migration, before/after, pending state, timing |

**Overall: P4+P5 CLOSED — WITH ACCEPTED LIMITATIONS — READY FOR P6 REVIEW.** Batasan yang diterima: cabang transfer `six_factor_draft`, `report_in_flight`, `delivery_in_flight`, `invalid_source` dan retry deadlock hanya diverifikasi lewat code review; live UAT yang mengirim email (onboarding) tidak diulang; bukti UAT operator tidak diarsipkan sebagai artefak.
