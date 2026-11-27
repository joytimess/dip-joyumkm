# P0-03 — RBAC & Ownership Impact Audit

- **Audit Timestamp:** `2026-11-27T18:35:45+07:00` – `2026-11-27T18:44:44+07:00` (`Asia/Jakarta`)
- **Gate Status (`G0-03`):** **PASS** (100% verified from source code across routes, controllers, FormRequests, services, models, migrations, and frontend shell)

---

## 1. Current Role & Authorization Baseline (Verified)

| Dimension | Observed Current Implementation | Source Reference |
|---|---|---|
| Role schema | **None.** `users` has only `id`, `name(120)`, `email` (unique), `email_verified_at`, `password`, `remember_token`, `timestamps`. | `database/migrations/0001_01_01_000000_create_users_table.php:14-22`, `app/Models/User.php:16-35` |
| Auth package & tokens | Laravel Sanctum `^4.0` stateless Bearer tokens (`guard => []`), wildcard abilities `['*']`, 7-day expiry (`SANCTUM_TOKEN_EXPIRATION_DAYS`), stored in browser `localStorage['joyumkm.token']`. | `config/sanctum.php:42`, `app/Services/AuthService.php:76-87`, `resources/js/api.js:1-2` |
| Policies / Gates / Enums | **None.** `app/Policies/` and `app/Enums/` do not exist; `AppServiceProvider` defines 0 Gates/Policies. | `app/Providers/AppServiceProvider.php:22-39` |
| Registration default | `POST /api/auth/register` creates a flat `User` with no role and immediately issues a 7-day Bearer token. | `routes/api.php:28`, `app/Services/AuthService.php:26-52` |
| Authorization enforcement | Scattered inline ownership checks (`$user->umkms()` / `where('user_id', $user->id)`) across **4 FormRequests** and **6 Services**, returning `404` on cross-tenant access. | See Section 3 below |
| Error envelope for `403` | `bootstrap/app.php:96-109` has **no `403` case** (falls through to generic `'Permintaan tidak dapat diproses.'`), whereas `resources/js/api.js:71` expects `403` `'Anda tidak memiliki akses ke bagian ini.'`. | `bootstrap/app.php:96-109`, `resources/js/api.js:71` |
| Frontend role awareness | Hardcoded single-role copy (`Pendamping UMKM` / `Ruang Pendamping UMKM`), static 4-group sidebar, hardcoded SPA regex in `workspace.js:166`. `UserResource` returns only `{id, name, email}`. | `resources/views/layouts/app.blade.php:24`, `components/navigation.blade.php:1-22`, `resources/js/workspace.js:166`, `app/Http/Resources/UserResource.php:14` |

---

## 2. Access Matrix — Current vs Approved Target

| Capability / Boundary | Current (All Users Identical) | Target: `superadmin` | Target: `analyst` | Target: `user` (Existing & Public Registrants) |
|---|---|---|---|---|
| Public self-registration (`POST /api/auth/register`) | Open (no role) | N/A (never self-assigned) | N/A (never self-assigned) | **Default role = `user`** (unchanged contract) |
| Manage Analyst accounts (list, create, update, deactivate) | Does not exist | **Full CRUD + trigger onboarding on real Analyst creation** | **403 Forbidden** | **403 Forbidden** |
| View & manage all UMKM (`GET/POST/PUT/PATCH/DELETE /api/umkms*`) | Own UMKM only | **Global access across all owners** (with optional owner filter) | Own UMKM only (404 on others) | Own UMKM only (404 on others) |
| Transfer UMKM ownership between Analysts | Does not exist | **Allowed via dedicated audited endpoint** (preserves IDs, history, reports, cohort) | **403 Forbidden** | **403 Forbidden** |
| Three Dimension & Six Factor CRUD / Finalization / Cancel / Predict | Own UMKM only; 6F final/cancel immutable | Global access subject to **identical** domain & immutability rules (final/cancel remain immutable for ALL roles) | Own UMKM only; domain & immutability rules preserved | Own UMKM only; domain & immutability rules preserved |
| Dashboard analytics (`factor-profile`, `distribution`, `comparison`, `three-dimension-*`, `six-factor-summary`, `umkms/summary`) | Own UMKM only | **Global portfolio** (+ optional owner/analyst filter) | Scoped to own UMKM | Scoped to own UMKM |
| Intelligence Reports & PDF/XLSX Artifact Downloads | Own UMKM only | **Global read & download** | Own UMKM only (404 on others) | Own UMKM only (404 on others) |
| Internal / Hermes HMAC endpoints (`/api/internal/*`) | Server-to-server HMAC only (no Sanctum) | Unchanged (HMAC only) | Unchanged (HMAC only) | Unchanged (HMAC only) |

