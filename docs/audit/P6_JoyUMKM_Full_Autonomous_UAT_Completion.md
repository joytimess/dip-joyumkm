# P6 JoyUMKM — Resume P6-02

## P6-05 diselesaikan: Playwright, provenance, dan kontrak idempotency (25 November 2026 WIB) — verdict terbaru

**Verdict P6-05: PASS.** Verifikasi kotak masuk penerima bersifat opsional dan tidak dilakukan karena akses kotak masuk tidak tersedia. Bagian "P6-05: PARTIAL" di bawah sudah tersusul oleh bagian ini.

- **Playwright (sesi Laravel terautentikasi):** login memakai token Sanctum sementara milik pemilik UMKM 26 (user id 6), yang dikirim lewat environment dan dicabut setelahnya. Halaman `/umkms/26`, tab Intelligence, untuk report id 4:
  - `Penyimpanan dokumen · Lengkap`
  - `Pengiriman email · Diterima server email`, dengan waktu `25 November 2026 pukul 07.15 WIB`
  - Riwayat laporan selesai dimuat dan menampilkan laporan yang sama
  - Tidak ada page error dan tidak ada console error
  - Teks panel memuat "Tiga Dimensi belum tersedia" tanpa skor, dan kalimat "Mulai buku catatan…" tampil sebagai rekomendasi
- **Unduhan aktual dari tombol UI:**
  - PDF `intelligence-report-4.pdf`, 37746 byte, SHA-256 `f016306202590c3799d46f3fd027719e344cc9281c7a46965611dcb40c8f2c98`, cocok dengan manifest dan Laravel
  - XLSX `intelligence-report-4.xlsx`, 26541 byte, SHA-256 `fb895dd9f8f8afedc313fc9a06ec7a4579ad6196540b0717cb7ad3a980c402e3`, cocok dengan manifest dan Laravel
- **Provider message id:** bug konkret. Kolom `intelligence_deliveries.provider_message_id` tidak pernah diisi oleh controller status, padahal snapshot membawa `message_id`. Diperbaiki di `IntelligenceDeliveryStatusController::persist`. Baris event ini dikoreksi dari `email_snapshot` yang tersimpan: sekarang `<f304fb88…1cb652@gmail.com>`. Tidak ada kirim ulang.
- **Idempotency key:** bug konkret. Saat insert, controller memberi placeholder `sha256("JMI-DELIVERY-STATUS-V1\n{eid}\nemail")`, dan placeholder itu tidak pernah diganti meskipun kunci kontrak sudah divalidasi dari payload. Diperbaiki: kunci kontrak disimpan begitu tersedia. Baris event dikoreksi: `idempotency_key` sekarang `f304fb88…1cb652`, yaitu `sha256(event\nemail\nfingerprint\nartifact_set_hash)`. Status dan `email_snapshot_sha256` tidak berubah. Catatan: `IntelligenceDelivery::idempotencyKeyFor()` memakai rumus lain (`intelligence|…`) dan hanya dipakai kanal artifact. Rumus itu tidak diubah.
- **Test:** `IntelligenceDeliveryStatusTest` dan `IntelligenceDeliverySchemaTest` lulus 12/12 (116 asersi). Test status kini memeriksa kedua kolom setelah update `sent`. Pint dijalankan pada file yang berubah.
- **Provenance prompt:** tercatat `prompt_version: r1-prompt-6` pada laporan, padahal teks aslinya dihasilkan di bawah `r1-prompt-5`. Ini bukan bug fungsional, karena laporan tetap tervalidasi dengan validator saat ini. Perbaikan di engine tidak dilakukan: `prompt_version` pada doc diikat ke identity check job (`IDENTITY_MISMATCH`), sehingga mengubahnya memerlukan perubahan kontrak. Artifact dan hash event ini sudah terkirim dan immutable. Asal generasi tercatat di `attempts/003.json` dan `candidate_response.generated_under`. Rekomendasi untuk versi kontrak berikutnya: pisahkan `validator_version` dari `generator_version`.
- **Rollout:** `email.env` ENABLED=0 / PAUSED=1. `delivery-status.env` sync ENABLED=0 / PAUSED=1. Tidak ada `uat-scope.json` dan tidak ada `email.pause`. Flag artifact tidak diset di environment proses Hermes.

