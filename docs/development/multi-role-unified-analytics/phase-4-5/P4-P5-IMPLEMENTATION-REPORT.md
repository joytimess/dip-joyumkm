# P4+P5 Implementation Report — Superadmin Management & Role-Based Frontend

**Status akhir (konfirmasi operator): P4+P5 CLOSED — WITH ACCEPTED LIMITATIONS — READY FOR P6 REVIEW.** Operator menyelesaikan manual browser UAT 12/12 dengan hasil PASS (login tiga role, dashboard global, filter Analyst, manajemen Analyst, dialog transfer, riwayat transfer, Tab Intelligence). UAT dilaporkan operator; tidak ada bukti mentah (screenshot/log) yang disimpan di repositori.

**Window (WIB):** start 22:37, implementation stopped ≈22:55 (deadline 23:07). Skills/agents actually used: graphify (query, update), direct implementation by the main agent. **No subagents were spawned**; Frontend Design / Superpowers / TDD skills were not invoked as named skills (existing design system reused by reading source).

## 1. Schema, migration, backup

| Item | Result |
|---|---|
| Migration | `2026_11_27_150000_add_is_active_to_users_table` — additive `users.is_active BOOLEAN DEFAULT 1` (guarded by `hasColumn`). All existing accounts became active; passwords/timestamps untouched. |
| Backup (before DDL) | `storage/app/p45-backup/joyumkm-v2-pre-p45.sql` (2.1 MB, `mysqldump --routines --triggers --single-transaction`; 8 triggers + data present; folder is git-ignored). |
| Rollback | `php artisan migrate:rollback --step=1` (drops only `is_active`). Do **not** roll back P3/P2 audit tables once transfers exist. |
| Audit table | Existing `umkm_ownership_transfers` reused unchanged (0 rows before and after). |

## 2. API (prefix `/api`, Sanctum, JSON envelope `data` / `meta`)

| Route | Notes |
|---|---|
| `GET /auth/me`, login/register responses | `UserResource` now adds `role`, `is_active` (additive). |
| `GET /umkms`, `/umkms/{id}` (Superadmin only) | adds `owner{id,name,role,is_active}`, `is_cohort_member`. Analyst/User payload unchanged. |
| `GET /umkms/summary` (Superadmin only) | adds `portfolio{total_analysts, active_analysts, three_dimension_evaluations, six_factor_evaluations, six_factor_final_evaluations, per_analyst[], umkms_by_owner_role}`. Evaluation counts follow the filter; `per_analyst` is always complete. |
| `?analyst_id=` on any GET portfolio/dashboard route | `ApplyAnalystFilter` middleware: Superadmin only (others 403), must be a real Analyst (422). Narrows `User::accessibleUmkms()` so list, counts, charts and summary agree. |
| `GET/POST /admin/analysts`, `GET/PATCH /admin/analysts/{id}`, `PATCH /admin/analysts/{id}/status` | `EnsureSuperadmin` (policy `manageAnalysts`, active Superadmin). Search/status/page; `role`, `is_active`, `password` prohibited on PATCH; status body `{is_active: bool}` idempotent. |
| `POST /admin/umkms/{id}/transfer` | body `expected_owner_id`, `to_user_id`, `reason` (5–255); 201 with `audit_id`, new/previous owner. `user_id` prohibited. |
| `GET /admin/ownership-transfers` | filters `umkm_id`, `from_user_id`, `to_user_id`; paginated; timestamps ISO (UI shows WIB). |

Errors: 401, 403, 404 (foreign UMKM, existing convention), 422, 409 with `{message, code}`. Transfer codes: `stale_owner`, `same_owner`, `invalid_source`, `invalid_target`, `target_inactive`, `name_collision`, `consent_active`, `six_factor_draft`, `three_dimension_draft`, `report_in_flight`, `delivery_in_flight`. Login of an inactive account: 403 `account_inactive` (only after the password verifies).

## 3. Authorization, status, onboarding

