# P1-03 — Core Assessment & Application Baseline

- **Gate Status (`G1-03`):** **`PARTIAL (Core Business Logic & Isolated UI Smoke PASS; Known Pre-Existing Baseline Failures Reproduced in Seeder & Legacy Browser Specs)`**
- **Evidence:** [mysql-test-results.md](evidence/run-20261127-1918/mysql-test-results.md) · [ui-test-results.md](evidence/run-20261127-1918/ui-test-results.md)

---

## 1. MySQL (`joyumkm_testing`) Automated Regression Results

All **51 PHPUnit files** (`595` test cases, `50,891` assertions) were executed against proven isolated MySQL `joyumkm_testing`:
- **49 Core Business & Contract Files (A1, A2, A3, A4, A5, A_EXTRA):** **`565 passed, 0 failed, 0 errors, 1 skipped`** (`IntelligenceArtifactStorageTest` Windows symlink/libzip encryption skip).
  - Registration, Sanctum auth, duplicate/invalid inputs, own-account access & cross-account `404` rejection (`AuthApiTest` `17/17`, `OwnershipApiTest` & `UmkmApiTest` in `A2` `82/82`): **PASS**.
  - 3D 16-question scoring/categories, draft/final rules & immutability, 6F 1..5 factor bounds, total/category boundaries, single active draft, finalized immutability, single outbox event, duplicate finalization idempotency (`A2` `82/82`, `A3` `140/140`): **PASS**.
  - Outbox v2 contract, HMAC verification, snapshot, callback, artifact upload/download/SHA-256, `business_email` & consent eligibility, delivery status sync (`A4` `273/274` + `1` platform skip): **PASS**.
  - Analytics dataset planner & seeder (`A5` `27/27`, `45,877` assertions): **PASS**.
- **2 Known Pre-Existing Failing Seeder Files (`KNOWN_BASELINE_FAILURE`):**
  - `DemoSeederTest`: `10 passed, 2 failed` (`.env` `SEED_PASSWORD` causes `DatabaseSeeder` to seed 4 users instead of 1).
  - `SampleDataSeederTest`: `14 passed, 1 failed, 2 errors` (`max_evaluations` 4 vs 3; PHPUnit 12 `@dataProvider` error; rollback connection config error).

---

## 2. Frontend Node Unit & Playwright Browser Baseline

- **Node Unit Tests (`npm test`):** **`46 / 46 passed`** (`0` failed, `461.07 ms`).
- **Isolated Playwright UI Smoke (`p1_ui_smoke.mjs`, `joyumkm_testing`, `127.0.0.1:8129`):** **`6 / 6` flows PASS** across desktop (`1440x900`) and mobile (`390x844`): `/register`, `/home`, `/umkms`, `/umkms/{id}`, `/umkms/{id}/six-factor-evaluations` (3D + 6F + finalize), Intelligence UI load, `/login` mobile, `scrollWidth <= innerWidth`, `0` `pageerror`, `0` `console.error`.
- **Existing Repository Browser Specs (`tests/js/*-browser.mjs`, `7` files):** **`0 / 7 passed`** (`KNOWN_BASELINE_FAILURE` due to pre-existing selector/mock drift, e.g., `intelligence-report-browser.mjs` rejects newer `/artifacts` calls, `ui-navigation-browser.mjs` lacks `#filter-toggle`, `workspace-browser.mjs` & `ui-foundation-browser.mjs` fail older link selectors). Left unmodified per PRD §4.3.
