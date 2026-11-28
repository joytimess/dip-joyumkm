# P6+P7+P8 Final Handoff

**Combined verdict: READY FOR OPERATOR REVIEW — ACCEPTANCE PENDING (LOCAL SCOPE).**
Reason it is not the unqualified "COMPLETED": new non-critical limitations need operator acceptance (monitoring pagination not added, adapter canary only, owner-independence by code review, restore not rehearsed). All seven gates are PASS within their stated evidence scope. **Operational activation: BLOCKED. Production: NOT DEPLOYED.**

| Phase | Verdict |
|---|---|
| P6 Hermes integration | **PASS** (G678-01/02/03; limitations documented) |
| P7 Final verification | **PASS** (bounded smoke; G678-04/05) |
| P8 Local readiness and handoff | **PASS** for local readiness (G678-06/07); activation of workers/cron NOT done |

Time: 23:21 → ≈23:35 WIB (budget 30 min, not exceeded).

## Final active local architecture

* Laravel app `http://joyumkm.test` → MySQL `joyumkm-v2` (unchanged; `APP_ENV=local`).
* Hermes conversational analytics → container `hermes-agent` → `/opt/data/joyumkm-analytics/mysql.json` (persistent volume `hermes_data`) → `joyumkm_analytics_ro@localhost` over TLS → **`joyumkm-v2`.`analytics_umkms` / `analytics_evaluations` / `analytics_six_factor_evaluations`** (cohort `joyumkm-analytics` v2 via `analytics_dataset_members`, 458/1,374/1,374).
* Views run as the locked definer `joyumkm_analytics_def@localhost` (SELECT on 6 base tables). The reader has SELECT on the 3 views only.
* SQL validator in `visual_workflow_hermes/mysql.py` unchanged: single SELECT, whitelisted nodes, unqualified approved views only, bound params, ≤200 rows, 5 s timeout, READ ONLY transaction.
* Monitoring: Laravel `SixFactorMonitoringController` (non-cohort only, cap 100, 29 eligible) — unchanged; its Hermes schedule stays paused.
* Legacy `joyumkm_p1_prod`: intact, no longer granted to the active account; rollback reference only.
* `ANALYTICS_DATASET_AUTO_SEED` production opt-in and the P3 seeder were **not** executed. Fresh-environment setup: run migrations, run the seeder under its existing controls, apply `docs/jobsheets-6/evidence/analytics-views-v2.sql` as a local DBA, create the reader account with REQUIRE SSL, and set `database` in the Hermes `mysql.json`.

## Backups (outside the repository, ACL limited to the current Windows user)

`C:\Users\itsba\backups\p678-20261127\` — `joyumkm-v2-pre-p678.sql` (full dump incl. 8 triggers, routines, events), `legacy-views-defs.txt`, `analytics-ro-grants-pre.txt`, `analytics-ro-grants-post.txt`, `mysql.json.redacted-pre`, `SHA256SUMS.txt` (verified). Earlier: `storage/app/p45-backup/joyumkm-v2-pre-p45.sql` (git-ignored). The P6 change added only views/grants/one config key, so no further snapshot was needed. **Restore from these files was not rehearsed** (P1 rehearsal is prior evidence only).

## Rollback (each MySQL DDL step commits implicitly; do them in this order and record each)

1. Point analytics back at legacy (degraded, stale 428 cohort): in the container set `database` to `joyumkm_p1_prod`:
   `docker exec hermes-agent python3 -c "import json;p='/opt/data/joyumkm-analytics/mysql.json';d=json.load(open(p));d['database']='joyumkm_p1_prod';open(p,'w').write(json.dumps(d))"`
2. Restore legacy grants (as local DBA): `GRANT SELECT ON joyumkm_p1_prod.analytics_umkms|analytics_evaluations|analytics_six_factor_evaluations TO 'joyumkm_analytics_ro'@'localhost';` Verify with the canary (expect legacy 428/1,284/1,284).
3. Only if removing the v2 objects: `REVOKE SELECT` on the 3 `joyumkm-v2` views from the reader, `DROP VIEW` the 3 views, `DROP USER 'joyumkm_analytics_def'@'localhost'`.
4. Do **not** restore the whole database dump, drop audit/transfer tables, or run P2/P3 rollbacks (one real ownership transfer exists). Do not delete `joyumkm_p1_prod` or any Hermes volume. If rollback cannot be completed, leave the reader without any analytics grants (fail-closed) and escalate.

## Local operator runbook (read-only)

* App: `curl -s -o NUL -w "%{http_code}" http://joyumkm.test/up`; `php artisan about --only=environment`.
* Containers: `docker ps --format "{{.Names}} {{.Status}}"` (expect `hermes-agent`, `hermes-workflow-ingress`, `hermes-webhook-proxy`). Do not run `docker compose down`.
* Analytics canary (no model, no writes): from a script copied into the container, run as `-u hermes`:
  `import sys; sys.path.insert(0,"/opt/data/plugins/visual-workflow"); from visual_workflow_hermes import mysql as m; print(m.execute_query("SELECT COUNT(*) AS n FROM analytics_umkms", [])["rows"])` → expect `[{'n': 458}]` (counts may grow with legitimate new final evaluations); also check `SELECT DATABASE(), CURRENT_USER()` and `Ssl_cipher` through `m._credential("joyumkm-analytics")`.
