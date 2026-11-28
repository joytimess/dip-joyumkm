# P6+P7+P8 Verification Results

Evidence labels: **LIVE-RO** actual read-only runtime · **ISOLATED** test DB, no network · **CODE** source/config review · **REUSED** earlier phase · **OPERATOR** operator-reported · **NOT RUN**.

## Gate matrix

| Gate | Verdict | Evidence |
|---|---|---|
| G678-01 Hermes analytics uses `joyumkm-v2` views | **PASS** | LIVE-RO (adapter, in container, as user `hermes`) |
| G678-02 Restricted principal, TLS, validator | **PASS** | LIVE-RO (grants, TLS, denied base tables, legacy denied) + CODE (validator) |
| G678-03 Monitoring excludes cohort; no side effects | **PASS** (limitations: cap 100, `partial`) | CODE (selector) + LIVE-RO (29 / 0) + ISOLATED (16 tests) |
| G678-04 App/RBAC/cohort/ownership integrity | **PASS** | LIVE-RO fingerprints + REUSED P4+P5 (199/46/build, OPERATOR UAT 12/12) + ISOLATED |
| G678-05 Protected report/email/onboarding/webhook contracts | **PASS** (bounded: nothing changed) | CODE + REUSED P1 real email/PDF/XLSX; no new send |
| G678-06 Local config, backup/rollback, blockers | **PASS** | LIVE-RO + backup checksums; restore **not rehearsed** |
| G678-07 Three documents, timing, limitations | **PASS** | these files |

## P6 — actual Hermes runtime canary (LIVE-RO)

Run via `visual_workflow_hermes.mysql` inside `hermes-agent` as user `hermes`, with the persistent `mysql.json`. No model, Telegram, workflow run or write.

| Check | Result |
|---|---|
| Server `DATABASE()` | `joyumkm-v2` |
| `CURRENT_USER()` | `joyumkm_analytics_ro@localhost` (container connection matches the `@localhost` account; the old account metadata was confirmed, not assumed) |
| TLS | secure=True, `TLS_AES_256_GCM_SHA384`; CA verified (`verify_mode`), account is REQUIRE SSL. `ssl_verify_identity=false` is the **pre-existing** setting and was kept |
| `COUNT(*)` via adapter | `analytics_umkms` 458 · `analytics_evaluations` 1,374 · `analytics_six_factor_evaluations` 1,374 (`truncated=false`, scope `synthetic-cohort`) |
| Aggregate shape | sector × AVG(average_score), joined `analytics_umkms`→`analytics_evaluations`, returned 3 rows through validator + READ ONLY txn + formatter (e.g. Makanan & Minuman 384 / 49.8) |
| Schema step | periods 2025-11 … 2026-10 for both evaluation views |
| Direct admin SQL | Same 458/1,374/1,374; 0 rows in any view whose `umkm_id` is not in `analytics_dataset_members`; 0 duplicate ids; all rows `status='final'`; 3 views definer `joyumkm_analytics_def@localhost`, SQL SECURITY DEFINER |

Final active config (redacted): `host=host.docker.internal`, `port=3306`, `user=joyumkm_analytics_ro`, `database=joyumkm-v2`, `ssl_ca=/opt/data/joyumkm-analytics/mysql-ca.pem`, `password=<redacted>`. No fallback: legacy views are no longer granted to this principal and the validator rejects qualified names.

Counts match the baseline exactly (458 / 1,374 / 1,374); no legitimate concurrent changes were observed.

## P6 — negative checks

| Check | Result |
|---|---|
| `SELECT 1 FROM analytics_dataset_members/umkms/users LIMIT 0` as ro | denied, MySQL error 1142 (LIVE-RO) |
| `SELECT 1 FROM joyumkm_p1_prod.analytics_umkms LIMIT 0` as ro, **before** revoke | allowed (staged grant, expected) |
| Same, **after** revoke | denied, 1142 (LIVE-RO) |
| Effective grants `joyumkm_analytics_ro` | `USAGE` + SELECT on the 3 `joyumkm-v2` views only; no GRANT OPTION, no base tables, no global privileges |
| Grants `joyumkm_analytics_def` | `USAGE` + SELECT on 6 base tables only; account locked |
| Validator (`validate_query`, nothing sent to DB) | rejected: `DELETE`, `UPDATE`, `SELECT 1; DROP TABLE x` (multi-statement), `` `joyumkm-v2`.umkms `` (cross-schema), `joyumkm_p1_prod.analytics_umkms`, unapproved table `umkms`, `LOAD_FILE`, `INTO OUTFILE`, `SELECT *`. Validator source **unchanged** (it already rejects any schema-qualified table). |
| Writes on live tables | not attempted |