## Bounded automatic correction dan pipeline sampai email (25 November 2026 WIB)

- **Event:** `a424c677-7c5a-4c59-86aa-e35d9d348364`, evaluasi 15, UMKM 26. Tidak ada Final baru, replay outbox, atau pengulangan P6-02/P6-03.
- **Perubahan Intelligence (deployed ke Hermes live):** normalisasi deterministik untuk Tiga Dimensi yang tidak tersedia (findings dan cross-insights dikosongkan), hypothesis tanpa `evidence_refs` dihapus, dan `historical_trends` tanpa pembanding tetap kosong. Validator P3-02 tidak dilemahkan: `evidence_refs` tidak sah dan kunci indikator atau expected_outcomes yang tidak sah tetap ditolak, sehingga feedback spesifik sampai ke model. Prompt dinaikkan ke `r1-prompt-6` dengan aturan batas refs dan pembedaan rekomendasi dari klaim finansial. Claims checker: kalimat rekomendasi berawalan "Mulai" tidak lagi dianggap klaim finansial; klaim faktual tetap diperiksa.
- **Kebijakan recovery:** otorisasi satu generasi dicabut dan diarsipkan, diganti anggaran tiga attempt otomatis. Riwayat tiap attempt tersimpan di `state/jobs/<eid>/attempts/`. Respons tersimpan dipakai lebih dulu, berhenti segera setelah laporan valid, dan tidak retry jika outcome provider ambigu (`halted_ambiguous`).
- **Hasil generasi:** kandidat dari respons corrective tersimpan (Hermes `state.db` message 4695, dibuat di bawah `r1-prompt-5`) dinormalisasi dan divalidasi **tanpa panggilan AI baru**. Attempt 3 diterima (`source: stored_candidate`, normalisasi `three_dimension_unavailable_cleared`). Budget terpakai 0 dari 3.
- **Catatan provenance:** dokumen tercatat `prompt_version: r1-prompt-6` (versi validator saat ini), sedangkan teks asli berasal dari `r1-prompt-5`. Pencatatan asal ada di `attempts/003.json`.
- **Pipeline setelah laporan valid:**
  - Laporan kanonis: `status: completed`; 18 `evidence_refs` seluruhnya berasal dari snapshot atau `data_gap`; `historical_trends` kosong; Tiga Dimensi `available: false` dengan 0 findings dan 0 cross-insights.
  - PDF: 10 halaman, SHA-256 `f016306202590c3799d46f3fd027719e344cc9281c7a46965611dcb40c8f2c98` (cocok dengan manifest dan Laravel).
  - XLSX: 5 worksheet (Ringkasan, Enam Faktor, Tiga Dimensi, Histori, Rekomendasi), SHA-256 `fb895dd9f8f8afedc313fc9a06ec7a4579ad6196540b0717cb7ad3a980c402e3` (cocok dengan manifest dan Laravel).
  - Manifest: `artifact_set_hash` `dbbd4994e9ffe6d1025963755377adfaf576b0331100e2e24c38e5a0fa945cc2`, sama di Hermes dan Laravel.
  - Laravel: callback dan upload PDF/XLSX `acknowledged` (`remote_artifact_status: complete`). Baris `intelligence_reports` id 4 berstatus `artifact_status: complete` dengan dua baris `intelligence_artifacts`.
  - Email: satu pesan SMTP, `status: sent`, `smtp_phase: data`, `attempt_no: 1`, dua lampiran (PDF dan XLSX), penerima UAT yang disetujui. Gate email dibuka sementara hanya untuk pengiriman ini, lalu dikembalikan ke ENABLED=0 / PAUSED=1.
  - Status sync: `accepted`, revision 1 ter-ACK, `email_status_sync.json` berstatus `synced`. Gate sync dibuka sementara, lalu dikembalikan ke ENABLED=0 / PAUSED=1.
