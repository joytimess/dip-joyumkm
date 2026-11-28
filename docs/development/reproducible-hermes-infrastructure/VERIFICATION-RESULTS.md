# Reproducible Hermes Infrastructure — Verification Results

All checks ran against the **live running instance** (read-only) unless marked otherwise. Window ≈2026-11-27 23:56 → 00:04 WIB (≤5 min of targeted verification).
No restart, recreate, pull, webhook POST, model call, or outbound message occurred.

| Gate | Evidence (actual) | Verdict |
|---|---|---|
| **GI-01 Baseline preservation** | Before/after signature (container short IDs, `StartedAt`, status, image ID, restart count, mount-set hash, compose project status, volume count) **identical** after `-Mode Auto -Plan`, `-Mode Adopt` ×2, `-Mode Fresh -Plan` (refused), check ×3 and backup. Containers still `Up` since 12:01:21Z. App `/up` = 200. Pending outbox ids [24,23] and jobs [30,31] unchanged; 3 cron paused; 2 tasks Disabled; email/status-sync flags unchanged. | **PASS** |
| **GI-02 Canonical Compose** | `docker compose -p jobsheet2 -f compose.yaml config --quiet` OK. Redacted structural comparison vs runtime: image digest, restart, privileged/user/read-only/cap_drop/security_opt, ports, mounts (type/target/rw/source/subpath), networks, declared env key **values** (names reported only) → **0 differences** for all 3 services; no docker.sock. Initial run found 5 diffs caused by my own script bugs (legacy env file not located; undeclared `user` compared) — fixed, not infrastructure drift. | **PASS** (labels still legacy — documented) |
| **GI-03 Reproducible assets** | Manifest + files present: 7 workflows, 30 JMI files, 36 overlay files, knowledge, templates; repo hashes = manifest = runtime hashes (workflows, JMI, overlay, plugin 1.1.0). Image digest resolvable (`docker manifest inspect`). Pattern scan of candidate assets: only benign hits (hashes, DejaVu license text, placeholders); 3 plugin test files + `.hermes-catalog.json` excluded. **Literal scan:** 15 live secret values vs 193 files (repo assets + backup) → 0 hits. Pinned deps (pymysql/sqlglot) recorded but their install path not exercised. | **PARTIAL→PASS for existing scope**; fresh-install provisioning of plugin enable/subscriptions/cron is manual (listed) |
| **GI-04 Setup orchestration** | PowerShell parse of 4 scripts: no errors (5.1.26100). `-Mode Auto -Plan`→ classified `existing`, adopted read-only. `-Mode Adopt` run twice: exit 0, 0 FAIL, identical results, no writes. `-Mode Fresh -Plan` on the existing deployment → `STOP … never takes over existing resources`, exit 2, no mutation. **Fresh runtime NOT RUN.** | **PASS (Adopt/Plan/conflict)**; Fresh = IMPLEMENTED — NOT RUN |
| **GI-05 Analytics & workflow integration** | Canary (inside `hermes-agent`, as `hermes`, no model): `joyumkm-v2`, principal `joyumkm_analytics_ro@localhost`, TLS active, CA present, grants = SELECT on exactly 3 views, no identity columns, counts **458 / 1374 / 1374** (= P678 baseline), `mysql.json` mode 600. 7 workflow hashes = manifest. Webhook route `joyumkm-events-dev` enabled and **HMAC key agrees with Laravel** (SHA-256 compared locally, only booleans printed); route `joyumkm-events` differs (expected, prod not deployed). Path allowed by proxy allowlist. Ingress `:8650/health` 200; `:8651/health` **WARN** (path not verified as non-mutating contract). `ssl_verify_identity=false` reported WARN (known backlog). | **PASS (bounded)** |
| **GI-06 Backup, security, runbook** | Backup folder created outside repo, owner-only ACL, 98 files + SHA256SUMS, no env values (key names only), no secrets (literal scan 0). README documents commands, secrets, conflicts, rollback. **Restore not rehearsed; no volume snapshot.** | **PASS (config scope)** |
| **GI-07 Final readiness** | Three reports; independent statuses below. | see Final Handoff |

## `check-hermes.ps1` result (live)

`INFRA_READY: True` · `INTEGRATION_READY: True (bounded read-only, live runtime)` · `OPERATIONAL_ACTIVATION: BLOCKED (6 blockers)` · production NOT DEPLOYED · exit 0.
Blockers listed, none cleared: dev email enabled/unpaused vs status-sync disabled; 3 cron paused; no active UAT pin; onboarding enabled/live; pending outbox/jobs; 2 Disabled Windows tasks.

## Not verified (explicitly)

* Fresh one-command installation (clean room not authorized): staging, SQL provisioning branch, `docker run --rm` copy, `compose up`, pip-target install, plugin clone at pinned commit — **all unexecuted**; only prerequisite/plan logic and the refusal path ran.
* Restore from backups; consistent volume snapshot; label migration; behavior of `docker compose up` on the live stack (intentionally not run).
* `:8651` health semantics; knowledge-file hashes at runtime (recorded in manifest, not read by canary); plugin test files excluded from overlay.
