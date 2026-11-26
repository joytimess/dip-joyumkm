# Final Handoff — P2–P10 Hermes Conversational Analytics

**Verdict keseluruhan: PARTIAL.** Seluruh komponen inti P2–P6 dan P9 (konektivitas MySQL read-only + TLS CA + 3 view cohort, node MySQL native di canvas & executor, integrasi `gpt-6-luna`, validator AST `sqlglot` + parameter binding, filter/analisis, 102 targeted tests + live security/timeout/redaction checks, restart container & regression) telah **PASS** dan terverifikasi. Verdict keseluruhan tetap **PARTIAL** karena gate Telegram live E2E (P7/P8/P10) membutuhkan pesan `/analytics` aktual dari klien Telegram operator yang diotorisasi (`6293298092`) serta pemulihan kuota `openai-codex` yang saat sesi verifikasi lanjutan terkena `429 usage_limit_reached` (reset ~14:17 WIB).

## 1. Durasi Wall-Clock & Status P2–P10

- **Durasi wall-clock total:** ~37 menit across 2 sesi bersambung pada 2026-11-26 (`11:20–11:36 WIB` implementasi & capture awal; `11:39–11:57 WIB` audit lanjutan, live security/timeout/redaction/plugin-dispatch verification, dan pembaruan evidence/handoff) — masih jauh di bawah timebox 90 menit.

