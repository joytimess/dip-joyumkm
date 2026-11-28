# P6+P7+P8 Implementation Report

**Time (WIB, 27 Nov 2026):** start 23:21, implementation + verification finished ≈23:30; reports written ≈23:35. Deadline was 23:51. No budget overrun.
**Agent:** Claude Code (Sonnet), single agent, **0 subagents**.

## Skills / tools actually used

* Project `CLAUDE.md` rules, Graphify `query` once (orientation only; it returned P1 documents and was not useful, so source/runtime inspection was authoritative). `graphify update .` was **not** run: no application code changed in P6–P8, so the index would not change.
* Direct execution of the PRD plan (P6 → P7 → P8), bounded verification, security-oriented read of the SQL validator.
* Not used (not needed): Superpowers, Ponytail, systematic-debugging, TDD, subagents.

## P6 — what was done

| Step | Result |
|---|---|
| Preflight | `APP_ENV=local`, `http://joyumkm.test`, operational DB `joyumkm-v2`, phpunit forces `joyumkm_testing`, legacy analytics DB `joyumkm_p1_prod` is distinct. MySQL 8.4.3. |
| Active analytics config found | `/opt/data/joyumkm-analytics/mysql.json` in container `hermes-agent` (volume `hermes_data`, owner `hermes`, mode 600). Read by `visual_workflow_hermes/mysql.py::_credential()` **on every query** → no restart needed. Key `database` was `joyumkm_p1_prod`. |
| Backup (before any change) | `C:\Users\itsba\backups\p678-20261127\` (outside repo, ACL limited to the current user): full `joyumkm-v2` dump with routines/triggers/events (8 triggers confirmed), legacy view definitions, analytics grants (no hashes), redacted `mysql.json`, `SHA256SUMS.txt` (verified OK). `mysql.json` was deliberately **not** copied because it holds the password; the only change is one key, so rollback is a one-key edit (see Handoff). |
| Views | `docs/jobsheets-6/evidence/analytics-views-v2.sql` (follows the existing `analytics-views.sql` convention; idempotent `CREATE OR REPLACE`). |
| Definer | New locked, password-less-by-design account `joyumkm_analytics_def@localhost` (`IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK`, generated password discarded, never logged in). SELECT only on `umkms`, `sectors`, `evaluations`, `six_factor_evaluations`, `analytics_dataset_members`, `analytics_dataset_cohorts`. Replaces the legacy `root@localhost` definer. |
| Runtime grants | Existing `joyumkm_analytics_ro@localhost` (REQUIRE SSL, `ssl_type=ANY`): SELECT on the 3 `joyumkm-v2` views added; SELECT on the 3 legacy `joyumkm_p1_prod` views **revoked** after the canary passed. Nothing else touched. |
| Cutover | `mysql.json` key `database`: `joyumkm_p1_prod` → `joyumkm-v2`. Nothing else in the file (host, port, user, password, CA, `ssl_verify_identity`) changed; mode/owner preserved. No restart, no new container, no workflow/cron touched. |
| Monitoring | No change. `SixFactorMonitoringController` still uses `whereDoesntHave('datasetMember')` before `orderBy`/`limit`, cap 100, `truncated` flag. Live: 29 eligible non-cohort (< cap), 0 cohort. Cursor pagination **not** implemented (PRD-permitted fallback): the Hermes consumer also needs a change and there is no safe live test, so the cap stays and the limit is documented. |
| `methodology_status=partial` | Source is the Hermes report engine (`jmi/schema.py`, `engine.py`; Laravel only validates `complete|partial`). The underlying methodology is still incomplete, so the honest `partial` is retained. No edit. |
| `test_analytics_workflow`, `test_commands` FakeCtx, `JMI_HOME` ambiguous-outcome test | Not touched (not required by the new query path, and running them risks live `/opt/data` state). Remain backlog. |

### View contract (same names / aliases / semantics as legacy)

| View | Columns | Grain | Predicate |
|---|---|---|---|
| `analytics_umkms` | `id, name, sector, province, city` | 1 row per member UMKM | `analytics_dataset_members.umkm_id = umkms.id` → `analytics_dataset_cohorts.id`, `dataset_key='joyumkm-analytics' AND dataset_version='v2'`, inner join `sectors` |
| `analytics_evaluations` | `id, umkm_id, ins_score, ect_score, ops_score, average_score, submitted_at, period_month` (`DATE_FORMAT(submitted_at + INTERVAL 7 HOUR,'%Y-%m')`, WIB) | 1 row per final 3D evaluation | membership predicate above + `status='final' AND submitted_at IS NOT NULL` |
| `analytics_six_factor_evaluations` | `id, umkm_id, sumber_daya, kepemimpinan, lingkungan_kerja, nilai_organisasi, stabilitas, kinerja, total_score, finalized_at, period_month` | 1 row per final 6F evaluation | membership predicate + `status='final' AND finalized_at IS NOT NULL` |

No `SELECT *`, no `user_id`, emails, contacts or credentials exposed; owner is never used for selection. The legacy `analytics_umkms`-chained join was replaced by a direct membership join in each view (so the definer needs no privilege on another view).

## Deviations from the PRD

1. Legacy-view grants were revoked immediately after the canary (PRD allowed "if safe and authorized"); exact reversal is in the Handoff.
2. The view provisioning is a SQL file in `docs/jobsheets-6/evidence/` (existing convention) rather than a Laravel migration, to avoid a migration that would break `joyumkm_testing` refreshes by needing a definer account and root privileges.
3. `mysql.json` was not backed up in plaintext (secret); redacted copy + previous key value recorded instead.
4. Monitoring pagination, `test_analytics_workflow`, `test_commands`, `JMI_HOME` test: not done (see above).
5. No Graphify update (no code change) and no subagents.
6. A second file outside the three deliverables was added: `analytics-views-v2.sql` (provisioning source, not a report).

## Changed files / runtime artifacts

| Item | Location |
|---|---|
| New | `docs/jobsheets-6/evidence/analytics-views-v2.sql` |
| New | `docs/development/multi-role-unified-analytics/phase-6-7-8/*.md` (3 reports) |
| Runtime (not in git) | container `hermes-agent:/opt/data/joyumkm-analytics/mysql.json` (key `database`) |
| DB objects | 3 views in `joyumkm-v2`; user `joyumkm_analytics_def@localhost`; grants listed above |
| Application code | **No PHP/JS/Python source changed in P6–P8.** The 20 modified tracked files in `git status` are the pre-existing, uncommitted P2–P5 work and were left intact. Nothing committed or pushed. |

## P7 / P8 work done

P7: bounded read-only verification and one targeted test run (see Verification Results). P8: final source/config review, backup/rollback/runbook (Handoff). No production action.
