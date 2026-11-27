# Evidence: Database Read-Only Inspection Results

- **Evidence ID:** `EV-DB-001` (Runtime Closure Update)
- **Observed at:** `2026-11-27T19:04:15+07:00` – `2026-11-27T19:06:07+07:00` (`Asia/Jakarta`)
- **MySQL Server:** `8.4.3` (`127.0.0.1:3306`, `tls_version=TLSv1.2,TLSv1.3`, `require_secure_transport=0`, `global_tz=SYSTEM`, `session_tz=SYSTEM`; Laravel connects with `timezone=+00:00`)
- **Live Query Status:** **VERIFIED LIVE (Read-Only Transaction `SET SESSION TRANSACTION READ ONLY; START TRANSACTION READ ONLY; ... ROLLBACK;`)**

---

## 1. Live Server, Schemas & MySQL Grants Inventory

- **Relevant Schemas on Server (`30` of `75` total DBs):** `joyumkm-v2`, `joyumkm_p1_prod`, `joyumkm_testing`, `joyumkm_p1_local`, `joyumkm_p1_conc`, `joyumkm_jobsheet3`, `joyumkm_jobsheet4`, `joyumkm_recovered`, `joyumkm_staging_restore`, `joyumkm_tz_lab`, and 20 `joyumkm_p2_*` lab schemas.
- **Analytics Read-Only Account Verified (`joyumkm_analytics_ro@localhost`, `account_locked=N`, `ssl_type=ANY`):**
  - `GRANT USAGE ON *.* TO 'joyumkm_analytics_ro'@'localhost'`
  - `GRANT SELECT ON joyumkm_p1_prod.analytics_evaluations TO 'joyumkm_analytics_ro'@'localhost'`
  - `GRANT SELECT ON joyumkm_p1_prod.analytics_six_factor_evaluations TO 'joyumkm_analytics_ro'@'localhost'`
  - `GRANT SELECT ON joyumkm_p1_prod.analytics_umkms TO 'joyumkm_analytics_ro'@'localhost'`
  - **Scope Check:** Zero grants on `joyumkm-v2` and zero grants on any base table (`users`, `umkms`, `webhook_outbox`, etc.).

---

## 2. Live Inspection: `joyumkm-v2` (Operational App Database)

- **Structure:** `28` base tables, **`0` views**, `6` immutability triggers (`intelligence_reports`, `intelligence_artifacts`, `intelligence_artifact_manifests`), `22` migrations applied (`max_batch=10`, including `2026_11_26_035046_create_analytics_dataset_cohorts_table`). Also retains 4 P2 timezone audit tables (`timezone_offset_anchors`=6, `timezone_offset_columns`=35, `timezone_offset_corrections`=1, `timezone_offset_snapshot`=70).
- **`users` Schema Check:** `has_role=NO` (`id, name, email, email_verified_at, password, remember_token, created_at, updated_at`).

### 2.1 Live Account, Ownership, Evaluation & Answer Breakdown (`joyumkm-v2`)

| User ID & Label | Role Col | `umkms` (`min..max id`) | Email / Consent / WA | 3D Evals (`draft / final`) | 3D Answers | 6F Evals (`draft / cancelled / final`) | 6F `period_month` |
|---|---|---:|---:|---|---:|---|---|
| `user#1:demo@joyumkm.test` | None | **4** (`1..4`) | 0 / 0 / 0 | `1 draft, 3 final` | 64 | `0` | — |
| `user#2:sample1@joyumkm.test` | None | **11** (`5..28`) | 4 / 2 / 4 | `0 draft, 22 final` | 352 | `1 draft, 3 cancelled, 27 final` | `2026-11` |
| `user#3:sample2@joyumkm.test` | None | **5** (`13..17`) | 0 / 0 / 0 | `1 draft, 9 final` | 154 | `0` | — |
| `user#4:sample3@joyumkm.test` | None | **7** (`18..24`) | 0 / 0 / 0 | `2 draft, 17 final` | 272 | `0` | — |
| `user#5:p6-2bb54ae3bcb8bd@joyumkm.test` | None | **0** | 0 / 0 / 0 | `0` | 0 | `0` | — |
| `user#6:p6-2f3d519610120b@joyumkm.test` | None | **1** (`26`) | 1 / 1 / 1 | `0` | 0 | `1 final` | `2026-11` |
| `user#7:analitik.sintetis@dataset.invalid` | None | **428** (`29..456`) | 0 / 0 / 0 | `0 draft, 1,284 final` | 20,544 | `1,284 final` | `2025-11`..`2026-10` |
| **TOTAL (`joyumkm-v2`)** | — | **456** | **5 / 3 / 5** | **1,339** (`4 draft, 1,335 final`) | **21,386** | **1,316** (`1 draft, 3 cancelled, 1,312 final`) | — |

