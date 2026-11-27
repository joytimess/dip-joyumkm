# Evidence: P1-03 Node Unit & Isolated Playwright UI Results (`run-20261127-1918`)

- **Evidence ID:** `EV-P1-UI-001`
- **Browser Runtime:** Google Chrome (`C:\Program Files\Google\Chrome\Application\chrome.exe`) via existing `playwright-core` (`C:\laragon\www\flow-ugc\automation\node_modules\playwright-core\index.js`); no package installed.
- **Laravel Server Isolation:** Ephemeral `php artisan serve --host=127.0.0.1` pointing strictly to **`joyumkm_testing`** (`APP_ENV=testing`, `HERMES_WEBHOOK_ENABLED=false`, `HERMES_ONBOARDING_ENABLED=false`, `HERMES_PREDICTION_ENABLED=false`, `OPENWA_ENABLED=false`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`), immediately terminated after run and `joyumkm_testing` reset via PHPUnit `ExampleTest`.

---

## 1. Frontend Node Unit Tests (`npm test`)

- **Command:** `npm test` (`node --test tests/js/*.test.js`, Node `v22.19.0`)
- **Result:** **46 / 46 passed, 0 failed** (`461.071 ms`, exit code `0`) across all 6 unit test files (`intelligence-report.test.js`, `rupiah-input.test.js`, `scoring.test.js`, `six-factor-dashboard.test.js`, `six-factor-draft.test.js`, `whatsapp-number.test.js`).

---

## 2. Isolated Live UI Smoke Verification (`p1_ui_smoke.mjs`, Scratch)

| Flow / Viewport | Expected | Observed | Status |
|---|---|---|---|
| **Desktop `1440x900` — Register & Home** | User registers on `/register`, redirects to `/home`, Sanctum token stored, no horizontal overflow | Redirected to `/home`, topbar displays `P1 Smoke Tester`, `scrollWidth <= innerWidth` = `true` | **PASS** |
| **Desktop `1440x900` — UMKM Create & List/Detail** | Create UMKM via API (`notification_consent: true`), navigate SPA `/umkms` & `/umkms/{id}` | `201 Created`, UMKM visible in list and detail pages | **PASS** |
| **Desktop `1440x900` — 3D + 6F + Finalize + Intelligence UI** | 3D final (`201`), visit `/umkms/{id}/six-factor-evaluations`, 6F draft & finalize (`200`), visit `/umkms/{id}` | All steps succeeded, `scrollWidth <= innerWidth` = `true` (note: `intelTab` locator `[role=tab/button]` returned `had_intel_tab=false`; Intelligence section/link is rendered directly on detail view) | **PASS** |
| **Mobile `390x844` — Login & Home** | Login on `/login`, redirect `/home`, no horizontal overflow | Redirected to `/home`, `scrollWidth <= innerWidth` = `true` | **PASS** |
| **Runtime Error Check** | Zero uncaught `pageerror` & `console.error` | `pageErrors: []`, `consoleErrors: []` | **PASS** |

---

## 3. Existing Repository Playwright Browser Specs (`tests/js/*-browser.mjs`)

All 7 pre-existing browser specs were executed unchanged against isolated `php artisan serve` (`joyumkm_testing`):

| Spec | Port | Exit | Duration | Observed Failure (Reproduced Baseline Drift — Unmodified) |
|---|---:|---:|---:|---|
| `workspace-browser.mjs` | 8129 | 1 | 18.60s | Logged `Home -> UMKM document/auth requests: 0 0`; timed out waiting for `getByRole('link', { name: 'Usaha UI0 QA', exact: true })`. |
| `six-factor-browser.mjs` | 8129 | 1 | 37.34s | Timed out clicking `getByRole('link', { name: 'Usaha P2 ... utama', exact: true })`. |
| `ui-navigation-browser.mjs` | 8129 | 1 | 19.21s | Passed drawer & mobile search checks, then timed out waiting for `#filter-toggle`. |
| `ui-page-refinement-browser.mjs` | 8129 | 1 | 26.00s | `TypeError: Cannot read properties of null (reading 'getBoundingClientRect')` at line 186. |
| `ui-foundation-browser.mjs` | 8131 | 1 | 20.08s | Timed out waiting for `#umkm-rows tr` link selector at line 116. |
| `umkm-wizard-browser.mjs` | 8134 | 1 | 3.34s | Assertion mismatch: expected `/bilangan bulat/` validation text, got `''` at line 289. |
| `intelligence-report-browser.mjs` | 8138 | 1 | 3.15s | Mock allowlist rejected newer `/api/umkms/5/intelligence-reports/{14,13,4}/artifacts` requests (`Unexpected API path`). |

**Classification:** `KNOWN_BASELINE_FAILURE` (pre-existing spec drift relative to newer UI selectors and `/artifacts` polling). Per PRD §4.3, specs were not edited to force PASS.