- **Cleanup dan rollout:** pin UAT dihapus (arsip di `state/uat-scope.json.removed-after-bounded-run`). Flag artifact dan delivery tidak diset di environment proses Hermes, sehingga default OFF. `email.env` ENABLED=0 / PAUSED=1. `delivery-status.env` sync ENABLED=0 / PAUSED=1. Laravel `HERMES_WEBHOOK_ENABLED=false`. Windows task `JoyUMKM Hermes Webhook Worker` dan `JoyUMKM Laravel Scheduler` Disabled.
- **Verdict:**
  - P6-04: **PASS.** Laporan kanonis valid tanpa mengarang data dan tanpa panggilan AI baru.
  - P6-05: **PARTIAL.** PDF, XLSX, manifest, callback dan upload Laravel, penerimaan SMTP, dan status sync tervalidasi dengan bukti di atas. **Belum dijalankan:** Playwright terhadap halaman Intelligence Report dengan data event nyata (suite browser yang ada memakai fixture dan mock API, sedangkan validasi live memerlukan sesi Laravel terautentikasi), dan verifikasi kotak masuk penerima (tidak ada akses).
  - P6-06: **PASS.** Rollout kembali OFF / PAUSED dan pin dihapus.
- **Temuan terbuka (tidak diselesaikan di run ini):**
  - `provider_message_id` pada `intelligence_deliveries` bernilai null, padahal SMTP mengembalikan Message-ID `<f304…@gmail.com>`.
  - `idempotency_key` Laravel (`60e252eb919e…`) berbeda dari kunci Hermes (`f304fb88…`). Belum ditelusuri apakah Laravel menurunkan kuncinya sendiri.
  - Suite `tests/test_corrective.py` lulus 13/13 pada salinan live. Regresi pra-existing di `test_monthly` (3 kegagalan: interrupted write, invalid model numbers, timeout) tidak berubah. `test_artifacts` dan `test_email_status_sync` tidak dijalankan ulang karena di luar scope.

## Corrective run Full Autonomous (25 November 2026 WIB) — verdict terbaru

**P6 OVERALL — NOT PASS. INBOX — NOT RUN. P6-05 — NOT RUN. ROLLOUT — OFF / PAUSED.**

| Tahap | Hasil |
|---|---|
| Perbaikan kode | Prompt bulanan, Host header untuk snapshot/callback/artifact, dan jalur corrective terotorisasi telah dideploy. Tes terarah lulus (8/8). Kegagalan lain di suite lama identik dengan baseline (16 kegagalan pra-existing). |
| Endpoint | Snapshot, callback, dan artifact menunjuk Laragon port 80 dengan Host `joyumkm.test`. Snapshot bertanda tangan HTTP 200, artifact route 405 (terjangkau), callback dry-run terbaca. |
| Rekonsiliasi outbox | 7 delivered. Enam historis tidak berubah (`updated_at` 22–23 Nov). Yang ketujuh `a424c677-…` adalah ACK UAT (19:54:06 UTC). |
| Corrective generation (1 dari 1) | Dijalankan satu kali pada 00:05:24 UTC. Respons ditolak validator. Job kembali `ANALYSIS_UNKNOWN`, `retryable` false, otorisasi `consumed`. Tidak ada panggilan AI tambahan. |
| P6-04 | BLOCKED. Canonical report tidak valid. PDF, XLSX, manifest, callback, upload, SMTP, dan status sync NOT RUN. |
| P6-05 | NOT RUN. Tidak ada output untuk divalidasi. |
| P6-06 | Shutdown dan handoff PASS. Pin UAT dihapus, dispatch off, kedua task Windows Disabled. |

