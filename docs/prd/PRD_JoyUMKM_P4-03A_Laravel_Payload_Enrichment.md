# PRD — JoyUMKM P4-03A: Laravel Webhook Payload Enrichment (Dual-Instrument)

**Status:** Approved scope from discussion; implementation pending  
**Target:** Laravel JoyUMKM (`dip-joyumkm`)  
**Scope:** Enrich `evaluation.finalized` with the relevant read-only V1 three-dimension snapshot (INS/ECT/OPS), alongside V2 six-factor evaluation.  
**Out of scope:** Hermes AI intelligence implementation (P4-03B), schema migration, changes to webhook transport, backfill, automatic resend of delivered events.

## 1. Context and objective

P4-02 live E2E confirmed Laravel's `evaluation.finalized` event reached Hermes, with outbox `delivered`. The observed event v1 contains six-factor scores only (evaluation #9, UMKM #5, score 26, category `Sangat Sehat`). The existing sender supports HMAC V2, queued outbox delivery, retry, and strict ACK verification. Preserve these working mechanisms.

P4-03A must add a **read-only snapshot of the existing three-dimension V1 assessment** to the webhook produced when a V2 six-factor evaluation transitions from Draft to Final. Hermes will subsequently analyze the two instruments independently.

## 2. Functional requirements

**FR-01 — Trigger unchanged.** Emit exactly one outbox event on successful Draft → Final transition of a V2 six-factor evaluation. No event for Draft save, cancellation, repeated finalization, or preexisting finalized records. Preserve transactionality and idempotency.

**FR-02 — Six-factor snapshot.** Include evaluation ID, UMKM ID, finalized status, total score, category, and all six factor scores. Use values frozen at finalization; do not recompute asynchronously.

**FR-03 — Three-dimension snapshot.** Read existing V1 data for the same UMKM, without modifying V1. Include INS (Institutional Resources), ECT (Economic Connectivity), OPS (Operational Stability) values **only when grounded in an actual persisted V1 assessment**. Include source record ID, assessed/completed timestamp (if present), and any original category/total only if the current domain model defines them. Do not invent or infer values.

**FR-04 — Selection rule.** Inspect the repository's actual V1 schema and domain service before coding. Choose the latest eligible *completed/final* V1 assessment at or before the V2 finalization timestamp, using a deterministic timestamp + ID tie-break. Do not use drafts or future assessments. If V1 has no final/completed concept, document the actual eligibility rule and use the closest authoritative persisted assessment; escalate only if ambiguous enough to cause incorrect data association. Never select a record belonging to a different UMKM.

**FR-05 — Missing data.** If no eligible V1 assessment exists, emit `three_dimension: null` (not a fabricated zero-score object). A partially populated V1 assessment must not be silently treated as a complete three-dimension score; represent missing values according to the domain's actual nullability and include a completeness indicator if needed.

**FR-06 — Event contract.** Set `event_version: 2` for newly enriched events; preserve `event_type: evaluation.finalized`, `event_id`, `occurred_at`, `source`, and the existing transport headers/signature. Keep historical v1 event payloads unchanged. Event schema must be documented and covered by tests. The recommended v2 structure is below; field mapping must be verified against the actual code.

```json
{
  "event_id": "<uuid>",
  "event_type": "evaluation.finalized",
  "event_version": 2,
  "occurred_at": "<ISO-8601>",
  "source": "joyumkm",
  "data": {
    "umkm_id": 5,
    "six_factor": {
      "evaluation_id": 9,
      "status": "final",
      "total_score": 26,
      "category": "Sangat Sehat",
      "scores": {
        "kinerja": 5,
        "stabilitas": 5,
        "sumber_daya": 3,
        "kepemimpinan": 4,
        "lingkungan_kerja": 5,
        "nilai_organisasi": 4
      }
    },
    "three_dimension": null
  }
}
```

When V1 exists, `three_dimension` should contain its actual source identity/timestamp and `scores: {"ins": <persisted>, "ect": <persisted>, "ops": <persisted>}`; only include metadata supported by the schema. Document whether values are 0–100 or another existing scale; do not rescale or combine with V2.

**FR-07 — Snapshot consistency.** Build and persist the enriched payload within the existing finalization/outbox transaction, so later V1 changes cannot mutate an already queued event. Do not add a network request in the finalization transaction.