---

## 3. Complete Route & Service Authorization Inventory (Actor vs Resource Owner)

All 11 Web routes (`routes/web.php:7-17`) are unauthenticated Blade HTML shells with zero PII; all 8 Internal routes (`routes/api.php:32-59`) use dedicated HMAC middleware; all 34 authenticated API routes (`routes/api.php:61-114`) use `auth:sanctum`.

### Every Direct `user_id` / `$user->umkms()` Check in `app/`

| # | File & Line(s) | Current Code Pattern | Actor vs Owner Distinction | RBAC / Superadmin Trap & Required Fix |
|---|---|---|---|---|
| 1 | `app/Services/UmkmService.php:27, 48-54` | `$user->umkms()->getQuery()` in `queryWithLatest()` & `owned()` | Resource access scope | Primary choke point. Replace with centralized `Umkm::accessibleBy($actor, $ownerFilterId)` / `UmkmPolicy`. |
| 2 | `app/Services/UmkmService.php:97, 102` | `$user->umkms()->make(...)` + `notificationConsentAttributes($user)` | **Conflated:** `user_id` = owner, `notification_consent_by_user_id` = **actor** | Separate `$actor` vs `$owner` when Superadmin creates/assigns UMKM. |
| 3 | `app/Services/UmkmService.php:117, 160` | `$user->umkms()->whereKey($id)->lockForUpdate()->firstOrFail()` (`L117`); `'notification_consent_by_user_id' => $user->id` (`L160`) | `L117` = access scope; `L160` = **actor** (keep!) | Refactor `L117` to access-scoped lock; keep `L160` recording `$actor->id`. |
| 4 | `app/Services/UmkmService.php:175` | `$user->umkms()->whereKey($id)->where('whatsapp_number', $number)->update(...)` | **HIDDEN WRITE TRAP** | If `owned()` allows Superadmin but `L175` stays `$user->umkms()`, `update()` hits 0 rows and throws false `409`. Scope by accessible UMKM ID! |
| 5 | `app/Http/Requests/Umkm/StoreUmkmRequest.php:28, 53` | `Rule::unique('umkms', 'name')->where('user_id', $this->user()->id)`; `'user_id' => ['prohibited']` | **Conflated** | Keep `user_id` prohibited for Analyst/User; if Superadmin specifies target Analyst `user_id`, validate uniqueness against target owner `user_id`. |
| 6 | `app/Http/Requests/Umkm/UpdateUmkmRequest.php:21, 31-32` | `authorize()` calls `owned()`; `Rule::unique('umkms', 'name')->where('user_id', $this->user()->id)->ignore($id)` | **CRITICAL CONFLATION BUG** | If Superadmin edits Analyst A's UMKM, `L31` checks name uniqueness against Superadmin's `user_id` instead of `$umkm->user_id`! Must scope uniqueness to `$umkm->user_id`. |
| 7 | `app/Http/Controllers/Api/UmkmController.php:93` | `$request->safe()->except(['record_id', 'user_id'])` | Mass-assignment defense | Preserve! Keep ownership transfer strictly on a dedicated Superadmin transfer endpoint. |
| 8 | `app/Services/ThreeDimensionHealthService.php:92` | `$user->umkms()->getQuery()->toBase()` (does NOT call `UmkmService`) | Direct scope bypass | Must refactor to shared access scope for global Superadmin dashboard. |
| 9 | `app/Services/SixFactorSummaryService.php:83, 121` | `L83`: `$user->umkms()->getQuery()->toBase()`; **`L121`: `Umkm::query()->toBase()->select('id')->where('user_id', $user->id)`** | **DOUBLE-FILTER TRAP** | Updating only `L83` leaves `L121` empty for Superadmin, falsely showing all Analysts' UMKMs as having no final evaluation! Both must use shared scope. |
| 10 | `app/Http/Requests/Evaluation/StoreEvaluationRequest.php:20` | `$umkmService->owned($this->user(), $id)` | Resource access gate | Centralize via `UmkmPolicy`. |
| 11 | `app/Services/EvaluationService.php:25, 37` | Calls `$umkmService->owned($user, $id)` **without `lockForUpdate()`** | **CONCURRENCY GAP** | Under MySQL `REPEATABLE READ`, a concurrent ownership transfer can race with 3D `store()`. Add `lockForUpdate()` on `umkms` in `store()`. |
| 12 | `app/Http/Requests/Evaluation/SixFactorEvaluationRequest.php:16` | `abort_unless($this->user()->umkms()->whereKey($this->umkmId())->exists(), 404)` (unsafe methods only) | Direct `$user->umkms()` check | Replace with centralized `UmkmPolicy`. |
| 13 | `app/Http/Requests/Evaluation/SixFactorPredictionRequest.php:18` | `abort_unless($this->user()->umkms()->whereKey($id)->exists(), 404)` | Direct `$user->umkms()` check | Replace with centralized `UmkmPolicy`. |
| 14 | `app/Services/SixFactorEvaluationService.php:123` | `lockOwnedUmkm()`: `$user->umkms()->whereKey($umkmId)->lockForUpdate()->firstOrFail()` | Duplicate `$user->umkms()` lock | Refactor to lock via centralized access scope. |
| 15 | `app/Services/IntelligenceReportService.php:250` | `ensureOwned()`: `$user->umkms()->whereKey($umkmId)->exists()` (also used by `IntelligenceArtifactDownloadController:81`) | Direct `$user->umkms()` check | Replace with centralized `UmkmPolicy` so Superadmin can read reports & download PDF/XLSX. |
| 16 | `app/Services/DashboardService.php:41, 67, 95, 106` | Delegates to `UmkmService::queryWithLatest($user)`; `comparison()` checks count match | Portfolio scope | Verify Superadmin optional `owner_id` filter validation (must reject `owner_id` filter from Analyst/User to prevent horizontal IDOR!). |
| 17 | `app/Providers/AppServiceProvider.php:35-38` | Rate limiters keyed by `$request->user()->id` | **Actor** (correct) | Keep keyed by authenticated actor. |

