# P3 JoyUMKM × Hermes

**P3 OVERALL — PASS.** Paket lokal otomatis tersedia untuk handoff, dan rollout produksi tetap nonaktif.

## Verdict

| Fase | Hasil |
|---|---|
| P3-01 Historical Snapshot | PASS diterima sebelumnya |
| P3-02 Intelligence dan Monthly Recap | PASS diterima sebelumnya |
| P3-03 Formal PDF Generator | PASS diterima sebelumnya |
| P3-04 Excel Workbook Generator | PASS diterima sebelumnya |
| P3-05 Artifact Validation dan Manifest | PASS diterima sebelumnya |
| P3-06 Pipeline Integration dan Recovery | FULL COMPLETION — PASS |
| P3-07 Integrated QA | FULL COMPLETION — PASS |

## Arsitektur

~~~mermaid
flowchart TD
    A[Final assessment Laravel] --> B[Transactional outbox v2]
    B --> C[Durable Hermes intake]
    C --> D[Signed historical snapshot GET]
    D --> E[Snapshot validation dan deterministic recap]
    E --> F[Intelligence analysis dan checkpoint valid]
    F --> G[Canonical report.json]
    G --> H[PDF generator]
    G --> I[XLSX generator]
    H --> J[Immutable manifest]
    I --> J
    J --> K[Verified Package Ready]
    K -.-> L[Future upload dan email]
~~~

~~~mermaid
stateDiagram-v2
    [*] --> Pending
    Pending --> Processing
    Processing --> Ready: Manifest verification valid
    Processing --> RetryWait: Recoverable stage failure
    RetryWait --> Processing: Cooldown expired
    Processing --> FailedPermanent: Permanent failure or budget exhausted
    Processing --> Conflict: Source or artifact identity conflict
    Ready --> Invalid: Committed bytes fail verification
    Invalid --> Ready: Original bytes restored and verified
~~~

~~~mermaid
sequenceDiagram
    participant O as Outbox
    participant I as Intake
    participant W as Worker
    participant S as Private state
    participant R as Recovery
    O->>I: evaluation.finalized v2
    I->>S: Persist job and enrollment
    W->>S: Commit snapshot, checkpoint, canonical report
    W->>S: Commit artifact stage
    Note over W,S: Worker exits before readiness update
    O->>I: Duplicate with same event UUID
    I-->>O: Existing identity and enrollment
    R->>S: Check durable files and lease
    R->>S: Reuse valid artifacts and finish missing stage
    R->>S: Verify manifest and record readiness
~~~

## Kontrak operasional

Intake baru mendaftarkan job v2 saat `JMI_ARTIFACT_PIPELINE_ENABLED=1`. Laravel adalah sumber penilaian dan penyedia snapshot. Worker Hermes menulis sumber kanonik serta paket di `$JMI_HOME/jobs/EVENT_ID/`. Default `JMI_HOME` adalah `/opt/data/joyumkm-intelligence/state`. Lock persisten ada di `$JMI_HOME/locks/` dan pada direktori job. Recovery menunggu cooldown per tahap, memeriksa file durabel, dan memakai ulang byte valid. Operator menggunakan `pipeline-status`, `pipeline-resume`, dan `pipeline-verify`.

Runtime memerlukan image Hermes dengan Python, ReportLab, pypdf, XlsxWriter, openpyxl, aset PDF, filesystem yang mendukung flock/rename/fsync, serta permission direktori 0700 dan file privat 0600. Signed GET memerlukan URL snapshot dan secret terpisah dari webhook dan callback. Gerbang rollout produksi tetap nonaktif. [Kontrak operasional P3-06](../evidence/p3-06-pipeline-contract.md)

## QA

Lima skenario menjalankan finalisasi Laravel, outbox v2, signed snapshot GET, model palsu, report kanonik, PDF, XLSX, dan manifest secara otomatis. Pengujian recovery menjalankan container terpisah yang membaca state QA persisten. Logs memuat identitas, checksum, penghitung per stage, dan durasi aktual. [QA integrasi](../evidence/p3-07-integrated-qa.md) · [Regresi](../evidence/p3-07-test-regression.md)

## Handoff P4

- Panggil `jmi.pipeline.get_ready_package(event_id)` untuk proyeksi transport dari manifest yang baru diverifikasi. Baca manifest v1 untuk identitas sumber dan lokasi relatif. PDF di `report.pdf` memakai `application/pdf`. XLSX di `report.xlsx` memakai `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`. Batas 10 MiB per artefak.
- `source_report_sha256` adalah SHA-256 byte `report.json`. `report_hash` adalah hash semantik callback Laravel. `artifact_set_hash` mengikat event, byte report sumber dan proyeksi artefak. `transport_artifact_set_hash` meringkas proyeksi yang dinormalisasi Laravel. Gunakan schema dan algoritma P3-05 apa adanya. [Kontrak manifest](../evidence/p3-05-manifest-contract.md)
- Verifikasi kembali saat akan mengonsumsi file. Lindungi konsistensi file sepanjang pembacaan dan cocokkan ukuran serta checksum stream dengan metadata terpaku. Setelah Laravel memberikan `report_id` melalui kontrak P4, ikat ke event dan identitas paket sebelum upload. Job legacy tetap memakai callback existing. Job enrolled berhenti pada `handoff_pending`. Callback paket, binary upload, dan binding ID adalah pekerjaan P4.

## Handoff P5

Gunakan pasangan PDF dan XLSX yang terikat manifest valid dan identitas paket yang sama. Ambil penerima dan persetujuan dari Laravel pada waktu delivery, ikuti suppression, dan kelola percobaan pengiriman melalui state delivery sendiri. Retry membaca artefak yang sama dan memverifikasi ulang kesiapan. Integrasi Gmail dan pengiriman email berada di P5.


## Deployment dan batas

Kode final tersimpan di `hermes_data:/joyumkm-intelligence/` dengan backup `backups/p3-06-before-pipeline-20261124T035636Z`. Pemeriksaan dependency lulus. Sidik SHA-256 gabungan state produksi identik sebelum dan sesudah sinkronisasi. Laravel tidak menghasilkan artefak. `HERMES_WEBHOOK_ENABLED=false` dan enrollment produksi masih nonaktif. Runtime provider asli, callback produksi, upload, email, dan cetak fisik berada di luar QA ini. [Hash deployment](../evidence/p3-07/deployment-sha256.txt)

## Referensi fase yang telah diterima

[P2 final](P2_JoyUMKM_FINAL_COMPLETION.md) · [P2 data contract](../evidence/p2-data-contract.md) · [P3-01 snapshot](P3-01_JoyUMKM_Historical_Snapshot.md) · [P3-02 rekap](P3-02_JoyUMKM_Intelligence_Monthly_Recap.md) · [P3-03 PDF](P3-03_JoyUMKM_Formal_PDF_Generator.md) · [P3-04 XLSX](P3-04_JoyUMKM_Excel_Workbook_Generator.md) · [P3-05 manifest](P3-05_JoyUMKM_Artifact_Validation_Manifest.md)