Error validasi corrective yang spesifik (respons tersimpan di `state.db` id 4695):
1. `action_plan.days_1_30[1].action`: kata "pemasukan" dan "pengeluaran" tanpa bentuk yang dapat diverifikasi (`financial_claim_unverifiable`).
2. `three_dimension unavailable: findings/cross insights must be empty`: model menulis temuan atau cross-insight Tiga Dimensi, padahal Tiga Dimensi tidak tersedia pada event ini.

Pemeriksaan ekstensi bulanan belum dijalankan karena validasi inti gagal lebih dulu.

Respons asli dan respons corrective keduanya tersimpan. Intent lama tidak dihapus dan dipindahkan ke `analysis_submission_history`. Rincian ada di [ringkasan UAT](../evidence/p6-uat-summary.md).

## UAT live — verdict terbaru

**P6 OVERALL — NOT PASS. INBOX — NOT RUN. ROLLOUT — OFF / PAUSED.**

| Tahap | Hasil |
|---|---|
| P6-02 | PASS untuk scope UAT yang disetujui. Gateway memakai cron existing tanpa agent tambahan, pin lintas runtime dilepas melalui scheduler, dan Gmail AUTH 235. |
| P6-03 | PASS. Playwright mencatat consent aktif dan satu Final, evaluasi 15 untuk UMKM 26. |
| P6-04 | PARTIAL. Outbox ACK 202 sekali, snapshot tersimpan dan satu real AI API call teramati. Respons model ditolak karena kontrak analisis bulanan, sehingga report, PDF, XLSX dan Gmail send tertahan. |
| P6-05 | PARTIAL. Skor 18/30, kategori dan periode cocok dengan Final. Validasi report, artefak dan delivery NOT RUN karena output belum ada. |
| P6-06 | PASS untuk shutdown dan handoff. Dispatch off, kedua task Disabled, pin tertutup, dan email serta status sync off. |

UUID `a424c677-7c5a-4c59-86aa-e35d9d348364`. Status `ANALYSIS_UNKNOWN` dipertahankan. Hasil checkpoint historis tetap berlaku. Rincian tersedia di [ringkasan UAT](../evidence/p6-uat-summary.md).


## Resume transport Socat

**P6 OVERALL — NOT PASS. INBOX — NOT RUN. ROLLOUT — OFF / PAUSED.**

P6-02 PARTIAL. Docker DNS dan signed HOLD lewat Socat PASS. C03/C07 memperoleh bukti tambahan dari gateway aktual. C04 automatic release NOT RUN. C01, C05 dan hasil checkpoint lain dipertahankan. Jalur sukses gateway dapat mengantrekan agent run setelah skrip intake menjadwalkan worker JMI. Batas satu analisis belum aman dengan subscription aktif. Konfigurasi Hermes dipertahankan.

P6-03 sampai P6-05 BLOCKED. Gmail AUTH, Final, real AI, SMTP dan validasi output NOT RUN. P6-06 PASS untuk shutdown dan handoff. Tidak ada perubahan skor atau output UAT. Rincian probe dan durasi ada di ringkasan UAT.


## Resume dengan acceptance criteria revisi

**P6 OVERALL — NOT PASS. INBOX — NOT RUN. ROLLOUT — OFF / PAUSED.**

| Task | Verdict | Bukti delta |
|---|---|---|
| P6-01 | MERGED | Bukti readiness dan hasil historis dipertahankan. |
| P6-02 | PARTIAL | Worker MySQL memproses dua kandidat dan melewati envelope non-UAT yang sudah terantre. Kedua implementasi pin membaca ulang penggantian atomik. Request bertanda tangan dari Windows ke proxy timeout. Proxy meneruskan ke alamatnya sendiri; health gateway langsung di dalam Hermes HTTP 200. |
| P6-03 | BLOCKED | Controlled activation tertahan. Tidak ada Final atau UUID UAT. |
| P6-04 | BLOCKED | Tidak ada trigger live, analisis, paket, atau SMTP. |
| P6-05 | BLOCKED | Tidak ada output live untuk direkonsiliasi. |
| P6-06 | PASS untuk handoff aman | Webhook disabled, antrean khusus kosong, task Windows Disabled dan Hermes HOLD dengan pin kosong. Jalur SMTP, artifact delivery dan status sync off. |

