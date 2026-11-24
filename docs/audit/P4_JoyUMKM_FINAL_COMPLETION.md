# P4 handoff

P4-01 through P4-05 retain their accepted checkpoints. **P4-06 FULL COMPLETION — PASS**, **P4-07 FULL COMPLETION — PASS**, and **P4 OVERALL — PASS** for the Laragon handoff. See [P4-06](P4-06_JoyUMKM_Artifact_Status_Download.md), [P4-07](P4-07_JoyUMKM_Deployment_Hardening.md), and [QA](../evidence/p4-07-qa-summary.md).

Actual routes and authorization are documented in the [P4-06 contract](../evidence/p4-06-api-contract.md). Artifacts stay on the private intelligence disk; availability is checked at request time, and Hermes delivery completion remains a historical acknowledgment. No automatic remote corruption monitoring is claimed.

The continuation added QPDF-backed validation and focused tests, restricted the Windows intelligence ACL with a backup, verified representative HTTP delivery, and registered disabled Windows worker and scheduler tasks. Production rollout flags remain disabled.

Before nginx/PHP-FPM deployment, verify the parser dependency and filesystem permissions under the hosting service account and repeat the representative HTTP flow there. The manifest migration is already applied on dev.

## P5 handoff

Local Hermes package readiness is exposed through `jmi.pipeline.get_ready_package(event_id)` and `jmi.transport.prepare_verified_package(event_id)`. Consume verified PDF/XLSX bytes as attachments and preserve the immutable artifact identity and hashes. Gmail delivery must remain independent of Laravel upload; recipient pull and consent belong to P5. SMTP transport has not been implemented by this task. P5 has not been started.
