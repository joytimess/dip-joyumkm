# Bukti presentasi DIP-JoyUMKM

## Basis audit

- Repository [joytimess/dip-joyumkm](https://github.com/joytimess/dip-joyumkm). Revisi lokal `main` **9fa9ccb32b37f39aa66f7bc64709dc838b0e4eff** dan pembanding `deploy-prod` **804738b0a39f9dc2658e40e92e796077d131aca6**. Kedua ref cocok dengan `git ls-remote --heads origin`. Basis isi ialah main dan working tree saat audit. Perubahan kompatibilitas D0 dan landing yang sudah ada dibaca sebagai bukti lokal.
- Graphify digunakan untuk menemukan relasi `SixFactorEvaluationService`, `HermesWebhookOutbox` dan `IntelligenceSnapshotService`. Temuan diperiksa melalui service dan rute. `app/Events` dan `app/Listeners` tidak tersedia. Event integrasi dibuat oleh service outbox.
- `README.md`, `docs/API.md`, `docs/IMPLEMENTATION.md`, `docs/HERMES_WEBHOOK.md`, inventaris `docs/prd/` dan `docs/audit/`, beserta runbook D0/P6 menjadi konteks. PRD produk yang disebut README (`PRD-JoyUMKM-Fullstack-End-to-End-V1.md`) tidak tersedia di dua ref yang diperiksa. Brief P0–P2, PRD dashboard/pendaftaran, P4/P5 dan runbook P6 dipakai sesuai ruang lingkup. PRD dibaca sebagai spesifikasi.
- Sumber aplikasi mencakup rute, controller, Form Request, API Resource, middleware, service, model, jobs, migration, Blade, JS dan CSS. Manifest dan kedua lockfile mengonfirmasi Laravel 13.40.0, Sanctum 4.3.3, Tailwind 4.3.4, ApexCharts 8.7.0 dan Vite 8.3.5.
- Runtime lokal diperiksa melalui metadata Docker dan modul `jmi` yang terpasang di volume Hermes. `.env.example` dan `config/services.php` menjadi referensi nama konfigurasi. Pemeriksaan `.env` lokal dibatasi jenis DB dan flag dispatch. Pemeriksaan konfigurasi Hermes dibatasi flag email dan status.
- Bukti QA berasal dari source test PHPUnit/JS, fixture Playwright, laporan P0–P6, dan artefak terdokumentasi. Status VERIFIED di bawah merujuk pada bukti pengujian yang sudah tercatat, kecuali pemeriksaan konfigurasi lokal yang dilakukan pada audit ini.

## Slide → klaim → sumber → status

| Slide | Klaim | Sumber dan bukti | Status |
|---|---|---|---|
| 01 | Sistem penilaian UMKM dan Intelligence Reporting | `README.md` ruang lingkup dashboard. `docs/audit/JoyUMKM_Hermes_FINAL_HANDOFF.md` addendum final P6. | IMPLEMENTED |
| 02 | Kebutuhan penilaian terstruktur, interpretasi dan efisiensi laporan | `README.md` tujuan dashboard. `docs/prd/JoyUMKM_V1_PRD_Brief_P0.md` baseline. Brief presentasi untuk framing akademik. | Rumusan proyek. Dampak empiris UNVERIFIED |
| 03 | Enam Faktor skala 1–5. INS/ECT/OPS dari evaluasi terpisah. Dashboard, histori Final, PDF/XLSX dan distribusi dengan consent. | `resources/js/six-factor-draft.js` sixFactors dan valid. `resources/js/three-dimension-health-data.js` dimensionNames. `routes/api.php` dan `ThreeDimensionHealthService`. Handoff P3/P5, P6 untuk laporan dan ketiadaan Tiga Dimensi pada event uji. | IMPLEMENTED. Output P6 VERIFIED terbatas |
| 03, 12–13 | Survei anonim owner–karyawan, undangan, pembanding persepsi dan pengingat | Inventaris rute, model dan UI tidak memberi bukti alur tersebut. Brief journey map sebagai dasar hipotesis. | Konsep dan peluang pengembangan |
| 04 | Web → Laravel API → service → MySQL. Outbox HMAC → Hermes. Hermes menarik snapshot, membangun paket dan punya dua cabang output. | `routes/{api,web}.php`, `HermesWebhookSender`, `IntelligenceSnapshotService`. Docker `jmi/{engine,pipeline,delivery,email_delivery}.py`. Handoff P3–P6. | IMPLEMENTED. P6 VERIFIED pada satu event UAT |
| 05 | Layer stack, ownership, reliability, Playwright dan UAT | Manifest dan lockfile. `app/Http/{Requests,Resources,Middleware}`, `database/migrations`, `app/Jobs/DeliverHermesWebhook.php`, `tests/Feature/OwnershipApiTest.php` dan suite `tests/js/`. MySQL lokal dikonfirmasi melalui konfigurasi yang dipilih. | IMPLEMENTED. Uji tercatat VERIFIED sesuai scope |
| 06 | Hermes menyediakan runtime AI, pipeline JMI buatan proyek mengatur otomasi | Docker `jmi/{engine,pipeline,artifacts,email_delivery,email_status_sync}.py`, `docs/audit/P3-06_JoyUMKM_Pipeline_Integration_Recovery.md`, handoff final. n8n disebut sebagai referensi dalam brief. | IMPLEMENTED |
| 07 | Laravel menjaga data dan skor. Hermes mengolah snapshot, insight, dokumen dan pengiriman | `SixFactorEvaluationService::finalize`, `IntelligenceReportService`, rute internal. Docker `jmi/{snapshot,monthly,engine,pdf,xlsx}.py`. | IMPLEMENTED |
| 08 | Finalisasi dan outbox atomik. Event evaluation.finalized. Email dan delivery artefak mengambil paket tervalidasi melalui jalur masing-masing | `SixFactorEvaluationService::transition`, `HermesWebhookOutbox::recordFinalization`. Handoff P4-05 dan P5-03–P5-05. P6 mengonfirmasi ACK, artefak, SMTP dan status. | IMPLEMENTED dan VERIFIED terbatas. Penuh secara konsep |
| 09 | Validasi/skor respons Laravel, laporan asinkron. Alur assessment tidak menggunakan /predict Hermes | `routes/api.php`, `SixFactorEvaluationController`, `ScoringService`, `SixFactorEvaluationService` dan runtime JMI. P6 menunjukkan dimensi yang tidak tersedia pada event UAT. | PARTIAL terhadap skenario modul |
| 10 | Synthetic webhook, HMAC, pipeline, retry, idempotensi, recovery, integritas, SMTP dan UAT Playwright | `tests/Unit/HermesWebhookSignatureTest.php`, `tests/Feature/HermesWebhookOutboxTest.php`, `docs/audit/P3-07_JoyUMKM_Integrated_QA.md`, P4-05 dan P5-07. Addendum P6-05 menunjukkan unduhan aktual terautentikasi dan kecocokan hash. | VERIFIED sesuai scope tiap uji |
| 11 | Pemetaan skenario 1/3/5, rollout OFF/PAUSED dan SMTP acceptance | Brief modul. Handoff final P6 dan addendum P6-05. Audit read-only mencatat `HERMES_WEBHOOK_ENABLED=false`, email dan sync `ENABLED=0` dan `PAUSED=1`. | VERIFIED lokal dan sesuai handoff. Hosting eksternal UNVERIFIED |
| 12 | Landing, registrasi dan wizard sudah tersedia. Awareness dan pertimbangan pemakaian merupakan hipotesis | `routes/web.php`, `resources/views/{welcome,register}.blade.php`, `resources/js/pages/umkms.js`, PRD wizard. | IMPLEMENTED pada kanal bertanda Tersedia. Kurva konseptual |
| 13 | Draft, finalisasi, histori, tab Intelligence, unduhan. SMTP uji sebagai bagian retention | `routes/api.php`, `resources/js/pages/intelligence-report-data.js`, addendum P6-05. | IMPLEMENTED. Unduhan dan SMTP VERIFIED di UAT terbatas |
| 14 | Penilaian terstruktur, integrasi otomasi dan bukti kesiapan terbatas | Handoff akhir dan addendum P6. | VERIFIED sesuai batas pengujian yang disebut |

## Rekonsiliasi dokumen

| Fase | Bukti yang dipakai dan batasnya |
|---|---|
| P0–P1 | Laporan audit awal dan stabilisasi memberi baseline integrasi. Dispatch ditangguhkan. |
| P2 | `P2_JoyUMKM_FINAL_COMPLETION.md` mencatat kontrak data, histori, perbaikan UTC dan periode WIB pada lingkungan dev. |
| P3 | `P3_JoyUMKM_FINAL_COMPLETION.md` dan P3-01–P3-07 mencatat snapshot, rekap, report kanonis, PDF, XLSX, manifest dan recovery lokal. |
| P4 | `P4_JoyUMKM_FINAL_COMPLETION.md` dan P4-01–P4-07 mencatat callback sebelum upload pada cabang artefak, storage privat, unduhan terotorisasi dan hardening Laragon. |
| P5 | `P5_JoyUMKM_FINAL_COMPLETION.md` dan handoff P5-01–P5-07 mencatat consent, signed recipient pull, SMTP TLS, fail-closed pada outcome ambigu dan callback status. SMTP nyata dilaporkan kemudian pada P6. |
| P6 | Bagian teratas `P6_JoyUMKM_Full_Autonomous_UAT_Completion.md` dan `JoyUMKM_Hermes_FINAL_HANDOFF.md` menyusul catatan PARTIAL lama untuk P6-05. Checkpoint P6-04/05/06 PASS pada bukti terbaru. Regresi lama tetap tercatat. Teks laporan berasal dari kandidat tersimpan di bawah prompt 5 lalu tervalidasi dengan kontrak prompt 6. Recovery yang diterima tidak menggunakan panggilan AI baru. |
| D0 | `D0_JoyUMKM_Deployment_Compatibility_Report.md` dan `docs/deployment/DOMAINESIA_DEPLOYMENT_RUNBOOK.md` membahas QA hosting bersyarat. Tidak ada bukti aktivasi produksi baru. |

## Informasi [PERLU DIVERIFIKASI]

- Runtime hosting eksternal, termasuk konektivitas dan aktivasi aktual. Tidak diakses dalam audit read-only. Status rollout berasal dari handoff dan konfirmasi flag lokal.
- Penerimaan pesan di kotak masuk. Bukti hanya mencapai penerimaan SMTP. Kotak masuk tidak tersedia untuk diperiksa.
- Riset pengalaman pengguna. Tidak ditemukan pengukuran emosi atau data riset untuk menilai pain point. Kurva dan persona pendukung adalah hipotesis.
- PRD utama yang disebut README tidak ditemukan. Bukti produk menggunakan source aktual dan PRD fase yang tersedia. Pemetaan akademik memakai deskripsi skenario dalam brief.
