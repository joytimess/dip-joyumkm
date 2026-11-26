# PRD Fast Track — P2–P10: Conversational Analytics

**Project:** DIP-JoyUMKM × Hermes Agent Visual Workflow  
**Timebox:** 90 menit TOTAL wall-clock (termasuk audit, implementasi, pengujian, dan evidence).  
**Mode:** Langsung eksekusi seluruh P2–P10 dalam satu sesi, maksimal 3 subagent. Tidak perlu approval antar-phase untuk perubahan development yang termasuk scope. Jangan deploy production tanpa persetujuan eksplisit.

## 1. Objective dan baseline

Bangun satu workflow reusable `umkm-analytics-assistant` pada canvas Hermes yang menerima pertanyaan Telegram, memahami intent menggunakan GPT-6 Luna, menerjemahkan intent ke SQL MySQL, memvalidasi dan mengeksekusi SQL secara deterministik, memfilter hasil, menganalisis menggunakan GPT-6 Luna, dan mengirim jawaban ke chat asal.

- Laravel: `C:\laragon\www\joyumkm`, repo `joytimess/dip-joyumkm`.
- MySQL existing melalui Laragon; database aplikasi development `joyumkm-v2`.
- Hermes existing: Docker container `hermes-agent`, volume persisten `/opt/data`, plugin Visual Workflow. Dashboard terakhir digunakan `http://127.0.0.1:9119`; verifikasi port aktual, jangan mengubahnya.
- P1 ditutup secara fungsional (verdict PARTIAL diterima untuk melanjutkan): 428 UMKM sintetis, 1.284 evaluasi Tiga Dimensi, 1.284 evaluasi Enam Faktor, 20.544 jawaban, 12 bulan. Dataset terakhir terverifikasi berada di database terisolasi `joyumkm_p1_prod`, bukan `joyumkm-v2`.
- P1 command baru: `analytics-dataset:seed`, mendukung secret via hidden prompt / password file / stdin. Jangan mengulang P1. Prioritaskan memakai cohort existing yang telah tervalidasi. Bila perlu database dev utama, gunakan command existing hanya dengan akses dan secret yang sudah tersedia serta target yang benar; jangan membuat akun atau mereset password secara diam-diam. Jangan mengarahkan Laravel dev secara permanen ke database QA.
- Dua credential OPENWA dan Hermes webhook pernah terekspos menurut laporan P1. Jangan membaca/mencetak nilai secret ke transcript. Rotasi terkoordinasi di luar scope; jangan mengklaim secret tersebut sudah dirotasi.

## 2. Timebox eksekusi — keras, bukan estimasi jaminan PASS

| Menit kumulatif | Phase | Output wajib |
|---|---|---|
| 00–05 | P2 | Koneksi MySQL read-only + kontrak plugin aktual |
| 05–20 | P3 | MySQL node berfungsi pada canvas dan executor |
| 20–30 | P4 | AI node menggunakan model pilihan yang terverifikasi |
| 30–45 | P5 | Intent → SQL tervalidasi → eksekusi aman |
| 45–50 | P6 | Filter hasil dan AI analysis |
| 50–60 | P7 | Telegram input/output terotorisasi |
| 60–70 | P8 | Satu workflow end-to-end persisten |
| 70–80 | P9 | Targeted QA, keamanan, restart, regression |
| 80–90 | P10 | SQL dan evidence + handoff |

Timebox dihitung sejak mulai, bukan per-agent. Reuse implementasi existing; alokasikan sisa waktu phase yang selesai lebih cepat ke critical path. Maksimal 3 subagent paralel dengan batas file yang jelas: A database/node/validator, B AI/Telegram, C QA/evidence/UI jika file tidak bertabrakan. Lead bertanggung jawab pada integrasi. Tidak perlu audit repo menyeluruh, full test suite, redesign canvas, atau refactor engine. Jika blocker independen muncul, catat dan kerjakan jalur lain. Pada menit 80 prioritaskan evidence dari hasil yang benar-benar ada; pada menit 90 berhenti dan serahkan status. Jangan bypass pengamanan atau mengklaim PASS palsu demi waktu.

## 3. Workflow final

