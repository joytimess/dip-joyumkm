# JoyUMKM dan Hermes ? Handoff akhir lokal

## Handoff final: P6-05 PASS dan kontrak delivery (25 November 2026 WIB)

- **P6-04 PASS, P6-05 PASS, P6-06 PASS.** Verifikasi kotak masuk penerima bersifat opsional dan tidak dilakukan karena akses tidak tersedia.
- **Playwright** dengan sesi Laravel terautentikasi (token sementara user pemilik UMKM 26, dicabut setelahnya) memverifikasi tab Intelligence: penyimpanan `Lengkap`, email `Diterima server email` (25 November 2026 pukul 07.15 WIB), dan unduhan PDF serta XLSX yang SHA-256-nya cocok dengan manifest dan Laravel. Tidak ada page error atau console error.
- **Bug yang diperbaiki:** controller status delivery tidak menyimpan `provider_message_id` dan tidak mengganti placeholder `idempotency_key`. Perbaikan dan asersi test sudah masuk, dan baris event dikoreksi dari snapshot tersimpan tanpa kirim ulang. Hasil: `idempotency_key` `f304fb88…1cb652` dan `provider_message_id` `<f304fb88…1cb652@gmail.com>`.
- **Provenance:** `prompt_version` laporan tercatat `r1-prompt-6`, sedangkan teks aslinya dihasilkan `r1-prompt-5`. Tidak diubah pada event ini. Rekomendasi: pisahkan `validator_version` dan `generator_version` pada kontrak versi berikutnya.
- **Rollout:** OFF/PAUSED. Tidak ada pin UAT, `email.pause`, atau flag artifact di environment.

## Handoff terbaru: bounded automatic correction dan pipeline sampai email (25 November 2026 WIB)

Event `a424c677-7c5a-4c59-86aa-e35d9d348364` (evaluasi 15, UMKM 26) telah menyelesaikan laporan kanonis, PDF, XLSX, manifest, callback dan upload Laravel, satu pengiriman SMTP, dan status sync. Kebijakan recovery sekarang tiga attempt otomatis dengan validasi spesifik dan tanpa retry pada outcome ambigu. Attempt yang diterima berasal dari respons tersimpan dan tidak memakai panggilan AI baru. Validator P3-02 tidak dilemahkan.

- **P6-04 PASS.** Laporan kanonis valid. Provenance teks di bawah `r1-prompt-5`, tercatat `r1-prompt-6` pada dokumen.
- **P6-05 PARTIAL (tersusul: PASS, lihat handoff final di atas).** Artifact, Laravel, SMTP acceptance, dan status sync tervalidasi. Playwright live dengan data nyata dan verifikasi kotak masuk belum dijalankan.
- **P6-06 PASS.** Rollout OFF / PAUSED. Pin UAT dihapus. Windows tasks Disabled. `HERMES_WEBHOOK_ENABLED=false`.
- **Terbuka:** `provider_message_id` Laravel null; `idempotency_key` Laravel berbeda dari Hermes.

Rincian dan bukti: [P6 completion](P6_JoyUMKM_Full_Autonomous_UAT_Completion.md) dan [bukti P6](../evidence/p6-uat-summary.md).

> Catatan: paragraf "Corrective run" dan "Resume P6-04" di bawah ini ditulis sebelum run ini. Endpoint snapshot, callback, dan artifact sekarang menunjuk Laragon port 80 dengan Host `joyumkm.test`, dan terverifikasi. Rujukan port 8000 pada paragraf tersebut sudah tidak berlaku.

## Handoff UAT live terbaru

**Rollout OFF / PAUSED.** Jalur Socat via Docker DNS dan rute `joyumkm-events` dengan cron `jmi-recover` tanpa agent tambahan telah terbukti pada ACK 202 live. Backup subscription privat ada di `/opt/data/joyumkm-intelligence/backups/webhook_subscriptions.pre-p6-no-agent.json`.

Pertahankan event `a424c677-7c5a-4c59-86aa-e35d9d348364` dan intent `ANALYSIS_UNKNOWN`. Respons asli terikat melalui hash prompt dan tersimpan di Hermes. Enam tren historis tidak memiliki pembanding dan validasi masih gagal setelah penghapusan tren secara read-only. Tuntaskan rekonsiliasi terhadap kontrak analisis sebelum membuka kembali event. Jangan replay outbox atau memulai panggilan AI kedua untuk outcome ini. Gmail belum menerima pesan untuk event.