- **`analytics_dataset_cohorts` (1 row in `joyumkm-v2`):** `dataset_key='joyumkm-analytics'`, `dataset_version='v1'`, `user_id=7`, `reference_start_month='2025-11'`, `reference_end_month='2026-10'`, `umkm_count=428`, `three_dimension_count=1284`, `six_factor_count=1284`, `answer_count=20544`, `content_fingerprint='229e3dc07f86bcbaaae82c819fa367bdac56f37bdec186d7043dfa1b479e1560'`, `completed_at='2026-11-26 12:01:19'`.
- **Integrity Checks (`joyumkm-v2`):**
  - Orphan `umkms` (`user_id` missing): **`0`**. Cohort/synthetic columns on `umkms`: **`[]`** (none).
  - Case-insensitive UMKM name collisions between `user#7` (`428` synthetic) and `sample1/2/3` (`users 2,3,4`): **`0` collisions**.
  - `user#7` contact/consent cleanliness: `428/428` (`whatsapp_number=NULL`, `business_email=NULL`, `notification_consent_at=NULL`, `wa_registration_status='unchecked'`).
  - `user#7` `webhook_outbox` rows: **`0`**.
  - Answer cardinality: all `1,335` final 3D evaluations have `min=16, max=16` answers; `2` draft evaluations have `10..16` answers (`2` empty drafts have `0` answers).

### 2.2 Operational Tables & Monitoring Simulation (`joyumkm-v2`)

- **`webhook_outbox` (24 total rows):**
  - `evaluation.finalized` v1 `delivered`: `1` (`2026-11-22`)
  - `evaluation.finalized` v2 `delivered`: `15` (`2026-11-22`..`2026-11-25`)
  - `evaluation.finalized` v2 `dead`: `6` (IDs `9, 10, 13, 14, 15, 16`; `attempts=1`; `2026-11-25`, HTTP `404 http_rejected` or `200 ack_ignored`)
  - `evaluation.finalized` v2 **`pending`: `2`** (ID `23` `aggregate_id=31` `queued_job_id=30` created `2026-11-26 09:44:24`; ID `24` `aggregate_id=32` `queued_job_id=31` created `2026-11-26 09:58:50`; `attempts=0`)
- **`jobs` (`2` rows):** Queue `hermes-webhooks` (IDs `30` & `31`, `attempts=0`, `reserved_at=null`). **`failed_jobs`:** `0`.
- **`intelligence_reports` (`10` rows):** `6` `complete` (`methodology_status=partial`, IDs `4..10`), `4` `pending` (`methodology_status=partial`, IDs `1..5`).
- **`intelligence_artifact_manifests` (`6` rows)** · **`intelligence_artifacts` (`12` rows: `6` `pdf` + `6` `xlsx` on `storage_disk='intelligence'`)**.
- **`intelligence_deliveries` (`1` row):** `channel='email'`, `status='sent'`, `latest_sent='2026-11-25 07:15:48'`, `with_msg_id=1`.
- **`email_suppressions` (`0` rows)** · **`personal_access_tokens` (`6` active tokens)**.
- **`SixFactorMonitoringController` Current Query Simulation (`ORDER BY id LIMIT 100` on `joyumkm-v2`):**
  - First 100 rows returned: `user#1` (`4`, IDs `1..4`), `user#2` (`11`, IDs `5..28`), `user#3` (`5`, IDs `13..17`), `user#4` (`7`, IDs `18..24`), `user#6` (`1`, ID `26`) = **`28` non-synthetic UMKM**, **PLUS `72` synthetic UMKM from `user#7` (`id 29..100`)**.
  - Latest 6F `< 13` (*Prioritas Intervensi*) within first 100: `1` from `user#2` + **`26` synthetic UMKM from `user#7`**.
  - `356` synthetic UMKM (`id 101..456`) and any newly created operational UMKM (`id > 456`) are ALREADY truncated/crowded out.

---

## 3. Live Inspection: `joyumkm_p1_prod`, `joyumkm_testing` & Auxiliary QA Schemas

| Schema | Base Tables | Views (Row Counts) | Migrations | Users | UMKMs | 3D Evals / Answers | 6F Evals | Outbox / Jobs |
|---|---:|---|---:|---:|---:|---:|---:|---:|
| **`joyumkm_p1_prod`** | 24 | **3 `SQL SECURITY DEFINER` views** (`DEFINER=root@localhost`): `analytics_umkms` (**428**), `analytics_evaluations` (**1,284**), `analytics_six_factor_evaluations` (**1,284**) | 22 (`batch=1`) | 1 (`analitik.sintetis@dataset.invalid`) | 428 (`1..428`) | 1,284 / 20,544 | 1,284 | 0 / 0 |
| **`joyumkm_testing`** | 24 | 0 | 22 (`batch=1`) | 0 | 0 | 0 / 0 | 0 | 0 / 0 |
| **`joyumkm_jobsheet3`** | — | — | — | 3 | 0 | — | — | — |
| **`joyumkm_jobsheet4`** | — | — | — | 4 | 14 | — | — | — |
| **`joyumkm_p1_local`** | — | — | — | 1 | 428 | — | — | — |
| **`joyumkm_p1_conc`** | — | — | — | 1 | 428 | — | — | — |

- **`joyumkm_p1_prod.analytics_dataset_cohorts`:** Identical `content_fingerprint` (`229e3dc0...`) and period (`2025-11`..`2026-10`), `completed_at='2026-11-26 11:00:15'` (~1 hour before `joyumkm-v2`'s `12:01:19`).
- **View Definition Verified (`joyumkm_p1_prod.analytics_umkms`):** Joins `umkms u JOIN sectors s ON s.id = u.sector_id JOIN analytics_dataset_cohorts c ON c.user_id = u.user_id WHERE c.dataset_key = 'joyumkm-analytics' AND c.dataset_version = 'v1'`.