## P6/P7 — data and runtime integrity (LIVE-RO, before vs after)

| Item | Preflight | After cutover |
|---|---|---|
| UMKM / 3D / 6F rows | 487 / 1,429 / 1,407 | 487 / 1,429 / 1,407 |
| Members / non-cohort | 458 / 29 | 458 / 29 |
| Ownership fingerprint (`id:user_id`) | `22548f25…` | `22548f25…` |
| Business-email fingerprint (hashed) | `24a4f529…` | `24a4f529…` |
| Member-id fingerprint | `5fe875a0…` | `5fe875a0…` |
| Ownership transfers | 1 | 1 |
| Outbox | #25 delivered, #24 and #23 pending | identical |
| Jobs | #30, #31 queue `hermes-webhooks`, attempts 0 | identical |
| Containers | `hermes-agent`, `hermes-workflow-ingress`, `hermes-webhook-proxy` up | unchanged, no restart |
| Hermes cron | `jmi-recover`, `jmi-recover-prod`, `workflow:umkm-monitoring`: enabled=False, paused | unchanged |
| Windows tasks | Webhook Worker, Laravel Scheduler: Disabled | unchanged |
| `/up` | 200 | 200 |

Note: the baseline said 0 transfers at P4+P5; the live DB has **1** transfer row (legitimate operator UAT activity, retained, append-only). Not reverted.

## P7 — targeted checks

| ID | Verdict | Evidence |
|---|---|---|
| V678-01 | PASS | P6 canary above |
| V678-02 | PASS | fingerprints/counts identical; no seeder, no transfer, no row edit |
| V678-03 | PASS | REUSED P4+P5 (199 backend, 46 JS, build, OPERATOR 12/12); P6 changed no app code; `php artisan test --filter='Monitoring|SuperadminManagementTest|RoleFoundationTest'` → **16 passed, 153 assertions** (ISOLATED, phpunit forces `joyumkm_testing`); `/up` 200 |
| V678-04 | PASS (bounded) | CODE: no report/snapshot/PDF/XLSX/callback/download file changed in P6; REUSED P1 real report email + P4+P5 Tab Intelligence OPERATOR check. No new send |
| V678-05 | PASS (bounded) | CODE: onboarding event/queue/recipient untouched; REUSED P1 real onboarding email + P4 isolated exactly-one dispatch. No registration, no email |
| V678-06 | PASS | monitoring selector unchanged and tested (ISOLATED); 29 eligible non-cohort < cap 100, 0 cohort; validator unchanged; no cron/LLM/channel |
| V678-07 | PASS (with blockers recorded) | table above; no queue processing, replay or flag change |

Flags read-only (names/values, no secrets): Laravel `HERMES_WEBHOOK_ENABLED=true`; Hermes dev state `JMI_EMAIL_DELIVERY_ENABLED=1`, `JMI_EMAIL_DELIVERY_PAUSED=0`, `JMI_EMAIL_STATUS_SYNC_ENABLED=0`, `JMI_EMAIL_STATUS_SYNC_PAUSED=1`; prod-state file `JMI_EMAIL_DELIVERY_ENABLED=0`. Scope pin: no active `uat-scope.json` exists under `/opt/data/joyumkm-intelligence/state` or `/joyumkm-intelligence-prod/state`; only historical `*.removed-*` copies. Nothing changed.

## Limitations and NOT RUN

* **NOT RUN:** real Telegram/WhatsApp/LLM end-to-end analytics question, live monitoring run, live email/report/onboarding, full PHPUnit/pytest, Python plugin tests (`test_mysql.py`, `test_analytics_workflow`, `test_commands`), fresh-DB view provisioning in `joyumkm_testing`, owner-independence fixture test, restore rehearsal, pre-signed/post-transfer download test.
* **Not an end-to-end PASS:** the adapter was exercised directly in the Hermes runtime; the model-driven conversational path was not invoked.
* Owner independence of views: CODE only (definitions contain no `user_id`/owner predicate). No live transfer was performed to test it.
* Monitoring cap 100 and `truncated` behaviour unchanged; 29 eligible now. Cron must stay paused if this grows past 100 or pending work is unresolved.
* `methodology_status=partial` is real (Hermes engine); not changed.
* Pre-existing, unchanged: P4+P5 limitations (code-review-only transfer branches, operator-attested UAT), `ssl_verify_identity=false`, RSK-01 secret rotation, RSK-08 queue/status reconciliation, `test_analytics_workflow`/`test_commands`/`JMI_HOME` backlog.
* Flag mismatch observed: dev email delivery enabled/unpaused while email status-sync is disabled/paused; outbox #23/#24 and jobs #30/#31 are pending. This blocks operational activation (see Handoff).