| Phase | Status | Ringkasan Bukti / Blocker |
|---|---|---|
| P2 | **PASS** | Koneksi TLS tervalidasi CA dari container `hermes-agent` (`uid 10000`) ke MySQL 8.4.3 (`joyumkm_p1_prod`). Akun khusus read-only hanya memiliki tepat 3 grant `SELECT` pada view `analytics_umkms` (428), `analytics_evaluations` (1.284), dan `analytics_six_factor_evaluations` (1.284); `out_of_cohort=0`, `orphaned_three=0`, `orphaned_six=0`. `UPDATE` view, `SELECT` tabel `users`, dan 2 referensi schema di luar scope ditolak MySQL error `1142`. Credential disimpan di `/opt/data/joyumkm-analytics/mysql.json` (`0600`), bukan di workflow JSON/repo. Bukti: `analytics-views.sql`, `p2-runtime.txt`. |
| P3 | **PASS** | Native node `mysql` (`operation`: `schema` dan `query`) terintegrasi di `node-spec.json`, `schema.py`, `convert.py`, `runner.py`, `dashboard/dist/index.js`, dan `mysql.py`. Dependency `pymysql==1.1.2` dan `sqlglot==27.28.1` dipin dan persisten di `/opt/data/joyumkm-analytics/dependencies`. Konfigurasi `credential_ref: joyumkm-analytics` ter-mask di inspector (`hermes-mysql-config.png`), koneksi selalu ditutup di `finally`, serialisasi `Decimal`/ISO/null teruji, dan validator AST dipanggil di boundary executor. |
| P4 | **PASS** | Auxiliary task `umkm_analytics` terdaftar untuk `openai-codex` / `gpt-6-luna` (tanpa fallback diam-diam). Tiga run live (`faf9a154cf8e`, `b81c4fe351ff`, `2160287aa1ae`) mencatat model ID aktual `gpt-6-luna` (`openai-codex`) dengan intent terstruktur berbeda. Penjagaan fail-closed anti-fallback teruji di unit test **dan** terbukti live saat `openai-codex` terkena `429 usage_limit_reached` (run `4b25ba325af2`, `8999bb433fce` langsung menolak fallback `claude-haiku-5-5[1m]` dengan `unexpected analytics model attribution`). |
| P5 | **PASS** | Validator AST berbasis `sqlglot 27.28.1` (fail-closed, bukan regex-only) dengan positional `?` parameter binding, allowlist view/kolom/join/fungsi, `SET SESSION MAX_EXECUTION_TIME` (≤5000 ms), `START TRANSACTION READ ONLY`, dan cap 200 baris + indikator `truncated`. Query Q7 dan Q4 yang dihasilkan AI cocok 100% dengan `mysql-reference.tsv`. 22 kasus penolakan AST + live test DML, metadata, cross-tenant, cross-schema, SQL injection, dan timeout redaction (`3024`/`1205` -> `mysql_timeout`) lulus. |
| P6 | **PASS** | Transform node `filter` (`preset: analytics_result`), kondisi `has_data`, node `analysis` (`gpt-6-luna`), dan formatter `_format_reply` memisahkan fakta SQL vs interpretasi/saran, menjaga presisi desimal, menolak angka karangan pada analisis, memperlakukan data DB sebagai data tidak tepercaya, dan menangani hasil kosong secara deterministik (`2160287aa1ae` -> skip `analysis`/`reply`, output `NO_DATA`). |
| P7 | **PARTIAL** | Command `/analytics` terimplementasi (`analytics_telegram.py`), terdaftar di plugin manager gateway (`['analytics', 'delegation', 'workflow']`), gateway Telegram berstatus `connected` (`@joyumkm_monitoring_bot`, polling tunggal), allowlist DM operator tunggal (`TELEGRAM_ALLOWED_USERS == TELEGRAM_HOME_CHANNEL == 6293298092`), dedupe `message_id`, rate limit 5 detik, sanitasi markup, dan 4 unit test keamanan/dedupe/rate-limit lulus. **Belum PASS:** belum ada pengiriman pesan `/analytics` live dari akun Telegram operator dan capture balasannya di chat asal. |
| P8 | **PARTIAL** | Workflow `umkm-analytics-assistant` (9 node) tersimpan persisten di `/opt/data/workflow-designs/umkm-analytics-assistant.json` (SHA-256 `655d2887e235262b6ff0ad83fdff0268bacd52f2077672d3ec09e75c36fc251b`), tampil di canvas (`hermes-analytics-canvas.png`), dan menjalankan 2 pertanyaan berbeda + 1 kasus no-data tanpa edit canvas (`hermes-analytics-runs.json`). **PARTIAL** karena run verifikasi dieksekusi lokal (`source: "verification"`, `telegram_delivery: null`), bukan dari update Telegram live. |
| P9 | **PASS** | 102 targeted tests (termasuk `HERMES_ANALYTICS_TEST_LIVE=1`) lulus dalam 0,77s; live security/timeout/fail-closed checks lulus; secret leak scan 0 temuan; no-mutation counts tetap 428/1.284/1.284; container `hermes-agent` telah di-restart sekali dengan persistensi workflow & MySQL node terverifikasi, semua 6 workflow existing SHA-256 identik, dan smoke test `uji-persisten` (`a1422764ea6d`) lulus (`post-restart-runtime.txt`). Catatan: kompatibel production (config eksternal, dependency pinned, least privilege), namun belum di-deploy/diuji di host production. |
| P10 | **PARTIAL** | `jobsheet6.sql` (Q1–Q9 adaptasi + Q8 Cartesian 2×3 + 3 latihan adaptasi), `mysql-reference.tsv`, `csv-staging.tsv` (COUNT=428, distinct=428), screenshot canvas & MySQL config masked, trace JSON, dan log QA tersedia. **PARTIAL** karena screenshot respons Telegram live masih pending, dokumen modul asli Q1–Q9 & 3 soal kuis dosen tidak ditemukan di checkout, dan persetujuan akademik atas dataset sintetis + MySQL belum diasumsikan. |

## 2. Arsitektur Workflow, Model, dan Target DB

- **Nama workflow:** `umkm-analytics-assistant` (`/opt/data/workflow-designs/umkm-analytics-assistant.json`, 9 node).
- **Node baru vs reuse:**
  - **Baru:** `mysql` node (`operation: schema` untuk `schema`, `operation: query` untuk `mysql` dengan AST validator terintegrasi), transform preset `analytics_result` (`filter`), trigger mode `telegram` (`telegram`) & handler slash command `/analytics` (`analytics_telegram.py`), serta orkestrasi runner (`analytics_workflow.py`).
  - **Reuse:** Visual Workflow canvas/designer (`dashboard/dist/index.js`, `dashboard/plugin_api.py`), node `agent` (`plan`, `analysis`), `condition` (`is_clear`, `has_data`), `output` (`reply`), `DesignStore`/`RunStore`, dan Telegram gateway polling existing (`@joyumkm_monitoring_bot`).