**FR-08 — Compatibility.** Ensure Hermes receiver's generic event filter (`evaluation.finalized`) and HMAC V2 contract remain compatible. Hermes intelligence processing of v2 belongs to P4-03B. If another known consumer relies on `data.scores` at the root, assess compatibility before replacing its location; prefer an additive migration where necessary rather than silently breaking consumers.

## 3. Non-functional requirements and guardrails

- No new database tables or migrations unless the current schema demonstrably cannot support the requirement; seek approval before scope expansion.
- No modifications to V1 scoring, V2 scoring, categorization, authorization, or finalization semantics.
- No direct Hermes-to-Laravel writes, no AI processing in Laravel, no callback endpoint in this phase.
- No edits to sender HMAC algorithm, queue connection, worker retry policy, ACK validation, or delivery URL.
- Preserve event UUID idempotency and avoid logging secrets, sensitive assessment payloads, or authentication tokens unnecessarily.
- Database read must be indexed/efficient where possible; prevent N+1 or broad historical scans.

## 4. Acceptance criteria

1. **AC-01:** Finalizing a V2 assessment for UMKM with eligible V1 data writes one v2 outbox event containing six-factor and correctly associated INS/ECT/OPS snapshots.
2. **AC-02:** UMKM with no eligible V1 data writes one valid v2 event with `three_dimension: null`.
3. **AC-03:** Latest eligible V1 selection is deterministic, respects the finalization cutoff, and never crosses UMKM boundaries.
4. **AC-04:** V1 records remain unchanged; six-factor score/category and transaction behavior remain unchanged.
5. **AC-05:** Concurrent/repeated finalization does not duplicate events or produce inconsistent snapshots (MySQL tests).
6. **AC-06:** Existing sender/HMAC/strict ACK tests continue to pass; a v2 event is delivered and accepted by the existing Hermes route.
7. **AC-07:** Previously persisted v1 events remain valid and are not backfilled or mutated.
8. **AC-08:** Documentation includes exact v2 JSON schema, nullable/missing-data semantics, V1 selection rule, and a concise migration/rollback note.

## 5. Verification plan

- Inspect V1 persistence schema, assessment completion semantics, V2 finalization service, outbox builder, and existing webhook tests **before implementation**.
- Add focused tests for complete V1, absent V1, partial V1 (if supported), multiple historical V1 records, future record exclusion, cross-UMKM isolation, concurrency, and event versioning.
- Run targeted Laravel/PHP tests and MySQL concurrency tests. Do not run the entire legacy SQLite suite merely for this phase.
- Run existing Hermes webhook sender/outbox/worker regression tests.
- Conduct one controlled live v2 finalization test when Hermes route and worker are available; report separately as LIVE PASS/NOT RUN. Do not claim live E2E success from mocked HTTP tests.

## 6. Implementation guidance for Codex

- Primary implementor: **Codex Sol**; use repository skills and specialized agents only when applicable, max three parallel subagents.
- Local skill directory: `C:\Users\itsba\.agents\skills`.
- Local agent directory: `C:\Users\itsba\.codex\agents`.
- First inspect current repository rather than assuming V1 table names or field names. Keep changes minimal and focused on payload construction, contract tests, and docs.
- Keep webhook delivery infrastructure untouched. No new queues or database migrations by default.
- Handoff: changed files, real V1 source/selection semantics, exact v2 payload example, test commands/results, MySQL concurrency result, and LIVE E2E status. Explicitly distinguish PASS, FAIL, and NOT RUN.

## 7. Work breakdown

1. **Discovery:** Confirm V1 data model, three-dimension fields, assessment eligibility, and event builder.
2. **Implementation:** Enrich finalization outbox snapshot and version the contract.
3. **QA:** Focused functional and concurrency tests plus sender regression.
4. **Docs & handoff:** Update webhook docs with v2 schema and compatibility notes.
5. **Optional live gate:** Trigger one new finalization and verify Hermes 202 ACK; no requeue of already delivered event.

## 8. Definition of done

All AC-01 through AC-08 pass in automated/appropriate verification, with live test status reported honestly. The resulting v2 payload is ready for **P4-03B Hermes Dual-Instrument Intelligence** without changing the existing transport architecture.
