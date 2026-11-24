# P2 — Handoff Final Completion, JoyUMKM × Hermes

Status: **PASS (dev `joyumkm-v2`)** dengan risiko sisa yang tercantum di §10. Dokumen ini adalah handoff final P2. Tidak ada commit atau push. Tidak ada AI, email, webhook, atau backlog outbox yang dijalankan. P3 belum dimulai.

Referensi: runbook `P2_JoyUMKM_Post_P2_Timezone_Recovery_Runbook.md`, preflight `P2_JoyUMKM_Timezone_Remediation_Preflight.md`, skrip `p2-timezone-correction-post-p2.sql` (bukan skrip lama), dan kontrak `docs/evidence/p2-data-contract.md`.

---

## 1. Verdict

| Definition of done | Hasil |
|---|---|
| Recovery timezone dev berhasil | **PASS**. Correction procedure COMMIT tanpa error (S7 exit 0). |
| Database menggunakan koneksi UTC | **PASS**. `timezone => '+00:00'`, session `+00:00`, `app.timezone` tetap `UTC`. |
| Anchor offset = 0 | **PASS**. Selisih epoch 6/6 anchor = 0. |
| Enam migration P2 terpasang | **PASS**. Batch 7, status Ran. |
| Data historis tetap utuh | **PASS**. CMP baris 18 tabel: 0 mismatch. Tiga laporan identik. |
| Rekap WIB benar | **PASS**. `period_month` 10/10 final sesuai WIB. |
| Recipient pepper tersedia | **PASS**. 64 karakter hex, terbaca config. |
| Test terkait PASS tanpa regresi baru | **PASS**. Lihat §7. Identitas kegagalan sama dengan baseline. |
| Tidak ada critical defect P2 terbuka | **PASS**. Gap consent-address sudah ditutup (§2.3). Gap P5 tercatat di §9. |
| Handoff final lengkap | **PASS**. Dokumen ini. |

---

## 2. Perubahan yang dilakukan

### 2.1 Database dev `joyumkm-v2`

1. Maintenance mode aktif sepanjang Phase B–E. Worker dan scheduler tidak berjalan sebelum eksekusi, dan Hermes webhook tetap nonaktif.
2. Rollback tepat enam migration P2 (`migrate:rollback --step=6`).
3. Procedure `p2_tz_correct_post_p2` dijalankan dalam mode default tanpa `--force`. Procedure dihapus setelah CALL.
4. Config `config/database.php` pada blok `mysql` ditambah `'timezone' => '+00:00',`. Ini dilakukan **setelah** COMMIT, sesuai S8.
5. Enam migration P2 dijalankan ulang di bawah UTC.

Tabel helper `timezone_offset_anchors`, `timezone_offset_columns`, `timezone_offset_snapshot`, dan `timezone_offset_corrections` **dipertahankan** di dev sebagai bukti ledger dan snapshot. Tabel ini tidak mengubah data aplikasi.

### 2.2 Kode dan konfigurasi repo

| File | Perubahan |
|---|---|
| `config/database.php` | Satu baris `'timezone' => '+00:00',` pada koneksi `mysql`. |
| `app/Models/Umkm.php` | `booted()` dengan event `updating`: mengganti `business_email` mencabut tiga kolom persetujuan notifikasi, kecuali persetujuan baru disimpan dalam penyimpanan yang sama. |
| `tests/Feature/IntelligenceRecipientConsentTest.php` | Penyesuaian urutan test eligibility mengikuti invariant baru, plus tiga test baru: pencabutan saat alamat diganti, persetujuan bersama alamat baru tetap berlaku, dan penyimpanan field lain tidak mencabut persetujuan. |
| `docs/evidence/p2-data-contract.md` | Koreksi §2, §5, §6, dan §7 sesuai Phase E: `recipient_fingerprint` nullable, eligibility tidak mengembalikan kontak, pull terautentikasi adalah tanggung jawab P5, persetujuan terikat alamat, dan Gmail sebagai penyedia P5. |
| `.env` (git-ignored) | Ditambah `INTELLIGENCE_RECIPIENT_PEPPER`. Nilai tidak ditulis di dokumen ini. |
| `graphify-out/` | Diperbarui oleh `graphify update .`. Direktori ini git-ignored. |

Tidak ada migration baru dan tidak ada perubahan dependensi.

---

