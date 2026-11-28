# Reproducible Hermes Infrastructure — Final Handoff

**Verdict: REPRODUCIBLE HERMES INFRASTRUCTURE — READY FOR OPERATOR REVIEW (LOCAL SCOPE)** for the *existing-deployment* scope.
This is **not** a claim that a fresh workstation installs end-to-end: that path is implemented but unverified.

| Independent status | Result |
|---|---|
| Existing deployment adoption | **PASS** — `compose.yaml` is structurally equivalent to the live 3-container runtime; 0 drift; assets hash-match; no restart, recreate or write. Labels still point at the legacy compose file (not migrated). |
| Fresh one-command installation | **IMPLEMENTED — NOT RUN** (and PARTIAL by design: plugin enable, webhook subscriptions, paused crons, provider login, `state/*.env` secrets stay manual). |
| Operational activation | **BLOCKED** — unchanged; 6 blockers reported by `check-hermes.ps1`. |
| Production | **NOT DEPLOYED** |

Time: 23:48:48 → ≈00:08 WIB, ≈19 min of 30 min. Nothing committed or pushed; P2–P5 uncommitted work untouched (no reset/clean/stash).

## Use it

```powershell
.\scripts\check-hermes.ps1                      # read-only; exit 0 = INFRA_READY + INTEGRATION_READY
.\scripts\setup-hermes.ps1 -Mode Adopt          # existing deployment; verifies, changes nothing
.\scripts\setup-hermes.ps1 -Mode Fresh -Plan    # dry run for a new workstation
.\scripts\backup-hermes.ps1 -Destination C:\Users\itsba\backups\hermes
```
Docs: `docker/hermes/README.md`. **Do not run `docker compose up/down/pull` on the live stack from `compose.yaml`** without a maintenance window (the digest reference, working-dir label and config hash differ from the running containers, so Compose may recreate them).

## Decisions for the operator

1. Accept or reject the limitations below; none was silently accepted on your behalf.
2. Authorize (or not) a **clean-room Fresh run** on isolated resources (separate volume/project/ports and a disposable DB) — the only way to promote Fresh to VERIFIED. I did not create any test containers.
3. Decide whether a maintenance window should migrate container labels to the repo `compose.yaml` (requires a recreate).
4. Separate milestone: reconcile pending outbox #23/#24 and jobs #30/#31, the email-delivery vs status-sync mismatch, UAT scope pin, paused crons, Disabled tasks, secret rotation.

## Limitations carried / introduced

* Fresh branch untested (see Verification). Fresh also needs host `git`+`python`, network access to GitHub (pinned commit) and PyPI (pinned versions, no hashes), and an empty Docker host (it refuses otherwise).
* Only config/inventory is backed up; **no consistent volume snapshot, restore not rehearsed**. Backups live outside the repo at `C:\Users\itsba\backups\hermes-infra-20261128\hermes-20261128-000314`.
* Overlay excludes 3 plugin test files and `.hermes-catalog.json`; `callback_registry.json`, live `state/*.env`, webhook subscriptions and cron state are not versioned (operational state). Knowledge-file hashes are in the manifest but not runtime-checked.
* `ssl_verify_identity=false` (CA verified, hostname not) preserved; `:8651/health` path unverified (WARN); the www tree is mounted read-only and the repo read-write into `hermes-agent` (baseline, unchanged); `hermes-agent` runs as root (baseline).
* Disclosed deviation: I briefly created `/root/.gitconfig` in the live `hermes-agent` container's writable layer and removed it immediately (volume untouched, container not restarted).
* Skills/subagents: none used.

## Next step

Operator review of this handoff and the new files (`compose.yaml`, `scripts/`, `docker/hermes/`, `.gitignore`/`.gitattributes` additions, this folder). No next milestone was started.
