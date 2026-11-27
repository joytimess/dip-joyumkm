# Evidence: Inbox Confirmation (Run 2)

- **Run:** `run2-20261127-2015`
- **Status:** `SMTP ACCEPTED — INBOX CONFIRMATION PENDING` for both emails. Full email UAT is **not** claimed as PASS.

## 1. Status by Email

| Email | System evidence | Inbox evidence | Status |
|---|---|---|---|
| Real report email (PDF + XLSX), event `5dd7efbf-…` | Hermes `email_delivery.json` `status=sent`, `smtp_phase=data`; Laravel `intelligence_deliveries.id=2` `sent` | **None yet** | `SMTP ACCEPTED — INBOX CONFIRMATION PENDING` |
| Real onboarding email, run `4ca001338046` | Hermes run `welcome_email` `status=sent`; receiver receipt `smtp_started=1` | **None yet** | `SMTP ACCEPTED — INBOX CONFIRMATION PENDING` |

SMTP `sent` is the transport's acceptance (DATA reply). It is not evidence that a message reached a recipient inbox, spam folder or quarantine.

## 2. Requested From the Operator (one consolidated handoff)

For **each** of the two emails, please confirm:

1. Whether the message is in the authorized mailbox's **inbox** (not spam/promotions), with the **sender**, **subject**, and **arrival time** (`2026-11-27 20:20 WIB` for onboarding, `2026-11-27 20:24 WIB` for the report).
2. For the report email: whether the **PDF** and **XLSX** attachments are present and open.
3. A **redacted screenshot** or a written confirmation is enough. Please redact the address and any personal content.

We are **not** asking for mailbox access, a password, or any mailbox credential.

## 3. What Happens When Confirmed

- Operator confirms both inboxes → update G1-05 and G1-06 to `PASS` in the handoff and regression matrix.
- Operator reports a missing message → check spam/quarantine first. Do not resend. Investigate with status-only reconciliation (`email_status_sync`), following the mandate. A re-send requires separate operator approval, because the authorized send budget is 1 per email.

## 4. Duplicate Protection (in case a re-send is considered)

- Report: `delivery` idempotency key `03d435edfefc7051f030fad596e610d4d6e513d4d8f9d38c9970f491c803de04`. Hermes state is `sent` (terminal). A retry would be refused, since `sent` is terminal in `email_delivery.py` (`TERMINAL`).
- Onboarding: receiver receipt keyed by event id, `duplicate` response on replay.