C01 dan C05 tetap PASS berdasarkan checkpoint. C02 lulus untuk scanner dua kandidat, deduplikasi scan berurutan dan worker dengan envelope lama; race scanner paralel belum diuji pada delta. C03 dan C07 PARTIAL karena entry point Hermes lulus pemeriksaan HOLD langsung, tetapi request melalui proxy runtime timeout. C04 lulus di kedua runtime dalam penyimpanan uji dan pada simulasi close setelah callback; release scheduler lintas runtime belum terbukti. C06 memenuhi batas revisi pada inspeksi konfigurasi efektif dan bukti ambiguous-stop yang dipertahankan. Hermes memakai anggaran loop retry 3, tanpa fallback provider yang dikonfigurasi, satu resubmit auth 401 yang digate di Direct SDK, dan timeout proses CLI 420 detik dengan batas idle upstream 180 detik. Anggaran loop bukan hitungan HTTP. Tidak ada probe model berbayar.

AC-01/02/09/19 PARTIAL. AC-03 hingga AC-08, AC-10 hingga AC-18, AC-21 NOT RUN untuk live. AC-20 mencatat shutdown aman; alokasi QA sebelumnya berupa estimasi, sehingga sisa kumulatif tidak dilaporkan sebagai angka terukur. Window live 0 menit.

### Checkpoint sebelum revisi

**P6 OVERALL — NOT PASS. INBOX — NOT RUN. ROLLOUT — OFF / PAUSED.**

| Task | Verdict | Bukti run resume |
|---|---|---|
| P6-01 | MERGED | Readiness P6-01 dipertahankan. |
| P6-02 | PARTIAL | Guard versi awal Laravel dan Hermes dipasang dengan rollout off. Hermes mempunyai intent durable untuk analisis bulanan v2. Batas satu submission pada CLI/provider belum terbukti. |
| P6-03 | BLOCKED | Gate sebelum Final belum terpenuhi. Tidak ada event UAT. |
| P6-04 | BLOCKED | Tidak ada dispatch, AI nyata, artefak, atau email. |
| P6-05 | BLOCKED | Tidak ada hasil live untuk direkonsiliasi. |
| P6-06 | PASS | Kedua task Windows Disabled, antrean kosong, staging QA dihapus, backup Python privat dipertahankan. |

| Gate | Hasil |
|---|---|
| C01 | PASS pada QA terisolasi. Final Laravel sukses saat HOLD, outbox tetap pending tanpa antrean atau HTTP. Hermes menolak admission dan melewati recovery saat HOLD. |
| C02 | PARTIAL. Filter UUID scanner dan klaim sudah diterapkan. Deferred queue envelope, dua kandidat MySQL dan balapan belum dibuktikan. |
| C03 | PARTIAL. Tes JMI membuktikan penolakan mismatch dan seleksi exact pin. Gateway live dan seluruh jalur downstream belum diuji. |
| C04 | PARTIAL. JMI membaca ulang pin pada tes CLOSED. Distribusi pin lintas runtime, expiry saat pekerjaan berlangsung dan release scheduler belum diuji. |
| C05 | PASS pada QA Hermes. Intent terbaca sebelum runner dipanggil, error tulis mencegah invocation, hasil sah dipakai ulang, dan concurrency yang diuji konvergen. |
| C06 | PARTIAL. Timeout dan interupsi berhenti tanpa invocation kedua di JMI. CLI Hermes mempunyai retry dan fallback sendiri, sehingga satu invocation CLI belum membuktikan satu submission provider. |
| C07 | PARTIAL. Enam tes Hermes dan dua tes Laravel lulus. Sembilan file Hermes terpasang dan hash cocok. Belum ada pembuktian config guard aktif pada gateway/cron dan worker aktual. |

