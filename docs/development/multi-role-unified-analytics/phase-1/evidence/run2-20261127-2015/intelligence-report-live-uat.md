# Evidence: Step 5 — Intelligence Report Live UAT (Run 2)

- **Run:** `run2-20261127-2015`
- **Window:** 2026-11-27 20:19–20:25 WIB (13:19–13:25 UTC)
- **Gate outcome:** G1-04 `PASS` (API-verified, see caveat §5); G1-05 `PARTIAL` (SMTP accepted, inbox pending — see `inbox-confirmation.md`)

## 1. Ordering Note

The mandate lists the report flow (Step 5) before registration (Step 6). The report fixture needs an owner account, and the only legitimately created account is the authorized onboarding account. The onboarding registration therefore ran first (`onboarding-email-delivery.md`), and the report fixture was created under that account. Both use the same authorized mailbox as the mandate allows. No other account was created.

## 2. Fixture

| Item | Value |
|---|---|
| Fixture prefix | `p1-uat-` (UMKM name `p1-uat-20261127-2015`) |
| UMKM | `umkms.id = 457`, `sector_id = 1`, province `DKI Jakarta` |
| `business_email` (report recipient) | `[REDACTED:AUTH_UAT_MAILBOX]` (authorized) |
| `notification_consent` | `true`, set through `POST /api/umkms` (normal API). `notification_consent_active=true` in response. |
| Owner account | `users.id = 8` (the authorized onboarding account, created in Step 6) |
| WhatsApp number | placeholder `081200000001`, `whatsapp_registration_status=unchecked` (OpenWA disabled for this window, zero WhatsApp calls) |
| Six-factor draft | `six_factor_evaluations.id = 1317`; scores `sumber_daya 4, kepemimpinan 3, lingkungan_kerja 4, nilai_organisasi 3, stabilitas 4, kinerja 4` |
| Finalization | `POST …/six-factor-evaluations/1317/finalize` → `status=final`, `total_score=22`, `category=Sehat` |

## 3. Flow Results (Expected Flow Items 1–11)

| # | Expected | Result | Evidence |
|---|---|---|---|
| 1 | Finalization succeeds | PASS. `finalized_at=2026-11-27 13:22:30 UTC`. | API response |
| 2 | Exactly one `evaluation.finalized` v2 created | PASS. `webhook_outbox.id=25` (only new row). | SQL |
| 3 | Event processed through scoped dispatch | PASS. `deliver(25)` (targeted). Outbox `delivered`, HTTP 202, 1 attempt. | `scoped-dispatch-verification.md` |
| 4 | Hermes obtains historical snapshot | PASS. `snapshot.json` and `snapshot_manifest.json` present in job dir. | Hermes job files |
| 5 | At most one JMI LLM invocation | PASS. `analysis_submission`: `attempt=1`, `corrective=false`, `state=accepted`, `started 13:23:03Z → ended 13:23:57Z`. `corrective.used=0`, `attempts=1`, `auto_retry_cycles=0`. Model `claude-sonnet-5-5[1m]`, provider `claude-subscription-directsdk-experimental`. | Hermes job record |
| 6 | PDF and XLSX generated | PASS. `report.pdf` (41,201 bytes), `report.xlsx` (27,101 bytes). | Hermes job dir |
| 7 | Artifact integrity and SHA-256 valid | PASS. See `artifact-integrity-results.md`. | manifest vs disk vs Laravel vs download |
| 8 | Callback and Laravel upload succeed | PASS. Callback `http_status=201`, `ack_status=stored`, `report_id=11`. Artifact upload rows `id 13` (pdf) and `id 14` (xlsx). | `delivery.json`, Laravel rows |
| 9 | Real email sent to authorized `business_email` | PASS for SMTP acceptance (`status=sent`, `smtp_phase=data`, attempt 1, no error). Inbox not confirmed. See `report-email-delivery.md`. | `email_delivery.json`, `intelligence_deliveries.id=2` |
| 10 | Delivery status synchronized | PASS. Hermes `email_status_sync.json`: `result=accepted`, `status=synced`, `revision=1`. Laravel `intelligence_deliveries.synced_email_event` holds the event id and the snapshot digest matches (`b7704633…`). | Both sides |
| 11 | PDF and XLSX downloadable through Tab Intelligence | PASS at API level. `GET /api/umkms/457/intelligence-reports/11/artifacts/{pdf,xlsx}` → `200`, correct MIME, byte counts equal manifest, SHA-256 equal manifest. **UI click-through not performed** in this session. | Download results |

## 4. Correlation Record

| Key | Value |
|---|---|
| UMKM ID | 457 |
| Evaluation ID | 1317 |
| Event ID | `5dd7efbf-b320-4be8-904d-707bbd2e8450` |
| Outbox ID | 25 |
| Laravel queue job ID | none (never enqueued, by design) |
| Hermes JMI job ID | `5dd7efbf-b320-4be8-904d-707bbd2e8450` (job dir) |
| Report ID (Laravel) | 11 |
| Manifest ID (Laravel) | 7 |
| Artifact set hash | `fa6dc603…` (Hermes `email_delivery.json`) |
| Artifact IDs | pdf 13, xlsx 14 |
| Delivery ID (Laravel) | 2 (`channel=email`, `status=sent`, `attempt_no=1`) |
| Delivery status | `sent` (SMTP accepted); status sync `synced` |
| Hermes job state | `completed`, `methodology_status=partial` (as produced by the analysis; not altered) |

## 5. Caveats

1. **Tab Intelligence UI** not opened. API evidence only.
2. **`methodology_status = partial`** is what the analysis produced. It was not changed to make the run look cleaner. The operator should review this before P2.
3. **Hermes-side scope** was not enforced during the run because the mode required for artifact generation is `normal` (`scoped-dispatch-verification.md` §4). Laravel-side scope was enforced.
4. **Other events:** the Hermes dev JMI job list grew from 19 to 20 directories. Only `5dd7efbf-…` is new.