---

## 4. Ownership Transfer Feasibility, FK Coupling & Concurrency Races

### 4.1 Safe Properties (Verified in Migrations & Services)
- **Zero child operational FKs reference `umkms.user_id`:** `evaluations`, `evaluation_answers`, `six_factor_evaluations`, `webhook_outbox` (payload contains `umkm_id`, no `user_id`), `intelligence_reports`, `intelligence_artifact_manifests`, `intelligence_artifacts` (filesystem path `{event_id}/{sha256}.{kind}`), `intelligence_deliveries`, and `email_suppressions` all bind to `umkms.id`, `evaluation_id`, `event_id`, or `business_email` fingerprint.
- **Hermes server-to-server endpoints never check `umkms.user_id`:** In-flight snapshots, callbacks, artifact uploads, recipient pulls, and delivery status syncs will **not** fail with `404`/`409` due to changing `umkms.user_id`.
- **`umkms.business_email` & consent:** `Umkm::booted()` (`app/Models/Umkm.php:68-77`) revokes consent ONLY when `business_email` is dirty — NOT when `user_id` changes. `Umkm::hasNotificationConsent()` checks `notification_consent_at` and `notification_consent_version`, preserving consent provenance (`notification_consent_by_user_id`).

### 4.2 Blockers, Constraints & Race Conditions Requiring Explicit P2/P4 Design

