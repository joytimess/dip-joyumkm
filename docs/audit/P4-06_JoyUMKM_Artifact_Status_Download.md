# P4-06 — Artifact status and download

Verdict: **P4-06 FULL COMPLETION — PASS** on Laragon. A single existing Hermes synthetic package passed signed callback, PDF/XLSX uploads, complete status, and owner downloads with matching SHA-256. Anonymous and cross-UMKM requests were rejected; both formats returned 409 for corrupt or missing files. Response headers and JSON sampled during the flow exposed no private path. The Windows storage ACL check passed. See [contract](../evidence/p4-06-api-contract.md) and [QA summary](../evidence/p4-07-qa-summary.md).

The status distinguishes historical database status from current file availability. Missing and corrupt files are rejected and incidents logged, without changing the report or manifest. Existing report ownership is reused. Actual bytes are verified before being returned, so a later path replacement cannot change the response.