- **Model ID aktual (terverifikasi dari run trace):** `gpt-6-luna` via provider `openai-codex` (task `umkm_analytics`, fail-closed tanpa silent fallback).
- **Database target & scope data:** MySQL 8.4.3 schema terisolasi `joyumkm_p1_prod` (bukan `joyumkm-v2`), akses khusus akun read-only melalui TLS CA dan tepat 3 `SQL SECURITY DEFINER` view pada cohort sintetis `dataset_key='joyumkm-analytics'`, `dataset_version='v1'` (428 UMKM, 1.284 evaluasi Tiga Dimensi, 1.284 evaluasi Enam Faktor, periode tersedia `2025-11` s.d. `2026-10` WIB).

## 3. Pertanyaan Uji, SQL Tervalidasi, Hasil Aktual, & Status Telegram

Semua 3 run di bawah dijalankan melalui workflow persisten `umkm-analytics-assistant` tanpa mengubah canvas (`source: "verification"`, `telegram_delivery: null`, bukti lengkap pada `hermes-analytics-runs.json`):

1. **Pertanyaan 1 (Run `faf9a154cf8e`, correlation `verification-q7-9dade83a`):**
   - *Input:* `"5 kota dengan rata-rata skor enam faktor tertinggi pada tiga bulan terakhir yang tersedia"`
   - *SQL tervalidasi:*
     ```sql
     SELECT u.city AS kota, COUNT(*) AS observasi, ROUND(AVG(e.total_score), 2) AS rata_rata
     FROM analytics_six_factor_evaluations AS e
     JOIN analytics_umkms AS u ON e.umkm_id = u.id
     WHERE e.period_month BETWEEN ? AND ?
     GROUP BY u.city
     ORDER BY AVG(e.total_score) DESC, u.city ASC
     LIMIT 5
     ```
     *Params:* `["2026-08", "2026-10"]`
   - *Hasil aktual (23 ms, cocok 100% dengan Q7 `mysql-reference.tsv`):* Tangerang Selatan `18.76` (29), Bandung `18.65` (31), Malang `18.23` (30), Jakarta Timur `17.71` (35), Jakarta Selatan `17.67` (30) — skala Enam Faktor 6–30.
2. **Pertanyaan 2 (Run `b81c4fe351ff`, correlation `verification-q4-9d5eab66`):**
   - *Input:* `"Bandingkan rata-rata INS, ECT, OPS per sektor."`
   - *SQL tervalidasi:*
     ```sql
     SELECT u.sector AS sektor, COUNT(*) AS observasi,
            ROUND(AVG(e.ins_score), 2) AS rata_ins,
            ROUND(AVG(e.ect_score), 2) AS rata_ect,
            ROUND(AVG(e.ops_score), 2) AS rata_ops
     FROM analytics_evaluations AS e
     JOIN analytics_umkms AS u ON e.umkm_id = u.id
     GROUP BY u.sector
     ORDER BY u.sector ASC
     LIMIT 200
     ```
     *Params:* `[]`
   - *Hasil aktual (27 ms, cocok 100% dengan Q4 `mysql-reference.tsv`, total 1.284 observasi skala 0–100):* Fashion & Kriya (`225`: `50.19 / 49.81 / 50.22`), Grosir & Retail (`300`: `47.61 / 47.35 / 47.68`), Jasa (`255`: `48.69 / 48.85 / 48.74`), Makanan & Minuman (`360`: `50.42 / 50.31 / 50.46`), Pertanian (`144`: `53.76 / 53.08 / 53.91`).
3. **Pertanyaan 3 — Empty Result (Run `2160287aa1ae`, correlation `verification-no-data-a0051e65`):**
   - *Input:* `"Berapa rata-rata skor total Enam Faktor per kota untuk Januari 1901?"` -> `params: ["1901-01"]`, `row_count: 0`, `has_data: false`, node `has_data`/`analysis`/`reply` di-skip, jawaban deterministik: `"Tidak ada data sintetis yang cocok dengan pertanyaan dan periode tersebut."`
