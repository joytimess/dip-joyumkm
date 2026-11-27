# Evidence: Runtime Before / After (Run 2)

- **Run:** `run2-20261127-2015`
- **Before:** 2026-11-27 20:04–20:19 WIB (Run 2 preflight)
- **After:** 2026-11-27 20:28 WIB (post-rollback)

| Dimension | Before | After | Change |
|---|---|---|---|
| Git `HEAD` | `aaa05da` | `aaa05da` | None |
| Tracked file changes | none | none | None (evidence written under untracked `docs/`) |
| Containers | 3 Up (`hermes-agent`, `hermes-workflow-ingress`, `hermes-webhook-proxy`) | Same 3 Up | No new container |
| Windows Scheduled Tasks | 2 Disabled | 2 Disabled | None |
| Laravel `php.exe` queue/scheduler workers | 0 | 0 | None |
| Hermes cron | 3 paused | 3 paused | None |
| `joyumkm-v2` UMKM | 456 | **457** | +1 (fixture `457`) |
| `joyumkm-v2` users | 7 | **8** | +1 (authorized onboarding account) |
| `six_factor_evaluations` | 1,316 | **1,317** | +1 (fixture evaluation) |
| `evaluations` (legacy) | 1,339 | 1,339 | None |
| `migrations` | 22 | 22 | None |
| `webhook_outbox` rows | 24 | **25** | +1 (`#25`, `delivered`) |
| `webhook_outbox` `#23`, `#24` | `pending` | `pending` | None |
| Laravel `jobs` | `#30`, `#31` (`hermes-webhooks`) | `#30`, `#31` (`hermes-webhooks`) | None (onboarding job `#32` ran and was removed) |
| `failed_jobs` | 0 | 0 | None |
| `intelligence_reports` | 10 | **11** | +1 |
| `intelligence_artifacts` | 12 | **14** | +2 |
| `intelligence_artifact_manifests` | (not recorded in Run 1) | +1 for report 11 | +1 |
| `intelligence_deliveries` | not captured at start of Run 2 | **2** (`id 2` is this run's `sent` row) | +1 for this run (prior count not recorded) |
| Hermes JMI job directories | 19 | **20** | +1 (`5dd7efbf-…`, completed) |
| Hermes `email.env` | `1/0` | `1/0` | None |
| Hermes `delivery-status.env` | `0/1` | `0/1` (restored after temporary `1/0`) | None at end |
| Hermes `dev-operation.env` | `normal` | `normal` | None |
| Hermes `uat-scope.json` | absent | absent | None |
| Laravel `.env` | `71571d34…` | `71571d34…` | None at end (temporary keys reverted) |
| Laravel `hermes-uat-scope.json` | absent | absent | None at end |
| Onboarding `settings.json` | `944c0fd4…` | `944c0fd4…` | None |
| Hermes cron `jobs.json` | `0a9b7d79…` | `0a9b7d79…` | None |

## Notes

- The **net data change** is limited to the authorized fixture: 1 UMKM, 1 evaluation, 1 outbox row, 1 report, 2 artifacts, 1 manifest, 1 delivery, 1 account, and the Hermes JMI job for that event. Nothing existing was modified.
- The **28 non-cohort UMKMs** and **428 synthetic UMKMs** were not written to. Verified by `updated_at`: since 2026-11-27 20:00 WIB, the only `umkms` row written is `id 457`, and the only `users` row written is `id 8`.
- The `intelligence_artifact_manifests` row count before Run 2 was not captured in Run 1, so the "before" value is left blank here rather than guessed.
