# Evidence: Step 3 — Scoped Dispatch Safety (Run 2)

- **Run:** `run2-20261127-2015`
- **Recorded at:** 2026-11-27 20:23 WIB (`+07:00`)
- **Verdict:** `PASS` for Laravel-side isolation of existing events. `PARTIAL` for Hermes-side scope enforcement: the Hermes pin is **not enforced** while `JMI_DEV_OPERATION_MODE=normal` (see §4). This partial is recorded, and the mitigation is documented.

## 1. Authorized Event and Correlation

| Item | Value |
|---|---|
| Authorized event (new) | `evaluation.finalized` v2, `event_id` `5dd7efbf-b320-4be8-904d-707bbd2e8450` |
| Outbox row | `webhook_outbox.id = 25` |
| Evaluation | `six_factor_evaluations.id = 1317` (aggregate id 1317) |
| UMKM | `umkms.id = 457` (`p1-uat-20261127-2015`) |
| Hermes JMI job | `state/jobs/5dd7efbf-…` (job id equals event id) |
| Existing outbox rows that must NOT be sent | `#23` (`event 9c4bb8e1-…`, aggregate 31), `#24` (`event 32f0a6e5-…`, aggregate 32) |
| Existing queue jobs that must NOT be consumed | Laravel `jobs.id` `30` and `31` (`hermes-webhooks`) |

## 2. Pre-Dispatch Verification

| Check | Result | Evidence |
|---|---|---|
| Laravel UAT scope flag | `HERMES_WEBHOOK_UAT_SCOPE_ENABLED=true` (temporary) | `temporary-configuration-diff.md` |
| Pin shape | `mode=pinned`, `run_id=20261127T132243Z` (matches `^\d{8}T\d{6}Z$`), `expires_at` = now + 25 min, `event_id`, `evaluation_id=1317`, `umkm_id=457` | `uatPin()` returned non-null |
| Gate decision per event (tinker, read-only) | `allows(#25)=true`, `allows(#23)=false`, `allows(#24)=false` | Tinker output 2026-11-27 13:22 UTC |
| Payload fields vs gate | `source=joyumkm`, `event_type=evaluation.finalized`, `event_version=2`, `six_factor.evaluation_id=1317`, `umkm_id=457`, payload `event_id` = column `event_id` | SQL JSON check on `#25` |
| Hermes receiver processing before dispatch | `jobs` still `30`,`31` (plus the onboarding job, completed and removed) | SQL |

## 3. Isolation Mechanism Used for Dispatch

- The new event was **never enqueued**. Finalization created `#25` with `queued_job_id = NULL` because `enqueueSafely()` returned `false` while no pin existed (`allows()` = false). This was verified after finalization: `jobs` still `30`,`31` only.
- Dispatch used the existing public method `HermesWebhookOutbox::deliver(25)` directly, through a single targeted call (`php artisan tinker --execute`). This is the same code path the queue job runs (`DeliverHermesWebhook::handle()` → `deliver()`), with no queue row and no FIFO pop.
- `deliver()` checks `claim()` → `allows()` and `send()` → `allows()` again, so only the pinned event leaves Laravel.
- **Not used:** `queue:work --queue=hermes-webhooks`, `hermes:cron`, `hermes:webhook-scan` (`scan()` creates a queue row, and the FIFO worker would pop `#30`/`#31` first). Scheduled tasks stayed Disabled.

Dispatch result: `status=delivered`, `attempts=1`, `last_http_status=202`, `delivered_at=2026-11-27 13:23:02 UTC`. After dispatch `jobs` still showed `30` and `31` with `attempts=0` and `reserved_at=NULL`.

## 4. Hermes-Side Scope: Finding and Mitigation

**Finding.** `/opt/data/scripts/joyumkm_intake.py` (Hermes admission) branches on `state/dev-operation.env`:

- `JMI_DEV_OPERATION_MODE=normal` → `os.environ.pop("JMI_UAT_SCOPE_ENABLED")`, sets `JMI_ARTIFACT_PIPELINE_ENABLED=1` and `JMI_ARTIFACT_DELIVERY_ENABLED=1`.
- any other value → sets `JMI_UAT_SCOPE_ENABLED=1` and sets **no** artifact flags.

The gateway container environment does not set the `JMI_ARTIFACT_*` flags (verified: `env` in `hermes-agent` lists only `HERMES_*`). Therefore:

- Switching to a non-normal mode would enforce the Hermes pin, but the job would not be enrolled for artifact generation, so PDF/XLSX could not be produced (Step 5 expected flow would be impossible).
- Keeping `normal` (the existing setting, unchanged by Run 2) enables the artifact pipeline and email delivery, but `uat_allows()` returns `True` for every job, so the Hermes-side pin is not enforced.

**Decision.** `JMI_DEV_OPERATION_MODE` was **not changed** (remains `normal`, identical to the pre-run value). Isolation was therefore established on the Laravel side, which is the only sender of events to Hermes:

1. Laravel `allows()` gates `enqueueSafely`, `scan`, `claim`/`deliver` and `requeue`. Only event `#25` passes while the pin is active.
2. No process that could transmit other events was run. Hermes cron (`jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring`) stayed `paused`. Windows tasks stayed `Disabled`. No `queue:work` ran on `hermes-webhooks`.
3. Pre-existing Hermes JMI jobs were all terminal (17 `completed`, 2 `failed` with `retryable=False`). No pending, processing, or retryable job existed to resume (`preflight-safety-verification.md` §3).
4. The UAT-scope tests in `HermesWebhookOutboxTest` pass on the testing database (`--filter='uat|Uat|scope|Scope|pin|Pin'`: 2/2 passed, 17 assertions), including the case that discards a pre-existing envelope outside the current pin.

**Residual risk (recorded, not hidden).** If any non-Laravel process posted a different event to the Hermes dev route during the window, normal-mode intake would admit it. No such process existed. The Hermes-side job list after the run is checked in `intelligence-report-live-uat.md`.

**Hermes-side pin file.** `state/uat-scope.json` was written with the same pin (0600) for consistency with the mandate. It is **inert** while `JMI_DEV_OPERATION_MODE=normal` because `uat_allows()` returns `True` when `JMI_UAT_SCOPE_ENABLED` is not `1`. It was removed during rollback.

## 5. Budget Guard in Normal Mode

In `normal` mode intake attaches a `corrective_authorization` (`max_attempts: 2`) to new jobs, which could add LLM calls after a failed analysis. A background guard (container, copy-out only) checked `attempts`, `auto_retry_cycles` and `corrective.used` every 3 seconds and would have stopped the worker on any second submission. It logged `processing → completed` with no trip, `attempts=1`, `corrective.used=0`, and `analysis_submission.corrective=false`. The guard log is saved as `jmi_guard_5dd7efbf.log` in the restricted backup folder.

## 6. Verdict

Laravel-side isolation: **PASS**, proven by gate decisions, the targeted dispatch, the queue state before and after, and the passing UAT-scope tests.
Hermes-side enforcement: **PARTIAL**, because the mode required to produce artifacts makes the Hermes pin inert. This is a documented gap, not a silent one, and it is carried into the final handoff for operator review.
