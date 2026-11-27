# Evidence: Baseline Failure Reconciliation (Run 2)

- **Run:** `run2-20261127-2015`
- **Scope:** G1-03, G1-07, G1-08 items in Step 8 of the mandate
- **Rule:** no source code was changed and no test assertion was edited to produce PASS. Each item is classified as `KNOWN_BASELINE_FAILURE` (same failure as Run 1, with root cause recorded) or `NEW` (any new failure).

## 1. Summary

| Item | Run 1 | Run 2 action | Classification | New? |
|---|---|---|---|---|
| `DemoSeederTest` (2 tests) | FAIL | Re-run: same 2 failures (`users` expects 1, finds 4) | `KNOWN_BASELINE_FAILURE`: seed expectation drift | NO |
| `SampleDataSeederTest` (1 fail, 2 errors) | FAIL | Re-run: same. Causes: (a) evaluation cap expectation 4 > 3 for Sumber Kencana; (b) `test_invalid_seed_password_does_not_create_sample` argument-count error; (c) `test_rollback_on_failure_leaves_no_partial_data` undefined connection name | `KNOWN_BASELINE_FAILURE` plus **two test-side defects** (b, c) | NO |
| Seeder group total (re-run) | 29 tests | 29 run, 24 pass, 3 fail, 2 error | same as Run 1 | NO |
| 7 legacy `tests/js/*-browser.mjs` specs | 0/7 | Not re-run in Run 2 (Run 1 evidence retained) | `KNOWN_BASELINE_FAILURE` (selector and route drift) | NO (carried) |
| Intelligence isolated contracts 273/274 | Verified 273/274 | Not re-run in Run 2 (Run 1 evidence retained) | Carried from Run 1 | NO (carried) |
| Analytics HTML formatting failures (`test_analytics_workflow` 3 fails) | FAIL | Not re-run in Run 2 | Carried from Run 1, `KNOWN_BASELINE_FAILURE` | NO (carried) |
| FakeCtx errors (`test_commands` 9 errors) | FAIL | Not re-run in Run 2 | Carried from Run 1, `KNOWN_BASELINE_FAILURE` | NO (carried) |
| JMI `test_ambiguous_terminal_outcome_and_legacy_enrollment_do_not_submit` | FAIL: `blocked` vs `delivery_unknown` | **Root cause reproduced** (see §2) | **Environment-dependent test defect. Not a product defect. No submission and no duplicate possible.** | NO |
| Recovery, retry, duplicate delivery, status-only reconciliation | Partial | Live path exercised once (no retry needed). Status sync accepted. | PARTIAL: retry path not exercised live | NO |

**Scope honesty:** items marked "Not re-run in Run 2" are carried from Run 1 evidence and were not executed again in this session. Only the seeder group and the ambiguous-outcome test were re-executed here.

## 2. Ambiguous Terminal Outcome: Root Cause and Safety

### 2.1 What the test expects

`StatusSyncTests.test_ambiguous_terminal_outcome_and_legacy_enrollment_do_not_submit` sets a `delivery_unknown` state (`SMTP_CONNECTION_LOST`) and calls `email_delivery.advance()` with a `submit` callback that calls `self.fail("unexpected SMTP")`. It expects the status to remain `delivery_unknown`, the file to be unchanged, and no SMTP submit.

### 2.2 Reproduction (disposable copy, no live state)

A copy of the Hermes `jmi` and `tests` packages was made inside the existing container under `/tmp` and removed afterwards. The live state directory was never used.

| Case | `JMI_HOME` | `advance()` result | Test result |
|---|---|---|---|
| A | writable scratch path | `delivery_unknown` / `SMTP_CONNECTION_LOST` | PASS (`tests_run=1 failures=0 errors=0`) |
| B | unusable path (simulates the default-home coupling) | `blocked` / `EMAIL_STATE_INVALID` | FAIL (`failures=1 errors=0`), the exact Run 1 symptom |

Full module `tests.test_email_status_sync` (6 tests) passed in case A.

### 2.3 Root cause

- The test does not pin `JMI_HOME`. `store.home()` and `artifacts._lock(store.home()/"locks", …)` resolve to the process default, `DEFAULT_HOME = /opt/data/joyumkm-intelligence/state`, when `JMI_HOME` is unset.
- When the lock or home location is not usable by the test process, `_lock` raises an `OSError`. `advance()` maps `OSError` and other `INVALID` exceptions to `status=blocked, error_code=EMAIL_STATE_INVALID`.
- The assertion `outcome["status"] == "delivery_unknown"` then fails. The `submit` guard is never reached in that case, because `errors=0` (`self.fail` would raise `AssertionError`, which is not caught as `INVALID` and would have been reported as an error).
- A stray zero-byte lock file `locks/11111111-1111-4111-8111-111111111111.email_delivery` exists in the live state directory, dated `2026-11-24 17:56 UTC`. It was created by an earlier live-tree run of this test (before Run 1), which is consistent with the coupling. It was **not** deleted (deleting live-state files needs approval). The operator can decide on removal.

### 2.4 Why this does not endanger live UAT or cause duplicate email

1. **Terminal states are never re-sent.** `TERMINAL` includes `sent` and `delivery_unknown`. `_advance` returns the summary immediately for a terminal state, with no submit call (`jmi/email_delivery.py` lines 13 and 151–152).
2. **An interrupted send becomes `delivery_unknown`, not a retry.** A `sending` state at recovery is recorded as `delivery_unknown` (lines 153–154). Only `processing` (interrupted before DATA, so no message left) becomes `failed_retryable` for retry (lines 156–158). That is the SMTP-correct split.
3. **Fail-closed on invalid state.** `OSError` and other invalid-state exceptions return `blocked` before any submit.
4. **Run 2 real send was clean.** One `sent` state, `attempt_no=1`, no retry, no duplicate (see `report-email-delivery.md`).

### 2.5 Classification and follow-up

`Environment-dependent test defect`, classified as isolation debt, not a product defect. Fix belongs to P2 test hygiene: pin `JMI_HOME` in `setUp` with `patch.dict(os.environ, {"JMI_HOME": temp})`. No source edit was made in Run 2.

## 3. Items That Remain Open

- Telegram alert paths, live WhatsApp checks, and additional analytics LLM invocations: **not authorized and not executed**. Their coverage gap is recorded in `P1-FINAL-HANDOFF.md`.
- The seeder test defects (b) and (c) above are test-side and need a separate, authorized test fix (not done, per "do not change assertions to obtain PASS").
- Carried Run 1 items (browser specs, analytics formatting, FakeCtx, intelligence 273/274) were not re-executed in Run 2.