- **Bukti Telegram:** **Pending (PARTIAL).** Handler `/analytics` aktif di gateway yang berjalan, tetapi belum ada bukti pesan & balasan Telegram live dari operator `6293298092`. Catatan minor tampilan: `_MARKUP` di `analytics_telegram.py` mengganti karakter `_` menjadi `?` (mis. label `rata_rata` menjadi `rata?rata` di balasan Telegram); aman secara escaping namun dapat dirapikan di iterasi berikut.

## 4. Hasil Security/Regression/Persistence, Perubahan File, Command Ulang & Rollback

- **Targeted tests & security/regression (`targeted-tests.txt`, `post-restart-runtime.txt`):**
  - `102 passed in 0.77s` (`HERMES_ANALYTICS_TEST_LIVE=1`): AST policy denials (22 kasus), live MySQL read-only & SQLi param binding, row cap/truncation, workflow ambiguity/no-data/decimal/anti-hallucination/fail-closed model guard, Telegram authorization/dedupe/rate-limit/redaction, plugin registration, node-spec, triggers, schema, convert.
  - Direct live denial check: `DELETE`, `information_schema.tables`, `users`, `` `joyumkm-v2`.umkms ``, dan SQLi literal ditolak sebelum eksekusi; timeout bounds (`0`/`10000`) & MySQL error `3024`/`1205` teredaksi menjadi `mysql_timeout`.
  - Secret leak scan: 0 kebocoran pada workflow JSON dan 3 run trace.
  - No-mutation verification: `428 / 1284 / 1284` tetap utuh.
  - Restart persistence: `hermes-agent` di-restart satu kali (`04:33 UTC` / `11:33 WIB`); workflow `umkm-analytics-assistant` dan 6 workflow existing cocok dengan SHA-256 pra-restart; smoke test `uji-persisten` (`a1422764ea6d`) berstatus `done`.
- **Perubahan file:**
  - **Repo (`docs/jobsheets-6/evidence/`):** `analytics-views.sql`, `jobsheet6.sql`, `mysql-reference.tsv`, `csv-staging.tsv`, `p2-runtime.txt`, `existing-workflows-before.sha256`, `hermes-analytics-canvas.png`, `hermes-mysql-config.png`, `hermes-analytics-runs.json`, `post-restart-runtime.txt`, `targeted-tests.txt`, `FINAL_HANDOFF.md`. (Tidak ada perubahan source code Laravel.)
  - **Volume persisten Hermes (`/opt/data`):**
    - `/opt/data/plugins/visual-workflow/`: baru `visual_workflow_hermes/{mysql.py,analytics_workflow.py,analytics_telegram.py}`, `examples/umkm-analytics-assistant.designer.json`, `tests/{test_mysql.py,test_analytics_workflow.py,test_analytics_telegram.py}`; diubah `node-spec.json`, `dashboard/{dist/index.js,plugin_api.py}`, `visual_workflow_hermes/{__init__.py,schema.py,convert.py,runner.py,runtime.py,triggers.py}`, `tests/test_register.py`.
    - `/opt/data/workflow-designs/umkm-analytics-assistant.json`.
    - `/opt/data/joyumkm-analytics/{mysql.json,mysql-ca.pem,dependencies/}`.
    - Backup pra-perubahan: `/opt/data/backups/analytics-p7` dan `/opt/data/backups/visual-workflow-mysql-20261126T042714Z`.
- **Command menjalankan ulang verifikasi:**
  ```powershell
  # 1. Targeted plugin + live MySQL QA suite (102 tests)
  docker exec -u hermes hermes-agent sh -c 'cd /opt/data/plugins/visual-workflow && PYTHONPATH=/tmp/pytest-lib HERMES_ANALYTICS_TEST_LIVE=1 /opt/hermes/.venv/bin/python3 -m pytest -q tests/test_mysql.py tests/test_analytics_workflow.py tests/test_analytics_telegram.py tests/test_register.py tests/test_node_spec.py tests/test_triggers.py tests/test_schema.py tests/test_convert.py'

  # 2. Referensi SQL akademik (dengan login-path QA operator yang sah)
  Get-Content docs/jobsheets-6/evidence/jobsheet6.sql | mysql --batch --login-path=joyumkm-qa joyumkm_p1_prod
  ```