* `UmkmPolicy::transfer/manageAnalysts` now allow only an **active Superadmin**; no `Gate::before`.
* `EnsureAccountActive` runs on every authenticated route: an inactive account's tokens are deleted and the request gets 401. Deactivation (`AnalystService::setActive`) locks the user row, flips status and deletes all tokens in one transaction. Reactivation does not restore tokens (new login required). Only `role = analyst` rows are addressable (Superadmin/User → 404).
* Account creation reuses `AuthService::createAccount()` (extracted from `register()`): role set server-side, onboarding `user.registered` v1 pushed to the existing `hermes-onboarding` database queue inside the same transaction (visible only after commit; rolled back with it). Edit, status change and transfer never enqueue onboarding. No credentials in response/job payload (existing contract unchanged).
* Email edit: allowed only when no job exists in the `hermes-onboarding` queue (no verified email-change mechanism exists) otherwise 409 — limitation, see handoff.

## 4. Transfer locking and consent policy (`OwnershipTransferService`)

Lock order everywhere: **`umkms` row → `users` rows ascending by id**. Account status changes lock only `users`, so no cycle. After locks: re-authorize actor (Gate), compare `expected_owner_id`, source/target role = Analyst, target active, name collision (DB collation), consent, drafts, in-flight work; then update `umkms.user_id` and insert exactly one audit row in the same transaction (2 attempts for deadlocks; duplicate-key → `name_collision`).

In-flight definition (from source): any 6F draft; latest 3D evaluation = draft; `webhook_outbox` `evaluation.finalized` rows `pending/processing/retry_wait`; delivered event with no `intelligence_reports` row within 24 h; report `artifact_status = pending`; `intelligence_deliveries` `queued/failed_retryable`. Active notification consent blocks (conservative P4 default; no consent is cleared/copied). Parent-row lock now also taken by `EvaluationService::store` (3D, previously unlocked) and `UmkmService::delete`; 6F draft/finalize/cancel and `UmkmService::update` already locked the row.

Deletion safety: members of `analytics_dataset_members` cannot be deleted through CRUD (409); FK 1451 on `umkm_ownership_transfers` maps to 409 instead of an SQL error.

## 5. Frontend (existing Blade + Vite + vanilla JS workspace)

* `resources/js/role.js` — role label, WIB formatting, session-scoped Analyst filter (cleared on login/logout). `auth.js` hydrates `body[data-role]` from `/auth/me`; `[data-role-only]` navigation reveals after hydration.
* Navigation: group **Administrasi** (Manajemen Analyst, Riwayat Transfer) for Superadmin only; topbar role label from role.
* Routes `/admin/analysts`, `/admin/transfers` (`admin-analysts.blade.php`, `admin-transfers.blade.php`, modules `pages/admin-analysts.js`, `pages/admin-transfers.js`); module-level role guard shows an access-denied state; server remains authoritative.
* `admin-portfolio.js` — global panel on Beranda (KPIs, per-Analyst table, owner-role split, Analyst filter that reloads the page so every widget agrees), owner filter on Data UMKM, transfer dialog (active Analysts excluding source, mandatory reason, preservation notice, server-error code hints, no optimistic success).
* `umkms.js` — owner line (name/role/inactive/synthetic marker) in table and cards; “Pindahkan kepemilikan” action only for Analyst-owned records.
* `api.js` — appends `analyst_id` to portfolio GETs; `ApiError.code` carries the 409 reason code. Passwords live only in the dialog form, which is reset/emptied on close.

## 6. Changed files (summary)

Backend: `User`, `UmkmPolicy`, `AuthService`, `UmkmService`, `UmkmSummaryService`, `EvaluationService`, `UmkmController`, resources (`User`, `Umkm`, `UmkmSummary`, new `Analyst`, `OwnershipTransfer`), 3 middleware, 2 exceptions, 2 services, 2 controllers, 5 admin requests, routes, `bootstrap/app.php`, migration. Frontend: files listed above. Tests: new `SuperadminManagementTest`; `RoleFoundationTest` placeholder test updated to the new contract (P2 “deny all” → “Superadmin only”).