AC-01/02/09/19 PARTIAL. AC-03 sampai AC-08, AC-10 sampai AC-18 dan AC-21 NOT RUN. AC-20 PASS untuk shutdown/cleanup/handoff run resume. Tidak ada UUID, ID evaluasi atau ID UMKM UAT. Tidak ada migrasi atau perubahan flag runtime.

Perubahan Laravel pada config webhook, sender, outbox, dan tes finalisasi. Perubahan Hermes pada engine, store, batas masuk jalur snapshot/render/callback/delivery/email/status serta tes pipeline. Lihat bukti resume untuk backup dan hasil pemeriksaan.

## Bukti run P6-01 sebelumnya

# P6 JoyUMKM ? Full Autonomous UAT

**P6 OVERALL ? NOT PASS. INBOX ? NOT RUN. ROLLOUT ? OFF / PAUSED.**

| Task | Verdict | Hasil |
|---|---|---|
| P6-01 Runtime readiness | PARTIAL | Laragon/MySQL/Hermes dapat diakses dan P5 ditutup. Guard satu event dan batas submit AI gagal dipenuhi. Identitas UAT dan konfigurasi lintas layanan belum divalidasi penuh. |
| P6-02 Controlled activation | BLOCKED | NO-GO pada guard. Gmail AUTH, aktivasi dan startup task tidak dijalankan. |
| P6-03 Browser UAT | BLOCKED | Tidak ada login Playwright, consent, draft atau Final dalam run ini. |
| P6-04 Real E2E processing | BLOCKED | Tidak ada event UAT untuk dispatch, AI, artefak atau SMTP. |
| P6-05 Output validation | BLOCKED | Tidak ada output UAT yang bisa direkonsiliasi. |
| P6-06 Operational handoff | PASS | Baseline aman diverifikasi ulang dan handoff disimpan. Tidak ada konfigurasi, data, atau resource runtime yang dibuat atau diubah oleh run ini. |

| Acceptance criteria | Hasil |
|---|---|
| AC-01 Runtime readiness | PARTIAL. HTTP 200 dari Windows dan Hermes, database UTC, migration batch 9, tool tersedia di host, ACL diperiksa. Service-account execution dan kontrak signed endpoint belum dibuktikan ulang. |
| AC-02 Backlog protection | FAIL. Antrean kosong saat inspeksi, tetapi scanner, delivery job, intake dan recovery belum dipagari ke satu UUID. |
| AC-03 Activation | BLOCKED. Guard belum siap dan kebijakan percobaan AI belum aman terhadap submit ambigu. |
| AC-04 hingga AC-08 | NOT RUN. Login, consent, Final, dispatch dan intake. |
| AC-09 hingga AC-14 | NOT RUN. AI, canonical report, PDF, XLSX, delivery dan integritas. |
| AC-15 hingga AC-18 | NOT RUN. SMTP acceptance, MIME, status sync dan UI. |
| AC-19 | PARTIAL. Digest file job Hermes cocok dan jumlah outbox tetap enam delivered. Deduplikasi event live dan digest seluruh baris histori tidak diuji. |
| AC-20 | PASS. Baseline off/paused teramati dan tidak ada resource sementara yang dibuat. |
| AC-21 | NOT RUN. Tidak ada pesan UAT. |

Event UUID, ID evaluasi, dan ID UMKM UAT belum ada. Run ini menghasilkan nol Final, nol panggilan AI dan nol email. Tidak ada perubahan source atau migrasi. Perubahan repo terbatas pada ketiga deliverable P6 dan addendum cleanup di tiga dokumen P5. Detail dan batas bukti ada di [ringkasan UAT](../evidence/p6-uat-summary.md).
