# P1-04 — Intelligence Report, PDF/XLSX, Real Email & Status Sync UAT

- **Gate Status (`G1-04`):** **`BLOCKED`** *(Pending operator preflight authorization for Track C)*
- **Gate Status (`G1-05`):** **`BLOCKED`** *(Pending operator-approved test mailbox, UAT scope pin, and `delivery-status.env` authorization)*
- **Current Run:** `run-20261127-1918` (Zero live `evaluation.finalized` dispatches, zero AI report generations, zero real report emails sent).

---

## 1. Isolated Contract & Negative Baseline Already Verified (No Live Send)

- Track A4 PHPUnit suite (`273/274` passed, `1` Windows skip) + Hermes JMI `pytest` recovery/delivery/email/sync suites (`213/214` passed) verify:
  - `evaluation.finalized` v2 payload, HMAC-SHA256 signatures across all 6 endpoints, callback/manifest immutability triggers, PDF/XLSX validation (`qpdf` + Python), `business_email` strict resolution (zero fallback to `users.email`), consent/suppression gating, and monotonic delivery-status revision enforcement.
- **Historical P6 Anchor (Reference Only — Not Counted as P1 Live Proof):** `a424c677-7c5a-4c59-86aa-e35d9d348364` (`2026-11-25`) had `1` completed report, PDF/XLSX on disk, and `intelligence_deliveries` row `#1` (`status=sent`, `attempt_no=1`, `provider_message_id` present), with inbox delivery `NOT RUN`.

---

## 2. Exact Blockers to Close `G1-04` & `G1-05`

See [preflight-and-authorization.md](evidence/run-20261127-1918/preflight-and-authorization.md):
1. Operator confirmation of owned test mailbox (`umkms.business_email`) and how redacted inbox receipt will be confirmed.
2. Operator authorization of UAT fixture (`p1-uat-<run_id>`) in `joyumkm-v2` and scoped single-event execution (`HERMES_WEBHOOK_UAT_SCOPE_ENABLED=true` + `hermes-uat-scope.json`, leaving `#23/#24` & `#30/#31` untouched).
3. Operator authorization for temporary Hermes `delivery-status.env` (`1/0`) and scoped UAT intake/recovery flags (`dev-operation.env` / `uat-scope.json`), `1` bounded LLM report generation, and `1` real report email.
