# Evidence: P1-06 Isolated Recovery & Idempotency Results (`run-20261127-1918`)

- **Evidence ID:** `EV-P1-RETRY-001`
- **Observed At:** `2026-11-27T19:53:00+07:00` – `19:56:00+07:00` (`Asia/Jakarta`)

---

## 1. Laravel Isolated Outbox, Retry, Concurrency & Idempotency Tests (MySQL `joyumkm_testing`)

Included in Track A4 (`274` tests, `273 passed`, `1 Windows symlink/libzip skip`, `0 failed`):
- `HermesWebhookOutboxTest`, `HermesWebhookCronTest`, `HermesWebhookWorkerTest`, `HermesWebhookSignatureTest`: UAT scope pinning (`HERMES_WEBHOOK_UAT_SCOPE_ENABLED`), lease claiming, retry backoff, non-retryable 4xx/ack_ignored -> `dead`, duplicate ACK idempotency.
- `IntelligenceCallbackTest`, `IntelligenceCallbackConcurrencyTest`, `IntelligenceManifestCallbackTest`, `IntelligenceArtifactConcurrencyTest`: duplicate callbacks (`200 duplicate`), hash conflicts (`409`), DB immutability triggers.
- `IntelligenceRecipientConsentTest`, `IntelligenceRecipientPullTest`: `business_email` strict source, missing consent/email/suppression -> `eligible=false` without fallback to `users.email`.
- `IntelligenceDeliveryStatusTest`: monotonic `revision`, stale `<` / `==` rejection, pinned fingerprint/message_id immutability.

---

## 2. Hermes JMI Isolated `pytest` Suite (`/opt/data/joyumkm-intelligence/tests/`)

| Selected Suites | Passed | Failed | Subtests | Notes |
|---|---:|---:|---:|---|
| `test_pipeline.py`, `test_callback.py`, `test_delivery.py`, `test_email.py`, `test_email_status_sync.py`, `test_snapshot.py`, `test_transport.py`, `test_corrective.py` | **213** | **1** | 110 | 213 passed (including all email idempotency, retry backoff, status-only sync retry without SMTP resend, callback conflict, and snapshot validation tests). 1 failure: `StatusSyncTests.test_ambiguous_terminal_outcome_and_legacy_enrollment_do_not_submit` (`email_delivery.advance()` returned `'blocked'` instead of `'delivery_unknown'`; note `self.fail("unexpected SMTP")` did **not** trigger — zero SMTP calls made). |

*(Note: Running `test_jmi.py`, `test_artifacts.py`, `test_pdf.py`, `test_xlsx.py` without fixture env vars like `JMI_PDF_FIXTURE_DIR` produced `82` environment/fixture setup failures out of `348` tests; excluded from recovery summary as missing harness env var `ENVIRONMENT_BLOCKER` / unconfigured JMI fixture path.)*

---

## 3. Live Track C Recovery / Retry Scenarios

- **Status:** **`BLOCKED`** pending operator authorization of Track C (live report/onboarding UAT). Preserved all 2 old failed JMI jobs (`3dba65ad-...`, `6e05a476-...`) and both pending outbox rows (`#23`, `#24`) untouched.