```text
Telegram Trigger (pertanyaan, user/chat/update ID)
  → Authorization / scope
  → GPT-6 Luna: Question Understanding
  → Database Schema Context (allowlist + business rules)
  → GPT-6 Luna: Text-to-SQL
  → SQL Validator (AST + scope + parameter binding)
  → MySQL: Execute Query
  → Filter & Transform
  → GPT-6 Luna: AI Data Analysis
  → Telegram Send Message (chat asal)
```

Authorization harus dilakukan sebelum request AI. Schema, validator, dan branching dapat memakai komponen existing atau menjadi bagian terintegrasi dari node selama tetap terlihat/dapat dikonfigurasi melalui workflow dan hasil validasi dapat diaudit. Jangan membuat banyak node duplikat jika kemampuan sudah ada. MySQL node wajib benar-benar menjalankan query dan mengembalikan JSON; tidak boleh berupa mockup atau AI agent yang menjalankan shell secara bebas.

## 4. Implementation tasks dan gate minimal

### P2 — Connectivity (5 menit)
- Periksa secara read-only `docker ps`, mount, plugin registry/node-spec, executor, lokasi konfigurasi dan provider model. Jangan dump environment atau credential. Reuse kontrak node hasil Jobsheet sebelumnya.
- Tes koneksi dari container ke host MySQL (`host.docker.internal` jika sesuai runtime), gunakan port aktual; jangan menambah container database atau mengubah port Hermes. Untuk deployment production gunakan host/credential yang dikonfigurasi, bukan alamat host dev hardcoded.
- Gunakan credential khusus read-only. Untuk target cepat dan aman, berikan SELECT hanya pada views analitik yang secara tetap memfilter cohort sintetis terotorisasi (atau database analitik yang benar-benar terisolasi hanya berisi data sintetis, dengan kolom terbatas). Jangan memberi SELECT ke tabel sumber multitenant, `users`, contact fields, secrets, atau data pengguna lain. Jangan memakai root dari workflow. Pembuatan grant/view memerlukan akses DBA yang sah; jika belum tersedia, catat blocker, jangan melemahkan pembatasan akses.
- Credential tidak disimpan plaintext dalam workflow JSON, log, prompt, atau repo. Reuse credential store atau secret reference existing. Test connection harus mengembalikan status teredaksi.

**Gate:** koneksi dari Hermes sukses, scope cohort dapat dibuktikan, dan akses tulis/akses di luar scope ditolak. Jika gagal, jangan menjalankan SQL AI pada DB yang tidak aman.

### P3 — Native MySQL Node (15 menit)
- Extend node registry/spec, konfigurasi UI, serializer dan executor existing hanya pada titik yang diperlukan. Node dapat dipilih, disimpan, diedit, dan dieksekusi dari Visual Workflow.
- Operasi minimum: Execute Query (input SQL + bound params) dan schema context dari daftar view/kolom yang diizinkan. Reuse driver MySQL yang tersedia; jika perlu dependency, pin versi dan pastikan persist setelah restart/recreate sesuai mekanisme plugin existing. Jangan install dependency berulang setiap run.
- Konfigurasi: credential reference, SQL/expression input, params, timeout, max rows. Output JSON: `rows`, `row_count`, `truncated`, `duration_ms`, status/error code. Serialisasi decimal tanpa kehilangan presisi, tanggal ISO, null tetap null.
- Connection lifecycle wajib ditutup saat success/error. Validator pada P5 juga wajib dipanggil di boundary executor untuk input dinamis, bukan hanya bergantung pada keberadaan node validator di canvas.

**Gate:** node nyata tampil di canvas, query SELECT terotorisasi mengembalikan JSON aktual, konfigurasi tersimpan. Tidak perlu membuat dashboard credential baru jika existing store cukup.