* Grants: `SHOW GRANTS FOR 'joyumkm_analytics_ro'@'localhost';` — expect 3 `joyumkm-v2` views only.
* Cohort: `SELECT COUNT(*) FROM analytics_dataset_members;` (458) and non-cohort `umkms` (29).
* Pending state: `SELECT id,status FROM webhook_outbox ORDER BY id DESC LIMIT 5;` `SELECT id,queue,attempts FROM jobs;` Windows tasks: `Get-ScheduledTask | ? TaskName -match 'JoyUMKM'`; Hermes cron: read `/opt/data/cron/jobs.json` (enabled/state) — do not toggle.
* Tests (isolated DB only): `php artisan test --compact --filter=<name>`. Never `migrate:fresh --env=testing`.
* Placeholders for secrets: `<DB_ROOT_PASSWORD>`, `<ANALYTICS_RO_PASSWORD>` — never store them in the repo.

## Known backlog

* Monitoring cap 100 without cursor pagination (needs Laravel + Hermes consumer change and an isolated fixture).
* `methodology_status=partial` (Hermes report engine methodology incomplete).
* Python tests `test_analytics_workflow` (formatting), `test_commands` (FakeCtx), `JMI_HOME` ambiguous-outcome test unsafe against live state.
* `ssl_verify_identity=false` (pre-existing; certificate hostname not verified, CA is).
* Owner-independence of views verified by definition only; add a small isolated fixture test when convenient.
* RSK-01 secret rotation, RSK-08 queue/status reconciliation, real Telegram/WhatsApp/LLM verification: separate authority.
* P4+P5 accepted limitations stand (transfer branches code-reviewed only, operator-attested UAT, no live email UAT). P3 live `db:seed`, fresh-DB seed, restore rehearsal still not performed.

## Operational activation blockers (nothing was activated)

1. Outbox #23/#24 pending and jobs #30/#31 queued: need an operator-approved reconciliation of event/job identities and duplicate-send risk before any worker run.
2. Dev Hermes state has email delivery enabled/unpaused (`JMI_EMAIL_DELIVERY_ENABLED=1`, `PAUSED=0`) while email status-sync is disabled/paused (`ENABLED=0`, `PAUSED=1`): a delivery/status-sync mismatch that must be reconciled first.
3. No active `uat-scope.json` pin was found in the two inspected state directories (only `*.removed-*` copies); confirm the intended intake scope before resuming normal intake. Nothing was created or deleted.
4. Windows Webhook Worker and Laravel Scheduler tasks remain Disabled; Hermes `jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring` remain paused. Resume only per task after explicit approval; confirm monitoring completeness (29 < 100) first.
5. Production deployment requires its own authorization.

## Next operator decision

Review and accept (or reject) the listed non-critical limitations; if accepted, the local milestone closes as "completed with accepted limitations". Then decide separately whether to authorise a reconciliation plan for the pending outbox/jobs and the delivery/status-sync flags, and which single task or workflow may be resumed first. Nothing was committed or pushed.