**Corrective run (25 November 2026 WIB):** satu generasi corrective dijalankan dan ditolak validator (`action_plan` dengan "pemasukan"/"pengeluaran"; temuan Tiga Dimensi saat Tiga Dimensi tidak tersedia). Job tetap `failed` / `ANALYSIS_UNKNOWN`, otorisasi `consumed`, tidak ada generasi lagi. Endpoint snapshot, callback, dan artifact sudah menunjuk Laragon port 80 dengan Host `joyumkm.test` dan terverifikasi dari Hermes. Pin UAT sudah dihapus. Rollout OFF / PAUSED. P6-04 BLOCKED, P6-05 NOT RUN. Rincian di [bukti P6](../evidence/p6-uat-summary.md) dan [verdict P6](P6_JoyUMKM_Full_Autonomous_UAT_Completion.md).

**Resume P6-04 (25 November 2026 WIB):** diagnosis read-only menemukan lima pelanggaran spesifik pada ekstensi bulanan: enam tren historis tanpa pembanding, satu kalimat `business_overview` yang ditolak claim checker, dan tiga temuan hypothesis tanpa `evidence_refs`. Pelanggaran ini memerlukan perubahan isi analisis, sehingga event tidak dilanjutkan dan tidak ada normalisasi yang diterapkan. Event tetap `failed` dengan `ANALYSIS_UNKNOWN`. Rincian di [bukti resume P6-04](../evidence/p6-uat-summary.md). Endpoint snapshot, callback, dan artifact di Hermes masih menunjuk port 8000 dan belum diubah.

Perbaikan sementara transport Apache telah dikembalikan setelah UAT. Sebelum resume, snapshot, callback dan binary upload harus menggunakan vhost JoyUMKM yang dapat dicapai dari Hermes. Konfigurasi baseline ketiganya masih menunjuk port 8000. [Bukti UAT](../evidence/p6-uat-summary.md)


## Resume transport Socat

Jalur signed HOLD sudah PASS. Proxy aktif mengarah ke `hermes-agent:8644` pada bridge `joyumkm-hermes-webhook`. Gunakan jalur ini pada resume. Proxy terdahulu tersimpan dalam keadaan stopped sebagai `hermes-webhook-proxy-p6-backup`.

Sebelum membuka pin, verifikasi jalur sukses admission-only yang didukung gateway dan mempertahankan ACK Laravel. Subscription saat ini meneruskan hasil intake sukses ke admission agent, sehingga ada potensi AI kedua di luar kontrol JMI. Pertahankan dispatch disabled dan kedua task Windows Disabled sampai gate tersebut lolos. Gmail AUTH, browser, AI, SMTP dan validasi output masih tertahan. P6 OVERALL NOT PASS dan rollout OFF/PAUSED.


P0?P1 diterima dengan dispatch ditangguhkan. P2 menerima kontrak data, UTC dan histori. P3 menerima snapshot, rekap WIB dan paket lokal. P4 menerima callback, binary upload, download terotorisasi dan hardening Laragon. P5 PASS lokal setelah addendum cleanup. P6 NOT PASS dan rollout OFF / PAUSED.

- [P2](P2_JoyUMKM_FINAL_COMPLETION.md), [P3](P3_JoyUMKM_FINAL_COMPLETION.md), [P4](P4_JoyUMKM_FINAL_COMPLETION.md), [P5](P5_JoyUMKM_FINAL_COMPLETION.md)
- [Verdict P6](P6_JoyUMKM_Full_Autonomous_UAT_Completion.md) dan [bukti runtime](../evidence/p6-uat-summary.md)

## Keputusan resume P6-02

P6-02 PARTIAL. Pertahankan webhook disabled serta worker/scheduler Windows Disabled. Guard Laravel menggunakan HERMES_WEBHOOK_UAT_SCOPE_ENABLED dan storage/app/private/hermes-uat-scope.json. Entry point intake dan cron Hermes memaksa mode UAT selama HOLD ini dan membaca uat-scope.json dari JMI_HOME. Saat mode diaktifkan, kedua pin harus cocok pada UUID, evaluasi, UMKM, dan versi v2 sebelum release. Kedua implementasi membaca ulang pin dalam uji terisolasi. Jalur live ke gateway tertahan karena Socat mengarah ke IP proxy sendiri. Pulihkan jalur ke gateway tanpa mengganggu layanan lain, lalu buktikan signed admission dalam HOLD sebelum Gmail AUTH dan aktivasi.