### P4 — AI integration & understanding (10 menit)
- Reuse provider dan AI/Agent node existing sejauh kompatibel. Gunakan model yang dipilih user: GPT-6 Luna. Verifikasi identifier aktual dari provider/runtime (label saja bukan bukti model). Jangan mengganti model atau provider tanpa persetujuan; jika unavailable, catat blocker dan lanjutkan pengujian non-AI secara terpisah.
- Tambahkan mapping input dinamis dan structured output. Intent minimal: `metric`, `evaluation_type`, `filters`, `period`, `group_by`, `sort`, `limit`, `clarification_needed`. Pertanyaan ambigu meminta klarifikasi, bukan menebak diam-diam. Maksimal 3 AI calls pada happy path; jika diperlukan untuk latency, gabungkan understanding + SQL generation dalam satu call dengan output intent terpisah.
- Context schema hanya mencakup objek database yang diizinkan dan aturan skor, tidak mengirim credential maupun informasi kontak. Batasi token, timeout dan retry; tanpa fallback model diam-diam.

**Gate:** pertanyaan berbeda menghasilkan intent berbeda; model ID tercatat (tanpa secret).

### P5 — Text-to-SQL & validator (15 menit)
- AI menghasilkan structured `{sql, params, intent}` menggunakan MySQL 8 syntax. Nilai input user menjadi parameter, bukan string interpolation.
- Gunakan parser AST MySQL existing atau dependency terpelihara; jangan membuat validator regex-only. Fail closed jika parse/validasi gagal. Satu statement SELECT saja; CTE/window function hanya jika seluruh AST dan referensi scope dapat divalidasi. Dukung minimal SELECT, WHERE, ORDER BY, JOIN, AVG, COUNT, GROUP BY, HAVING untuk Jobsheet 6.
- Tolak DML/DDL, multi-statement, SELECT INTO/OUTFILE, locking read, metadata/schema sensitif, user variables, functions berbahaya (SLEEP, BENCHMARK, LOAD_FILE, dll.), external access, dan akses objek/kolom di luar allowlist. Gunakan allowlist fungsi yang diperlukan. Periksa subquery/CTE/alias juga. Jangan memakai hasil AI untuk menentukan otorisasi.
- Database grants + views/cohort isolation menjadi boundary otorisasi utama; AST adalah defense tambahan. Tidak cukup menambahkan `WHERE user_id` lewat prompt. Tidak ada akses lintas tenant pada SQL langsung, nested query atau join.
- Batas query: timeout server-side jika didukung MySQL (mis. MAX_EXECUTION_TIME 5000 ms), client/socket timeout, max 200 rows output dengan indikator truncation; batasi kompleksitas join/subquery dan query Cartesian pada workflow AI. Gunakan EXPLAIN jika diperlukan untuk query berisiko; jangan menjalankan query berat tanpa guard. Satu koreksi AI maksimal untuk error sintaks/kolom yang aman, bukan untuk bypass penolakan keamanan.
- Schema contract: `umkms` → `evaluations` (INS/ECT/OPS, average 0–100, submitted_at) dan `six_factor_evaluations` (6 faktor integer 1–5, total 6–30, finalized_at, period_month WIB). Gunakan nama view aktual dalam query. Filter final untuk penilaian final; latest dengan timestamp + ID tie-breaker. Hindari join dua histori mentah yang menggandakan observasi. Bedakan jumlah UMKM dan jumlah evaluasi; jangan mencampur skala dua jenis skor.

**Gate:** satu query JOIN/agregasi cocok dengan reference SQL; query write/metadata/cross-scope dan injection ditolak sebelum data keluar.

### P6 — Filtering & Analysis (5 menit)
- Reuse Filter/Edit Fields/Transform existing; mapping data JSON, null, decimal, periode, jumlah observasi, truncation. Agregasi/ranking dilakukan MySQL, bukan estimasi AI dari hasil terpotong.
- GPT-6 Luna menganalisis hasil query aktual. Prompt harus memisahkan fakta, interpretasi dan rekomendasi, tidak mengarang angka. Data hasil DB diperlakukan sebagai data tidak tepercaya, bukan instruksi. Jika kosong atau error, keluarkan jawaban deterministik tanpa klaim statistik.
- Respons Indonesia: jawaban inti, angka/tabel ringkas, periode, jenis evaluasi, jumlah observasi dan penanda data sintetis. Jangan mengirim SQL internal, credential, atau data kontak ke Telegram kecuali SQL aman diminta operator untuk evidence.

**Gate:** hasil final sesuai nilai SQL, dan empty result ditangani.

