# Evidence: Real Report Email Delivery (Run 2)

- **Run:** `run2-20261127-2015`
- **Event:** `5dd7efbf-b320-4be8-904d-707bbd2e8450` (UMKM 457, report 11)
- **Recipient:** `[REDACTED:AUTH_UAT_MAILBOX]` (authorized `business_email`)
- **Status:** `SMTP ACCEPTED — INBOX CONFIRMATION PENDING`. Not a full email PASS.

## 1. Send Count

| Metric | Value |
|---|---|
| Real report emails attempted | **1** (within the authorized maximum of 1) |
| Attempt number | 1 (`attempt_no=1`, no retry) |
| Duplicate sends | 0 |
| Idempotency key (Hermes `email_delivery.json`) | `03d435edfefc7051f030fad596e610d4d6e513d4d8f9d38c9970f491c803de04` |

## 2. Hermes Record (`state/jobs/5dd7efbf-…/email_delivery.json`)

| Field | Value |
|---|---|
| `status` | `sent` |
| `smtp_phase` | `data` (the SMTP DATA command was accepted; the message was accepted for delivery by the transport) |
| `error_code` | `null` |
| `sent_at` | `2026-11-27T13:24:07.395758Z` (20:24:07 WIB) |
| `channel` | `email` |
| `consent_version` | `v1` |
| `recipient_fingerprint` | masked (SHA-256 of the recipient, not the address) |
| `source_report_sha256` | `6b215e66…` |
| `report_hash` | `78a2a05f…` |
| `status_sync` | `pending` at send, then `synced` (`email_status_sync.json`) |

## 3. Laravel Record (`intelligence_deliveries.id = 2`)

| Field | Value |
|---|---|
| `channel` | `email` |
| `status` | `sent` |
| `attempt_no` | 1 |
| `error_code` | NULL |
| `sent_at` | 2026-11-27 20:24:07 WIB |
| `synced_email_event` | `5dd7efbf-b320-4be8-904d-707bbd2e8450` |
| `email_snapshot_sha256` prefix | `b77046335cfb` (matches Hermes `email_status_sync.json` digest) |

## 4. Status Synchronization

Hermes `email_status_sync.json`: `result=accepted`, `status=synced`, `revision=1`, `attempts=1`, `resumed=false`. Laravel stored the synced event id and the matching digest.

## 5. Meaning of "sent"

`sent` in this record means **SMTP acceptance** by the Gmail transport (the DATA reply was received). It does **not** mean the message reached an inbox, and it is not a delivery receipt from the recipient's mailbox. Inbox confirmation is requested in `inbox-confirmation.md`.

## 6. Scope Controls Observed

- The transport used `email.env` (`JMI_EMAIL_DELIVERY_ENABLED=1`, `PAUSED=0`), which was already in the authorized end state.
- No retry was triggered. The status-only reconciliation path was not needed.
- No other recipient was addressed by this run.
- The email body and attachment contents were not inspected by this run. The PDF and XLSX bytes are verified in `artifact-integrity-results.md`, and the SMTP DATA acceptance is verified here.