Analisis bulanan v2 menulis analysis_submission sebelum runner dipanggil. Job ANALYSIS_UNKNOWN harus ditahan. Pertahankan checkpoint sah dan rekonsiliasi dengan bukti provider sebelum keputusan operator. Revisi mengizinkan retry/fallback bawaan dalam satu invocation logis. Profil saat ini memiliki retry budget 3, tanpa fallback terkonfigurasi, timeout CLI 420 detik, dan satu retry auth 401 bergate di Direct SDK. Lanjutkan setelah seluruh safety gate lulus. Backup Hermes ada di /opt/data/joyumkm-intelligence/backups/p6-02-resume-20261124T184742Z dan backup checkpoint sebelumnya di /opt/data/joyumkm-intelligence/backups/p6-02-20261124T183445Z. Untuk rollback source, bandingkan file aktif dengan backup dan pulihkan hanya file source yang terdampak setelah memastikan tidak ada pekerjaan aktif. Pertahankan state job, intent dan hasil durable.

## Syarat sebelum aktivasi berikutnya

1. Terapkan seleksi fail-closed untuk satu UUID pada scanner dan job queue Laravel serta intake, worker dan recovery Hermes. Tahan dispatch sampai UUID Final dipin melalui identitas draft/UMKM terotorisasi. Sertakan callback dan kelanjutan pipeline, upload, SMTP dan revisi status dalam batas yang sama. Verifikasi dengan seleksi kandidat atau tes tanpa efek eksternal.
2. Pastikan job dengan hasil submit AI ambigu berhenti sebelum percobaan berikutnya. Pertahankan checkpoint hasil yang valid dan buktikan recovery tidak mengirim ulang analisis yang mungkin sudah diproses. Buktikan juga batas retry pada provider yang efektif.
3. Siapkan akun dan UMKM UAT melalui jalur aplikasi yang sah. Verifikasi consent, suppression, dan signed GET pada event UAT yang disetujui. Validasi link antarservice dan lakukan Gmail TLS/AUTH tanpa DATA setelah hard gate terpenuhi.
4. Simpan backup privat. Aktifkan pipeline, artifact delivery, email delivery, dan status sync hanya di bawah guard. Verifikasi flag `JMI_ARTIFACT_PIPELINE_ENABLED`, `JMI_ARTIFACT_DELIVERY_ENABLED`, `JMI_EMAIL_DELIVERY_ENABLED`, dan `JMI_EMAIL_STATUS_SYNC_ENABLED` efektif serta pause terkait dibuka hanya dalam window UAT. Buka `HERMES_WEBHOOK_ENABLED` terakhir. Gunakan task existing `JoyUMKM Hermes Webhook Worker` pada `database:hermes-webhooks`, `JoyUMKM Laravel Scheduler`, dan cron `jmi-recover`; gunakan satu instance per peran.
5. Lakukan satu Final lewat Playwright. Setelah timeout atau hasil tak jelas, baca state evaluasi dan outbox sebelum mengambil tindakan. Untuk SMTP `sent`, retry yang diizinkan hanya sinkronisasi status. Untuk `delivery_unknown`, pertahankan outcome tanpa submit ulang. Lanjutkan upload dari paket immutable yang sudah diverifikasi.
6. Tutup admission dan dispatch terlebih dahulu. Pertahankan guard sambil menunggu operasi berjalan selesai dalam timeout yang berlaku. Kembalikan webhook off, pipeline dan delivery off, pause delivery/email/status aktif, dan kedua task Windows ke Disabled. Pastikan recovery tidak mengambil job tertahan. Pertahankan Final, paket dan outcome yang sudah durable.

Inbox, acceptance Gmail, dan alur live P6 masih belum diuji. Aktivasi permanen memerlukan otorisasi terpisah. Verifikasi hosting nonlokal mencakup service account, QPDF/Python, storage privat, limit request/timeout dan alur HTTP.