### P7 — Telegram (10 menit)
- Reuse bot, trigger/router dan output existing. Satu jalur update consumer; jangan menjalankan polling kedua atau mengganti webhook bot existing. Gunakan prefix/command `/analytics` bila diperlukan untuk menghindari konflik dengan workflow lain; isi pertanyaan tetap natural language dan dinamis. Scope ini single authorized operator/cohort, bukan rollout multitenant publik.
- Allowlist user ID dan chat ID dari konfigurasi tepercaya; gunakan chat privat yang telah diotorisasi. Jangan percaya chat ID dari prompt. Mapping cohort fixed server-side. Dedupe update ID, correlation ID, rate limit sederhana; response ke chat asal yang sudah terotorisasi.
- Gunakan Telegram send node existing; escape formatting dan batasi panjang pesan. Tangani unauthorized, ambiguity, no data, timeout, dan delivery failure. Jangan broadcast, jangan mengirim ke chat lain, jangan log token. Bila approval output existing wajib, pertahankan dan gunakan approval workflow yang sah, bukan bypass global.

**Gate:** satu pesan uji operator yang diotorisasi diterima di chat asal; unauthorized update tidak memanggil AI/DB.

### P8 — End-to-end Canvas (10 menit)
- Buat `umkm-analytics-assistant`, wiring semua tahap dengan input/output mapping nyata. Schema context dan validator harus tercatat dalam execution trace. Simpan ke volume persisten; jangan ubah workflow sebelumnya.
- Jalankan minimal dua pertanyaan berbeda pada workflow yang sama tanpa edit node, misalnya: (1) “5 kota dengan rata-rata skor enam faktor tertinggi pada tiga bulan terakhir yang tersedia”; (2) “Bandingkan rata-rata INS, ECT, OPS per sektor.” Gunakan periode dataset yang benar; jika user bertanya bulan sekarang dan data tidak tersedia, nyatakan kosong atau minta klarifikasi.
- Simpan bukti execution trace, SQL tervalidasi, rows/summary dan output Telegram. Jika AI atau Telegram belum tersedia, hasil manual/test fixture hanya bukti parsial, bukan E2E PASS.

**Gate:** kedua intent menghasilkan query dan respons aktual berbeda dari satu workflow; canvas dan execution history tersedia.

### P9 — QA & hardening (10 menit)
- Targeted smoke tests saja, parallel bila aman: correct aggregate/reference SQL; no-result; denied write + metadata + cross-tenant + prompt injection; timeout; unauthorized/duplicate Telegram update. Pakai DB terisolasi untuk destructive-negative test; jangan menjalankan DML sungguhan pada DB aplikasi.
- Verifikasi credential/config tidak tercetak dan tidak ada mutasi oleh SQL analitik. Jangan memasukkan data PII asli ke prompt atau evidence. Audit trail minimum: correlation ID, model ID, query/status, duration, row count dan scope; redact sensitive params/results.
- Backup plugin/state sebelum perubahan, gunakan patch persisten dan rollback file terarah. Restart container existing satu kali jika aman dan tidak mengganggu job aktif; verifikasi workflow baru tersimpan, MySQL node masih tersedia, dan lakukan smoke test satu workflow existing yang tidak menimbulkan pengiriman eksternal. Jika restart tidak aman, laporkan persistence runtime PARTIAL.
- Production-compatible: no local-only guard, no dev-only host/secret hardcode, dependency pinned, least privilege, documented install/restart/rollback. Uji konfigurasi production-like di lingkungan terisolasi jika tersedia dalam timebox. Jangan mengklaim production runtime PASS tanpa deploy/test aktual. Jangan deploy production, ubah route production, atau mengubah queue/webhook global.

**Gate:** security tests inti dan smoke regression PASS; status restart dan production-like ditulis sesuai bukti.