| # | Risk / Constraint | Verified Evidence | Failure Mode | Recommended Mitigation (P2/P4) |
|---|---|---|---|---|
| 1 | **`UNIQUE(user_id, name)` case-insensitive collision** | `2026_11_04_051235_create_umkms_table.php:16,21` (`utf8mb4_0900_ai_ci`) | Transfer fails with SQLSTATE `23000`/`1062` if target Analyst already owns same UMKM name (case/accent-insensitive). | Inside `lockForUpdate()` transaction, check collision on target `user_id` and return deterministic `409 Conflict` (no partial mutation). |
| 2 | **Active Six Factor Draft on Transferred UMKM** | `six_factor_evaluations.active_draft_umkm_id` UNIQUE (`2026_11_20_084534:29-31`) | Moving `umkms.user_id` silently hands Analyst A's unfinished draft to Analyst B. | **Decision required:** block transfer while a `status='draft'` exists (`409`), OR allow draft handover with explicit UI warning. |
| 3 | **In-Flight `webhook_outbox` / Pending Email Delivery Race** | `IntelligenceRecipientController.php:40-56`, `IntelligenceDeliveryStatusController.php:224-228` | If Analyst B immediately edits `business_email` or revokes consent after transfer while Hermes is still processing Analyst A's finalization, delivery can become `skipped_ineligible` / `recipient_changed` or `409` if fingerprint already pinned. | Coordinate transfer: either (a) allow transfer while documenting that `business_email` remains entity-scoped and unaffected, OR (b) optionally block transfer if an outbox/delivery for `umkm_id` is actively in `pending`/`processing`/`sending` state. **No duplicate email** can occur because `webhook_outbox` has `UNIQUE(event_type, aggregate_id)` and transfer never re-finalizes. |
| 4 | **`EvaluationService::store()` Missing Row Lock** | `app/Services/EvaluationService.php:36-37` | Concurrent transfer + 3D submit can allow old owner to write a 3D evaluation after transfer commits. | Add `lockForUpdate()` on `umkms` in `EvaluationService::store()` and in `UmkmTransferService`. |
| 5 | **`UmkmService::recheckWhatsapp()` Race** | `app/Services/UmkmService.php:167-184` | External OpenWA HTTP call runs outside txn; concurrent transfer causes 0-row update and misleading `409`. | Scope update by `id` + re-check authorization cleanly after lock. |
| 6 | **No SoftDeletes + Asymmetric `CASCADE` vs `RESTRICT` on `users`** | `umkms.user_id` `cascadeOnDelete()` vs `six_factor_evaluations`/`intelligence_reports` `RESTRICT` | Deleting an Analyst either destroys UMKMs/3D evals silently or crashes with FK `1451`. | **Never hard-delete Analyst accounts**; add `deactivated_at` (or `is_active`), revoke all Sanctum tokens (`$user->tokens()->delete()`), and consider changing `umkms.user_id` FK to `RESTRICT`. |
| 7 | **Cohort Membership Tied to `user_id`** | `analytics_dataset_cohorts.user_id` UNIQUE; `analytics-views.sql:6` | Transferring a synthetic UMKM out of a cohort owner breaks analytics views & verifier. | Decouple cohort membership to UMKM-level key/table in P2 before enabling transfers. |
| 8 | **Wildcard Sanctum Tokens (`['*']`, 7-day TTL)** | `app/Services/AuthService.php:76-87` | Suspended Analyst or demoted Superadmin retains API access for up to 7 days if tokens are not revoked. | Enforce active-account check in auth middleware AND revoke all tokens on deactivation or role change. |