## 3. Backup dan restore evidence

| Backup | Lokasi (di luar repo, `%USERPROFILE%\joyumkm-backups\`) | Keterangan |
|---|---|---|
| Pra-eksekusi | `p2-final-20261124-000133\joyumkm-v2-full.sql.gz` | Dump pertama, flag `--tz-utc --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF`. Exit 0, stderr kosong, `gzip -t` OK. SHA-256 `40e7aa0882587a42e5295f0b564f3f3e43a756e427d09c620a88a69c88dd8f5b`. |
| S1 (sebelum rollback) | `p2-final-S1-20261124-000555\joyumkm-v2-full.sql.gz` | Dump di bawah maintenance mode, flag sama. Exit 0, stderr kosong, `gzip -t` OK. SHA-256 `fbba6dc2a606d561c6e0ba19e49f44df1c1b226194c706c3a79ddeffcaadeea2`. |

Restore uji:
- Dump pra-eksekusi dipulihkan ke `joyumkm_p2_final_lab`. CHECKSUM TABLE identik untuk 22 tabel, 4 trigger, 19 migration, dan jumlah baris sama.
- Fingerprint dev sebelum S1 identik dengan fingerprint sumber rehearsal, sehingga rehearsal valid untuk S1.
- Klon pristine `joyumkm_p2_final_ref` dipakai sebagai referensi perbandingan baris.

Klon `joyumkm_p2_final_lab`, `joyumkm_p2_final_ref`, dan database lab lama (`joyumkm_p2_*`, `joyumkm_tz_lab`) **belum dihapus**. Penghapusan memerlukan persetujuan owner (runbook §11).

---

## 4. Hasil recovery timezone

### 4.1 Rehearsal di lab (`joyumkm_p2_final_lab`)

Seluruh urutan diuji dulu. Semua gate lolos: rollback enam migration, S6 read-only, CALL procedure, CMP, config UTC, migrate, dan verifikasi P2. Angka rehearsal sama dengan dev.

### 4.2 Eksekusi dev

| Langkah | Hasil |
|---|---|
| S0 | `php artisan down`. Tidak ada worker atau scheduler yang berjalan. Tidak ada sesi aktif lain pada database. |
| S1 | Dump baru, SHA-256 dicatat (§3). |
| S2 | Gate data P2 kosong: artifacts 0, deliveries 0, suppressions 0, consent non-null 0, `artifact_status` bukan `pending` 0. **PASS.** |
| S3 | `migrate:rollback --step=6`, exit 0. Migration tersisa 13. |
| S4 | Trigger 0. Kolom TIMESTAMP 36 termasuk `failed_at`, yaitu 35 kolom non-failed. Tabel P2 tidak ada. **PASS.** |
| S5 | Loader anchor (bagian 1) exit 0. Anchor 6. |
| S6 | Offset 25200, session SYSTEM, trigger 0, anchor 6, sesi lain 0, dan setiap anchor selisih -25200. **PASS.** |
| S7 | Procedure dalam mode default, exit 0. Ledger 1 baris dengan offset 25200. Snapshot 70 baris. Procedure dihapus. |
| S8 | Config UTC ditambahkan. `config:clear`. Session Laravel `+00:00`. `finalized_at` id 9 terbaca `2026-11-22 13:10:20`, sama dengan payload. |
| S9 | `migrate` exit 0. Enam migration P2 batch 7 Ran. |
| S10 | Trigger 4. `period_month` non-null 10. Artifact pending 3. Anchor 6. |

### 4.3 Offset anchor sebelum dan sesudah

Anchor adalah `six_factor_evaluations.finalized_at` untuk id 9–14, dibandingkan dengan `occurred_at` payload webhook (UTC).

| id | `finalized_at` sebelum koreksi (MySQL instant − payload) | Sesudah koreksi |
|---|---|---|
| 9 | −25200 | 0 |
| 10 | −25200 | 0 |
| 11 | −25200 | 0 |
| 12 | −25200 | 0 |
| 13 | −25200 | 0 |
| 14 | −25200 | 0 |

Jumlah selisih enam anchor: sebelum −151200, sesudah 0.

---

## 5. Status enam migration P2

| Migration | Status |
|---|---|
| `2026_11_23_153536_add_artifact_status_to_intelligence_reports_table` | Ran, batch 7 |
| `2026_11_23_153537_create_intelligence_artifacts_table` | Ran, batch 7 |
| `2026_11_23_153538_create_intelligence_deliveries_table` | Ran, batch 7 |
| `2026_11_23_153539_add_notification_consent_to_umkms_table` | Ran, batch 7 |
| `2026_11_23_153540_create_email_suppressions_table` | Ran, batch 7 |
| `2026_11_23_153541_add_period_month_to_six_factor_evaluations_table` | Ran, batch 7 |

---

## 6. Trigger, historical report, dan integritas periode

**Trigger.** Empat trigger immutable aktif setelah re-migrate:
- `intelligence_reports_before_update_immutable`
- `intelligence_reports_before_delete_immutable`
- `intelligence_artifacts_before_update_immutable`
- `intelligence_artifacts_before_delete_immutable`

**Historical reports.** Tiga `intelligence_reports` tetap utuh. `id`, `report_hash`, dan panjang `report` identik dengan referensi pra-koreksi. Kolom waktu terkoreksi +25200 s secara instan. Artifact status ketiganya `pending`.

**Periode WIB.** Sepuluh final memiliki `period_month` yang sama dengan bulan WIB dari `finalized_at` (dihitung di SQL dengan session UTC): **10/10**. Semua final berada di `2026-11`. Tidak ada baris di batas bulan pada data dev. Batas bulan/tahun sudah diuji pada fixture rehearsal dan test.

**Integritas baris.** Perbandingan baris (`cmp.sh`, CRC per baris, 18 tabel) membandingkan lab/dev dengan referensi:
- Kontrol negatif (referensi tanpa offset) menunjukkan 11 tabel mismatch, sehingga pembanding sensitif.
- Dengan offset +25200 pada 35 kolom: **0 mismatch** pada dev dan lab. Kolom non-TIMESTAMP identik. Jumlah baris identik.
- Tabel `migrations` dikecualikan karena sengaja berubah (19 → 13 → 19).

**Jumlah baris dev sekarang:** six_factor_evaluations 14, intelligence_reports 3, webhook_outbox 6, umkms 25, users 4.

---

## 7. Hasil regression test

Test dijalankan pada `joyumkm_testing` melalui `php artisan test`. Tidak ada `migrate:fresh --env=testing`.

**Suite Laravel penuh** (412 test):
- 407 lulus, 3 FAIL, 2 ERROR.
- Identitas kegagalan **sama persis** dengan baseline P1/P2: `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores`, `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture`, `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` (FAIL), `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` (ERROR), dan `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` (ERROR).
- Tidak ada kegagalan baru.

Catatan: Run pertama tidak valid karena maintenance mode mengubah HTTP test menjadi 503 (268 gagal). Run yang valid dilakukan dengan file maintenance dipindahkan sementara selama sekitar 86 detik, lalu dipulihkan di perintah yang sama. Checksum dev sebelum dan sesudah jendela itu identik.

**Test P2 terkait:** `IntelligenceRecipientConsentTest` 11/11 lulus, termasuk tiga test invariant consent baru. Test P2 lain (SixFactorEvaluation, HermesWebhookOutbox, intelligence report, periode, dan recap) termasuk dalam suite penuh dan lulus.

**Hermes (container `hermes-agent`, Python):**
- `tests.test_callback`: **58 OK**, sesuai baseline.
- `tests.test_jmi` + `tests.test_review`: 158 test, **48 FAIL dan 25 ERROR**. Jumlah sama dengan baseline. Pola pesan kegagalan sama (`'failed' != 'completed'`, dan validator `action_plan.days_31_60`). Kode Hermes tidak diubah di sesi ini. Identitas per nama tidak diverifikasi karena daftar baseline per nama tidak tersimpan.

---

## 8. Konfigurasi recipient pepper (tanpa nilai secret)

- `INTELLIGENCE_RECIPIENT_PEPPER` ada di `.env` Laravel. Panjang 64 karakter hex (32 byte acak). Nilai tidak dicatat di repo dan tidak ditampilkan.
- Verifikasi: `config('services.intelligence.recipient_pepper')` terbaca, panjang ≥ 32.
- Salinan cadangan baris env ada di `%USERPROFILE%\joyumkm-backups\secrets\intelligence-recipient-pepper.env-line.txt`, dengan ACL hanya untuk user Windows pemilik.
- **Rotasi sebelum pemakaian.** Nilai pertama sempat tampil sebagian di output terminal karena pemeriksaan `grep` di sesi ini. Pepper itu dirotasi sebelum digunakan. Belum ada fingerprint atau baris `email_suppressions` yang dibuat (tabel kosong), sehingga tidak ada data yang tidak cocok. Owner sebaiknya menganggap nilai pertama sudah tidak dipakai.
- Kebijakan: pepper tidak boleh diganti setelah ada fingerprint. Rotasi memerlukan prosedur hitung ulang `email_suppressions`, yang belum ada.

---

## 9. Item P5 dan remaining risks

**Item P5 (belum dikerjakan, sesuai scope):**
- Alur pemberian persetujuan notifikasi (siapa dan bagaimana). Belum ada endpoint atau UI. Ini keputusan owner (gap A).
- Pull recipient terautentikasi (path, skema autentikasi dan secret terpisah, batas PII, TTL, kode error). Ini adalah addendum kontrak P5 (K3).
- Penyedia email Gmail. Dipilih owner, belum diimplementasikan.
- Endpoint unduhan artefak (K10). Belum dibangun.

**Risiko sisa:**
1. **Pembaca eksternal epoch (O5) belum dikonfirmasi owner.** Dalam repo dan container Hermes tidak ditemukan konfigurasi koneksi DB. `SHOW PROCESSLIST` tidak menunjukkan sesi lain. Pembaca eksternal di luar repo tetap tidak terkonfirmasi. Pembaca yang membaca epoch langsung akan melihat nilai yang sudah benar secara instan (sebelumnya 7 jam lebih awal). Owner perlu memberi tahu pembaca tersebut bila ada.
2. **Penulisan langsung lewat `DB::table` tidak mencabut persetujuan.** Event model hanya berlaku untuk jalur model. Jalur tulis aplikasi harus memakai model. Ini tercatat di kontrak §6.
3. **Bukti per baris untuk offset hanya 6 anchor.** Untuk 1.959 nilai lainnya, kesimpulan berdasarkan jalur tulis yang sama dan selisih konstan pada anchor (preflight §2.5). Owner menerima asumsi ini atau menambah anchor dari sumber lain.
4. **Data dev tidak memiliki kasus batas bulan.** Kasus batas diuji pada fixture rehearsal.
5. **Hermes `test_jmi`/`test_review`** memiliki baseline 73 kegagalan yang belum diperbaiki. Ini di luar scope P2.
6. **Rollback kembali (revert) tetap tersedia.** Skrip `p2-timezone-revert-post-p2.sql` diuji di lab dan memerlukan `timezone_offset_corrections` yang masih ada. Setelah re-migrate, revert harus dijalankan sebelum migrate P2 lagi (runbook §8, cabang B).

---

## 10. Daftar file yang berubah (sesi P2 final)

- `config/database.php`
- `app/Models/Umkm.php`
- `tests/Feature/IntelligenceRecipientConsentTest.php`
- `docs/evidence/p2-data-contract.md`
- `docs/audit/P2_JoyUMKM_FINAL_COMPLETION.md` (dokumen ini)
- `.env` (git-ignored; hanya `INTELLIGENCE_RECIPIENT_PEPPER`)
- `graphify-out/` (git-ignored; dibangun ulang)

File-file di bawah ini sudah berubah sebelum sesi ini (status awal repo) dan tidak diubah oleh eksekusi P2 final: `.env.example`, model SixFactor, `IntelligenceReport.php`, `HermesWebhookOutbox.php`, dan berkas migration P2.

Tidak ada commit, push, `git reset`, atau `git clean`.

---

## 11. Verdict final

**PASS — P2 selesai pada dev `joyumkm-v2`.**

Recovery timezone berhasil dengan prosedur post-P2 fail-closed. Koneksi UTC aktif. Anchor 6/6 bernilai 0. Migration P2 terpasang. Data historis tetap utuh dan terverifikasi per baris. Periode WIB benar. Pepper tersedia. Tidak ada regresi baru dalam suite Laravel penuh. Kegagalan Hermes sama dengan baseline.

Syarat untuk melanjutkan ke P5: keputusan owner pada alur consent dan pull recipient (§9), serta konfirmasi bahwa tidak ada pembaca eksternal epoch (§9 risiko 1). Keduanya bukan blocker P2 dan tidak dikerjakan di sini.