### P10 — Academic validation & evidence (10 menit)
- Ikuti tujuh prosedur asli Jobsheet 6. Cari Q1–Q9 di bahan modul yang tersedia; jika tidak ditemukan, buat query ekuivalen berlabel adaptasi, jangan mengklaim Q1–Q9 asli. Tiga query kuis dosen hanya dapat diselesaikan jika diberikan; bila tidak, sediakan tiga latihan berlabel latihan.
- Buat `jobsheet6.sql` **hanya sebagai artefak akademik** (penamaan eksplisit modul), berisi komentar tujuan setiap query, SELECT/WHERE/ORDER BY, JOIN nama UMKM/kota/skor terbaru, AVG/GROUP BY/HAVING, rata-rata kota/bulan. Demonstrasikan JOIN tanpa ON secara aman pada dua himpunan kecil terbatasi, jelaskan Cartesian product. Jalankan lewat MySQL manual atau workflow yang aman, bukan AI tanpa bukti SQL.
- Reuse CSV sintetis 428 baris dari P1 (`storage/app/analytics-dataset/umkm-analytics-dataset.csv` jika masih tersedia). Impor hanya ke staging table/database QA, verifikasi COUNT=428; jangan impor ulang ke tabel bisnis. Nyatakan dataset sintetis pengganti CSV asli dan MySQL pengganti PostgreSQL; persetujuan akademik belum diasumsikan.
- Evidence minimal: screenshot canvas, konfigurasi MySQL node dengan credential masked, execution trace, hasil query SQL, COUNT CSV, dan respons Telegram. Gunakan tooling/browser existing; jika screenshot tidak tersedia, simpan evidence log teredaksi dan tandai screenshot pending. Jangan membuat bukti palsu.

**Gate:** artefak SQL dan evidence aktual tersedia, gap prosedur akademik dicatat.

## 5. Batasan keras

- Tidak memakai n8n, PostgreSQL, atau container baru kecuali user menyetujui perubahan scope.
- Tidak mengubah workflow, port, volume, atau konfigurasi production existing di luar kebutuhan yang telah disetujui. Tidak menghapus data, `migrate:fresh`, `docker prune`, atau `docker compose down -v`.
- Tidak mengulang P1, tidak memperbaiki baseline test unrelated, tidak menjalankan full suite SQLite. Tidak melakukan audit ulang setelah fakta cukup.
- Source code, node, credential, workflow dan data tidak menggunakan penamaan akademik. `jobsheet6.sql` dan folder evidence akademik merupakan pengecualian.
- Tidak commit/push/deploy production secara otomatis. Jangan print credential/token, jangan meminta user menempelkan secret ke chat.
- Jangan menambahkan agent shell/SQL execution bebas sebagai jalan pintas. Semua AI-generated SQL melewati validasi dan akses DB least privilege.
- Tidak perlu membuat PRD baru, proposal tambahan, atau meminta konfirmasi antarphase untuk pekerjaan development yang sudah disetujui. Jika akses rahasia/otorisasi/DBA tidak ada, fail closed dan lanjutkan pekerjaan independen.

## 6. Final handoff — satu laporan ringkas

Tulis `FINAL_HANDOFF.md` pada folder evidence yang dipilih, dengan:

1. Durasi wall-clock aktual dan status P2–P10 (PASS/PARTIAL/FAIL/BLOCKED) berdasarkan gate masing-masing; jangan menganggap mock/test fixture sebagai live PASS.
2. Nama workflow, node yang baru vs reuse, model ID aktual, DB target (tanpa secret), dan scope data.
3. Dua pertanyaan uji, query tervalidasi, hasil aktual dan bukti Telegram; tunjukkan jika hanya sebagian yang dapat dijalankan.
4. Hasil targeted security/regression/persistence tests; perubahan file, lokasi evidence, command menjalankan ulang, rollback singkat.
5. Status tujuh prosedur akademik, perbedaan sumber dataset, serta blocker yang masih ada.

**Definition of Done:** satu workflow nyata di Hermes menerima pertanyaan Telegram yang terotorisasi, menggunakan GPT-6 Luna untuk memahami/menyusun SQL dan menganalisis hasil, mengeksekusi SQL read-only yang tervalidasi pada scope data yang sah, lalu mengirim jawaban faktual ke chat asal. Dua pertanyaan berbeda berhasil tanpa perubahan canvas, persistensi dan keamanan teruji, dan evidence akademik tersedia. Bila satu gate belum terpenuhi pada menit 90, laporkan PARTIAL/BLOCKED beserta tindakan minimal berikutnya; jangan memperpanjang pengerjaan tanpa arahan user.

**Mulai sekarang dari P2. Jalankan P2–P10 sebagai satu pekerjaan. Jangan berhenti untuk membuat rencana tambahan.**