---

## 5. Safe Analyst Onboarding vs Public Registration

- **Public registration (`POST /api/auth/register`) MUST stay locked to default role `user`:**
  - `role` MUST NOT be in `User`'s `#[Fillable]` (`app/Models/User.php:16`).
  - `RegisterRequest` MUST prohibit or reject any `role` parameter.
- **Superadmin creation of real Analyst accounts MUST NOT call `AuthService::register()`:**
  - `AuthService::register()` mints and returns a live 7-day Sanctum Bearer token (`AuthService.php:36, 76-87`), which would leak the Analyst's session token to the Superadmin!
  - Instead, extract the transactional onboarding enqueue block (`AuthService.php:37-45`) into a shared service (e.g., `HermesOnboardingDispatcher::enqueueInsideTransaction(User $user)`) invoked by BOTH `AuthService::register()` (public `user`) and future `AnalystProvisioningService::create()` (Superadmin-created real `analyst`), while seeders bypass it completely.

---

## 6. Proposed Transaction, Authorization & Race Test Matrix (For P2/P4/P7)

| Test ID | Scenario | Expected Result |
|---|---|---|
| `RBAC-01` | Public `POST /api/auth/register` with extra `role: "superadmin"` or `role: "analyst"` | Role rejected or ignored; persisted user strictly has `role = 'user'`; `user.registered` v1 contract unchanged. |
| `RBAC-02` | `user` or `analyst` calls Superadmin endpoints (list/create Analyst, transfer UMKM) | `403 Forbidden` with proper Indonesian JSON error envelope. |
| `RBAC-03` | `analyst` A accesses/edits/finalizes/downloads report/artifact of `analyst` B's UMKM or passes `owner_id=B` on dashboard | `404 Not Found` (or `403` on forbidden filter); zero cross-account leakage. |
| `RBAC-04` | `superadmin` lists/views/updates UMKM, reads dashboards (`SixFactorSummaryService` & `ThreeDimensionHealthService`), reads reports, downloads PDF/XLSX of any Analyst | `200 OK`; `UpdateUmkmRequest` validates name uniqueness against `$umkm->user_id`; `recheckWhatsapp` succeeds; `notification_consent_by_user_id` records Superadmin actor ID if consent granted. |
| `RBAC-05` | `superadmin` attempts to modify or delete a finalized/cancelled `SixFactorEvaluation` or `IntelligenceReport` | Rejected (`409` / `LogicException` / DB immutability trigger); immutability holds for ALL roles. |
| `XFER-01` | `superadmin` transfers UMKM (with 3D, 6F, reports, PDF/XLSX, consent, synthetic cohort tag) from Analyst A to Analyst B | `umkms.id`, evaluations, reports, artifacts, `business_email`, consent, and cohort membership preserved; audit row written; Analyst B sees data, Analyst A gets `404`; `0` new `webhook_outbox` rows / `0` duplicate emails. |
| `XFER-02` | Transfer collides with existing UMKM name (different case, e.g., `"warung maju"` vs `"Warung Maju"`) owned by Analyst B | `409 Conflict`; transaction rolled back cleanly. |
| `XFER-03` | Concurrent transfer (Superadmin) vs 3D submit / 6F draft save / 6F finalize (Analyst A) using multi-process MySQL test | Serialized cleanly by `umkms` `FOR UPDATE` lock; zero split-brain writes or duplicate outbox events. |
| `XFER-04` | Transfer occurs while Hermes intelligence pipeline is in-flight (`pending` outbox -> snapshot -> callback -> artifact upload -> recipient pull -> delivery status) | All Hermes server-to-server endpoints succeed without `404`/`409`; email targets `umkms.business_email` (never Analyst/Superadmin email); no duplicate send. |
| `ACCT-01` | `superadmin` deactivates Analyst A | Existing Sanctum tokens immediately revoked (`401` on next request); Analyst's UMKMs & history remain intact (zero cascade loss). |