- **Rollback singkat:**
  1. Hapus workflow `/opt/data/workflow-designs/umkm-analytics-assistant.json` dan folder `/opt/data/joyumkm-analytics`.
  2. Pulihkan file plugin dari `/opt/data/backups/visual-workflow-mysql-20261126T042714Z` dan `/opt/data/backups/analytics-p7` (serta hapus 3 modul + 3 test baru), lalu restart `hermes-agent`.
  3. (Opsional DBA QA) `DROP VIEW` tiga view `analytics_*` dan revoke/drop user read-only QA.
  4. Hapus folder `docs/jobsheets-6/evidence/` bila artefak evidence tidak diperlukan.

## 5. Status Tujuh Prosedur Akademik, Perbedaan Dataset, & Blocker Tersisa

| # | Prosedur Jobsheet 6 | Status | Bukti |
|---|---|---|---|
| 1 | Siapkan dataset | **PASS (adaptasi)** | CSV sintetis P1 (`storage/app/analytics-dataset/umkm-analytics-dataset.csv`, 428 baris). |
| 2 | Impor CSV ke staging | **PASS** | Diimpor sebagai proyeksi ID/skor/periode ke tabel sementara schema QA `joyumkm_p1_prod` (tanpa impor ulang ke tabel bisnis). |
| 3 | Verifikasi COUNT baris | **PASS** | `csv-staging.tsv`: `csv_rows=428`, `distinct_umkm=428`, `first_month=2026-07`, `last_month=2026-10`. |
| 4 | SELECT, WHERE, ORDER BY | **PASS (adaptasi)** | Q1 & Q2 pada `jobsheet6.sql` dan `mysql-reference.tsv`. |
| 5 | JOIN nama, kota, skor terbaru + Cartesian | **PASS (adaptasi)** | Q3 (latest dengan tie-breaker `submitted_at DESC, id DESC`), Q8 (demonstrasi aman JOIN tanpa `ON` berukuran 2×3 = 6 baris). |
| 6 | AVG, GROUP BY, HAVING | **PASS (adaptasi)** | Q4 (INS/ECT/OPS per sektor) dan Q5 (`HAVING COUNT(*) >= 10`), cocok dengan output AI workflow. |
| 7 | Agregasi kota & bulan | **PASS (adaptasi)** | Q6 (kota × bulan WIB) dan Q7 (top-5 kota 3 bulan terakhir `2026-08`..`2026-10`), cocok dengan output AI workflow. |

- **Perbedaan sumber & catatan akademik:** Menggunakan **dataset sintetis P1** (pengganti CSV asli modul) dan **MySQL 8.4.3** (pengganti PostgreSQL); persetujuan dosen/akademik atas substitusi ini belum diasumsikan. Soal Q1–Q9 asli modul dan 3 kuis dosen tidak ditemukan di repo, sehingga Q1–Q9 dilabeli **adaptasi** dan 3 soal tambahan dilabeli **latihan**.
- **Blocker tersisa & tindakan minimal berikutnya untuk menutup P7/P8/P10 menjadi full PASS:**
  1. Tunggu kuota `openai-codex` (`gpt-6-luna`) reset (~14:17 WIB / setelah jendela 5 jam selesai) atau sediakan kuota `openai-codex` aktif (jangan switch model diam-diam tanpa persetujuan).
  2. Dari akun Telegram operator terotorisasi (`6293298092`), kirim 2 pesan DM ke `@joyumkm_monitoring_bot`:
     - `/analytics 5 kota dengan rata-rata skor enam faktor tertinggi pada tiga bulan terakhir yang tersedia`
     - `/analytics Bandingkan rata-rata INS, ECT, OPS per sektor.`
  3. Simpan screenshot balasan Telegram + trace run baru (`analytics_source: "telegram"`) ke `docs/jobsheets-6/evidence/` dan perbarui status P7/P8/P10.
  4. (Di luar scope P2–P10) Rotasi terkoordinasi atas credential OPENWA dan webhook Hermes yang pernah terekspos pada laporan P1 tetap diperlukan dan **belum** diklaim selesai.
