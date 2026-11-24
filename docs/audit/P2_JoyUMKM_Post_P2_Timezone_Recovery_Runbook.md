# P2 — Runbook Recovery Timezone Pasca-Migration (JoyUMKM)

Status: **dry-run lab selesai. Dev `joyumkm-v2` belum dimutasi. Menunggu persetujuan owner.**
Kondisi dev diperiksa pada 2026-11-23 sekitar 23:32 (waktu lokal). Dokumen ini adalah pengganti untuk eksekusi pasca-P2. Preflight pra-P2 dan runbook asli **tidak diubah**.

Referensi:
- `docs/audit/P2_JoyUMKM_Timezone_Remediation_Preflight.md`: pra-P2. Sebagian sudah usang (lihat §2).
- `docs/audit/p2-timezone-correction.sql`: runbook asli. **Jangan dijalankan pada dev pasca-P2** (lihat §5).
- `docs/audit/p2-timezone-correction-post-p2.sql`: koreksi baru, fail-closed.
- `docs/audit/p2-timezone-revert-post-p2.sql`: revert koreksi, teruji.

Batasan kerja: tidak ada kredensial yang ditampilkan. Tidak ada email, pemanggilan AI, webhook, rollback, atau UPDATE yang dijalankan pada dev. `config/database.php` belum diubah. Perubahan config diuji lewat override runtime di lab.

---

## 1. Ringkasan dan verdict

| Item | Hasil |
|---|---|
| Kondisi dev | 6 migration P2 **sudah terpasang** (batch 7). Preflight lama mengasumsikan *Pending*, sehingga urutan lamanya tidak lagi berlaku. |
| Backup | Penuh, `--tz-utc`, terverifikasi: gzip OK, checksum tercatat, dan restore ke lab identik per tabel. |
| Rollback 6 P2 | Tidak menghilangkan data yang tidak bisa dipulihkan. Syarat: lima tabel/kolom P2 masih kosong saat eksekusi (gerbang S2). |
| Runbook asli | **Tidak aman** untuk kondisi pasca-P2. COMMIT tidak bersyarat, dan dengan `--force` terjadi partial commit (terbukti di lab). |
| Runbook baru | Fail-closed: stored procedure dengan EXIT HANDLER, gerbang G1–G6, verifikasi V1–V3. Lulus semua uji di §6. |
| Config UTC | Harus di-deploy bersamaan dengan COMMIT. Tanpa itu, pembacaan Laravel salah +7 jam (terukur di lab, C1). |
| Verdict lab | **GO** (prosedur teruji). |
| Verdict dev | **NO-GO sekarang.** Menjadi GO setelah syarat di §10 terpenuhi. |

---

## 2. Kondisi aktual dev (read-only)

| Item | Preflight (pra-P2) | Aktual sekarang | Catatan |
|---|---|---|---|
| Database | `joyumkm-v2` | `joyumkm-v2` | `DB_URL` tidak diset. `DB_DATABASE` satu-satunya penentu database. |
| Versi MySQL | 8.4.3 | 8.4.3 | |
| `time_zone` global / session | SYSTEM / SYSTEM | SYSTEM / SYSTEM | `system_time_zone` = SE Asia Standard Time. Offset server = 25200. |
| Config Laravel | `app.timezone` UTC; `mysql` tanpa key `timezone` | sama (diverifikasi) | `config/app.php:68` = `'UTC'`. `config/database.php` blok `mysql` tanpa `timezone`. |
| Migration P2 | Pending | **Ran, batch 7** (153536–153541) | Batch 6 = `create_intelligence_reports_table`. |
| Trigger | 0 | **4** | `intelligence_reports` dan `intelligence_artifacts`, UPDATE dan DELETE. |
| Kolom TIMESTAMP | 36 (termasuk `failed_jobs.failed_at`) | **42** (termasuk `failed_at`) | Enam kolom P2 baru: `umkms.notification_consent_at`, `intelligence_deliveries` ×3, `intelligence_artifacts.created_at`, `email_suppressions.created_at`. |
| `period_month` | belum ada | ada, terisi 10 baris (semua Final) = `2026-11` | Dihitung oleh backfill saat config SYSTEM. |
| `artifact_status` | belum ada | ada, 3 baris, semua `pending` | |
| Tabel P2 baru | 0 baris | `intelligence_artifacts` 0, `intelligence_deliveries` 0, `email_suppressions` 0 | |
| `notification_consent_at` non-null | — | 0 | |
| Jumlah baris (`COUNT(*)`) | six_factor 14, intelligence_reports 3, webhook_outbox 6 | sama | `TABLE_ROWS` InnoDB sempat menunjukkan 2 untuk `intelligence_reports`. Itu estimasi, bukan jumlah. |
| Nilai TIMESTAMP non-null (35 kolom pra-P2) | 1.965 | 1.965 | |
| Anchor (six_factor id 9–14) | −25200 pada 6/6 | −25200 pada 6/6 | Selisih `UNIX_TIMESTAMP(finalized_at)` terhadap `occurred_at` payload. |
| Sidik jari 35 kolom | — | identik dengan hasil restore backup | Menunjukkan tidak ada penulisan pada data sejak backup. |

Perbedaan kritis: rencana preflight "migrasi P2 dulu, lalu koreksi" tidak lagi tersedia. Koreksi sekarang membutuhkan rollback P2 terlebih dahulu (lihat §4).

---

## 3. Backup dan uji restore

- Berkas: `C:\Users\itsba\joyumkm-backups\p2-post-migration-20261123-233223\joyumkm-v2-full.sql.gz` (24,1 KB). Berada di luar repo dan tidak di-commit.
- Perintah: `mysqldump --tz-utc --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 joyumkm-v2 | gzip -9`. Exit 0, stderr kosong.
- Integritas: `gzip -t` OK. SHA-256 `17bfa2167dabe157dcbb00999e45eb7b183d7f2a93eaa7bdfb588651d5d82335`. Tercatat di `SHA256SUMS.txt` pada folder yang sama.
- Uji restore (A1): dipulihkan ke `joyumkm_p2_lab`. Hasilnya 22 tabel dan 4 trigger. Jumlah baris dan `CHECKSUM TABLE` identik untuk semua 22 tabel.
- Berkas ini memuat data pengguna. Simpan terenkripsi atau di lokasi terbatas. Jangan dibuka atau dibagikan.

Dump baru wajib dibuat sebelum eksekusi (S1), karena data dev bisa bertambah sejak 23:32.

---

## 4. Audit rollback enam migration P2

Urutan rollback (`migrate:rollback --step=6`) membalik dari 153541 ke 153536. Hasil pada klon lab (L1):

| Migration | Aksi `down()` | Data yang hilang | Kehilangan permanen? |
|---|---|---|---|
| 153541 `period_month` | drop kolom, index, dan CHECK | 10 nilai (`2026-11`) | **Tidak.** Turunan dari `finalized_at`. Backfill ulang di UTC menghasilkan nilai identik (10/10, M1). |
| 153540 `email_suppressions` | drop tabel | 0 baris | Tidak ada data. |
| 153539 consent umkms | drop 3 kolom, FK, dan CHECK | 0 non-null | Tidak ada data. |
| 153538 `intelligence_deliveries` | drop tabel | 0 baris | Tidak ada data. |
| 153537 `intelligence_artifacts` | drop tabel | 0 baris | Tidak ada data. |
| 153536 `artifact_status` | drop trigger ×2, CHECK, unique index, kolom | `artifact_status` pada 3 baris, semua `pending` (sama dengan default) | **Tidak.** Tidak ada informasi yang hilang. Isi `report`, `report_hash`, dan identitas laporan tidak berubah (dibuktikan lewat perbandingan baris, CMP). |

**Data baru yang perlu dijaga.** Tiga laporan intelligence dibuat 2026-11-23 06:31–06:35 UTC dari callback Hermes. Baris ini **tidak hilang**. Hanya kolom `artifact_status` yang di-drop lalu dibuat ulang dengan default. Di lab, ketiganya identik kecuali kolom waktu yang memang dikoreksi.

**Kesimpulan.** Tidak ada data yang tidak dapat dipulihkan. Tidak perlu STOP untuk kondisi data saat ini. Namun rollback hanya aman bila lima tabel/kolom P2 tetap kosong sampai eksekusi. Jika ada baris baru di sana, rollback akan menghapusnya. Ini diperiksa di gerbang S2, yang berhenti bila hasilnya tidak nol.

**Risiko selama jendela eksekusi:**
- Trigger immutable tidak aktif antara rollback dan migrasi ulang. Ini disengaja, karena koreksi `intelligence_reports` membutuhkannya. Mitigasi: `php artisan down`, worker berhenti, dan gerbang G6.
- DDL MySQL tidak transaksional. Rollback yang gagal di tengah meninggalkan keadaan campuran. Pemulihan: restore dari dump (diuji, cabang C di §8).
- Hash laporan tidak terdampak. `report_hash` dihitung dari payload (`IntelligenceCallbackPayload::hash()`), bukan dari kolom DB. Koreksi tidak mengubah hash, dan pengecekan idempotensi memakai hash payload.

**Mengapa rollback diperlukan.** Koreksi `intelligence_reports` (3 baris, 12 nilai TIMESTAMP) ditolak trigger immutable: `ERROR 1644 … immutable` (A2, A3). Menonaktifkan trigger secara manual dilarang oleh instruksi. Alternatif lain, yaitu membiarkan tiga laporan tidak terkoreksi, menghasilkan data campuran dan tidak diterima.

---

## 5. Audit runbook asli (`p2-timezone-correction.sql`)

Diuji pada klon full dev yang sudah P2 (`joyumkm_p2_lab_neg`, A2–A4):

| # | Temuan | Bukti | Dampak | Perbaikan di versi baru |
|---|---|---|---|---|
| F1 | `COMMIT;` tidak bersyarat. Keputusan ROLLBACK hanya berupa komentar. | Dijalankan dengan `--force`: exit 0, ledger tercatat, snapshot 70 baris, `finalized_at` terkoreksi, `intelligence_reports` tidak. | **Partial commit.** Data setengah terkoreksi. | Seluruh koreksi di dalam procedure. COMMIT hanya setelah V1–V3 lolos. |
| F2 | Verifikasi hanya ditampilkan sebagai angka dan tidak menghentikan proses. | Script mencetak `delta_violations = 4`, lalu tetap COMMIT (F1). | Operator harus membaca manual. | Gerbang `SIGNAL` yang menghentikan proses. |
| F3 | Cek anchor lolos secara kosong (vacuous) bila anchor belum dimuat. | `anchors_loaded = 0`, `anchor_violations = 0`. | Anchor "lulus" tanpa bukti. | G5a: minimal 6 anchor, dan setiap anchor divalidasi (G5b). |
| F4 | Daftar kolom diambil dari skema pra-P2 dan tidak dicek terhadap skema aktual. | Pasca-P2 terdapat 41 kolom non-failed (6 baru). | Kolom P2 yang terlewat diam-diam bila rollback tidak tuntas. | G4: inventaris `information_schema` harus sama persis dengan tabel helper 35 kolom. |
| F5 | Tidak ada gerbang untuk trigger atau migration P2. | `triggers_on_db` hanya informasi. | Koreksi bisa berjalan di kondisi yang salah. | G3a dan G3b. |
| F6 | Tidak ada gerbang konkurensi. | Tidak ada pemeriksaan. | Penulis lain bisa menulis di tengah koreksi. | G6, dan `SELECT … FOR UPDATE` pada 12 tabel. |
| F7 | Loader anchor tidak ada di dalam script. | Anchor harus diisi manual. | Sumber anchor tidak tercatat. | Loader SQL di langkah 1 file. |
| F8 | Revert tidak pernah diuji. | Hanya inverse SQL tanpa gerbang. | Pemulihan bergantung pada ketelitian operator. | `p2-timezone-revert-post-p2.sql` dengan V0–V2. |

Runbook asli tetap utuh. Hanya file baru yang dibuat. Runbook asli boleh dibaca sebagai referensi, tetapi **jangan dijalankan** pada dev pasca-P2.

---

## 6. Hasil dry-run di full clone

Semua klon berasal dari dump full dev, kecuali yang disebutkan. Dev tidak disentuh.

| ID | Skenario | Hasil | Bukti / catatan |
|---|---|---|---|
| A1 | Restore backup penuh ke `joyumkm_p2_lab` | **PASS** | 22 tabel, 4 trigger, `CHECKSUM TABLE` identik untuk semua tabel. |
| A2 | Skrip asli, klon P2, mode default | Berhenti di `ERROR 1644` (baris 155). Tidak ada yang tertulis. | ledger 0, snapshot 0, epoch tetap −25200. Perilaku ini bergantung pada mode client. |
| A3 | Skrip asli, `--force` | **FAIL: partial commit (F1).** `intelligence_reports` 3 baris tidak terkoreksi. | `negB-original-force.out`. |
| A4 | Kontrol pembanding: `compare.sh` pada hasil A3 | Mendeteksi `intelligence_reports` mismatch 3. **HASIL: FAIL.** | Membuktikan pembanding sensitif, sehingga PASS di CMP bermakna. |
| L1 | Rollback 6 P2 pada `joyumkm_p2_lab` | **PASS** | exit 0. Trigger 0. Kolom dan tabel P2 hilang. `migrations` 19→13. Reports 3 utuh. |
| L2 | Procedure baru, happy path, setelah rollback | **PASS** | ledger 1. Snapshot 35 + 35, non-null 1.965 per fase. Anchor 6, selisih 0. Procedure dihapus setelah selesai. |
| L3 | Split path S5→S7 (loader, lalu procedure) | **PASS** | Anchor 6/6 tervalidasi. Setelah CALL: ledger 1, snapshot 70, sidik jari identik dengan referensi pasca-koreksi. |
| G-1 | P2 masih terpasang (G3a) | **PASS** | Ditolak. Data tidak berubah. |
| G-2 | Offset 3600 (G1) | **PASS** | Ditolak. Data tidak berubah. |
| G-3 | Kolom TIMESTAMP tambahan (G4) | **PASS** | Ditolak. Data tidak berubah. |
| G-4 | Anchor hanya 5 dari 6 (G5a) | **PASS** | Ditolak. Data tidak berubah. |
| G-5 | Epoch anchor tidak cocok dengan payload (G5b) | **PASS** | Ditolak. Data tidak berubah. |
| G-6 | Sesi aktif lain pada database (G6) | **PASS** | Ditolak. Data tidak berubah. |
| G-7 | Overflow TIMESTAMP di tengah transaksi, mode default (1292) | **PASS** | Rollback penuh. Kolom yang sudah diperbarui sebelumnya ikut kembali. |
| G-8 | Kasus G-7 dengan `--force` | **PASS** | rc 0, tetapi tidak ada yang tertulis. Berbeda dengan A3. |
| G-9 | Koreksi ulang (G2) | **PASS** | Ditolak. Ledger tetap 1 baris. |
| R1 | Revert setelah koreksi | **PASS** | Sidik jari identik dengan pra-koreksi. |
| R2 | Koreksi ulang setelah revert | **PASS** | Sidik jari identik dengan pasca-koreksi. |
| R3 | Revert setelah data berubah (V0) | **PASS** | Ditolak. Data tidak berubah. |
| R4 | Revert saat P2 masih terpasang (G3a) | **PASS** | Ditolak. Data tidak berubah. |
| C1 | Laravel tanpa `timezone`, data sudah terkoreksi | **HAZARD (terukur)** | `finalized_at` id 9 terbaca `2026-11-22 20:10:20`. Seharusnya `13:10:20`. Mengonfirmasi bahwa config harus di-deploy bersamaan. |
| C2 | Laravel dengan `timezone => '+00:00'` | **PASS** | Terbaca `2026-11-22 13:10:20`, sama dengan payload. |
| M1 | Migrasi ulang P2 di config UTC | **PASS** | 6 DONE. Trigger 4. CHECK 21. `period_month` 10/10 sesuai WIB. Baca Laravel = payload. |
| M2 | Fixture batas WIB di UTC (5 kasus, termasuk 29 Feb 2028) | **PASS** | 5/5 sesuai. |
| M3 | Fixture batas WIB dengan urutan salah (SYSTEM) | **HAZARD** | 2 dari 5 salah (id 101 dan 103). Mengonfirmasi bahwa urutan wajib. |
| B-A | Gagal sebelum COMMIT, lalu migrasi ulang di SYSTEM | **PASS** | Trigger 4. `period_month` 10/10. |
| B-B | Revert, lalu migrasi ulang di SYSTEM | **PASS** | `period_month` 10/10. |
| CMP | Perbandingan baris: `joyumkm_p2_ref` (pra-koreksi, tidak diubah) vs lab (pasca-koreksi + migrasi UTC). 21 tabel. | **PASS** | 0 mismatch. 1.965 nilai TIMESTAMP bergeser +25200, atau tetap NULL. Nama migration identik (19). Trigger 4 = 4. CHECK 21 = 21. |
| TST | Regresi MySQL: 10 kelompok filter, 132 test pada `joyumkm_testing` | **PASS** | Dijalankan dengan config SYSTEM (repo belum berubah). Wajib diulang setelah config UTC (S10). |

Catatan: fixture batas (M2, M3) adalah data sintetis di atas klon. Data dev sendiri tidak memiliki kasus batas bulan.

Log lengkap di `C:\Users\itsba\joyumkm-backups\p2-post-migration-20261123-233223\lab\`.

---

## 7. Runbook recovery post-P2 (eksekusi dev)

Prasyarat (semua wajib, lihat §9): persetujuan owner, dump baru terverifikasi, rehearsal ulang pada klon dari dump baru **lulus**, dan config UTC siap di-deploy.

Setiap langkah berhenti jika gerbangnya gagal.

| Langkah | Tindakan | Lolos bila |
|---|---|---|
| S0 | Hentikan queue worker dan scheduler. Jalankan `php artisan down`. | Tidak ada proses worker yang berjalan. |
| S1 | Dump baru dengan `mysqldump --tz-utc --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF joyumkm-v2 \| gzip -9`. Verifikasi `gzip -t` dan SHA-256. Catat sidik jari. | Dump dan checksum tersimpan. |
| S2 | **Gerbang data P2 kosong.** `intelligence_artifacts`, `intelligence_deliveries`, dan `email_suppressions` = 0 baris. `umkms.notification_consent_at` non-null = 0. `intelligence_reports.artifact_status <> 'pending'` = 0. | Semua nol. **Jika tidak: STOP.** Rollback akan menghapus data tersebut. Rancang preservasi lebih dulu. |
| S3 | `php artisan migrate:status`: pastikan 6 P2 batch 7 dan enam migration terakhir adalah P2. Lalu `php artisan migrate:rollback --step=6`. | Exit 0. 13 migration tersisa. |
| S4 | Verifikasi pasca-rollback: trigger 0, TIMESTAMP 36 termasuk `failed_at`, tabel P2 tidak ada. | Sesuai. |
| S5 | Buat tabel helper dan loader anchor. Pisahkan file menjadi dua bagian dan jalankan bagian 1 saja (lihat perintah di bawah). Lalu periksa anchor. | Anchor = 6. Setiap `expected_epoch` cocok dengan payload. |
| S6 | Pemeriksaan baca-saja sebelum CALL (§7.1). | Semua sesuai. |
| S7 | Jalankan bagian 2 (procedure dan `CALL`). **Mode default, tanpa `--force`.** | Tidak ada `ERROR`. Ledger = 1. Snapshot = 70 baris. |
| S8 | Deploy `config/database.php`: tambahkan `'timezone' => '+00:00',` pada blok `mysql`, setelah baris `'collation' => …`. Lalu `php artisan config:clear`. | Verifikasi di §7.1. |
| S9 | `php artisan migrate` (enam P2 di UTC). | 6 DONE. Trigger 4. `period_month` 10/10 sesuai WIB. |
| S10 | Verifikasi pasca: selisih anchor 0; baca Laravel id 9 = `2026-11-22 13:10:20`; `php artisan test` untuk suite regresi MySQL (§6, TST). | Semua lulus. |
| S11 | `php artisan up`. Nyalakan kembali worker dan scheduler. Laporkan angka ke owner. | — |

**Perintah S5 (pemisahan file, diuji di L3):**
```bash
sed -n '1,/^-- 2\. Procedure/p'        p2-timezone-correction-post-p2.sql > part1-loader.sql
sed -n '/^-- 2\. Procedure/,$p'        p2-timezone-correction-post-p2.sql > part2-procedure-call.sql
mysql --defaults-extra-file=<berkas-kredensial> -D joyumkm-v2 < part1-loader.sql
```
Jangan menjalankan berkas penuh sekaligus di langkah ini. Berkas penuh juga memuat CALL, sehingga koreksi langsung berjalan.

### 7.1 Pemeriksaan baca-saja (S6)

```sql
SET SESSION time_zone = 'SYSTEM';
SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_s;                              -- harus 25200
SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE();             -- harus 0
SELECT COUNT(*) FROM timezone_offset_anchors WHERE run_key = 'p2-utc-2026-11';                  -- harus 6
SELECT COUNT(*) FROM information_schema.PROCESSLIST
 WHERE DB = DATABASE() AND ID <> CONNECTION_ID() AND COMMAND <> 'Sleep';                        -- harus 0
```

Verifikasi S8 (config), tanpa menampilkan secret:
```bash
php artisan tinker --execute 'echo DB::selectOne("select @@session.time_zone as tz")->tz;'     # +00:00
php artisan tinker --execute 'echo DB::table("six_factor_evaluations")->where("id",9)->value("finalized_at");'   # 2026-11-22 13:10:20
```

---

## 8. Cabang kegagalan

| Kondisi | Keadaan data | Tindakan | Teruji |
|---|---|---|---|
| **A.** Gerbang atau SQL gagal sebelum COMMIT. | Tidak berubah (procedure ROLLBACK). P2 sudah di-rollback. | Config tetap SYSTEM. `php artisan migrate` untuk menerapkan ulang P2 di SYSTEM. Ini konsisten karena data belum terkoreksi. Periksa 4 trigger dan `period_month` 10/10. Lalu `php artisan up`. | B-A |
| **B.** COMMIT berhasil, tetapi config UTC tidak bisa di-deploy atau migrasi UTC gagal. | Sudah terkoreksi. Config SYSTEM = **campuran** (salah +7 jam). | Pilihan 1, jika config bisa segera di-deploy: lanjutkan S8–S9. Pilihan 2: `php artisan migrate:rollback --step=6`, lalu revert (`p2-timezone-revert-post-p2.sql`), lalu `php artisan migrate` di SYSTEM. | R4 (revert ditolak bila P2 masih terpasang), B-B |
| **C.** Migrasi UTC gagal di tengah (DDL tidak transaksional). | Campuran. | Restore dari dump (lihat di bawah). Jangan memperbaiki secara manual. | Restore ke DB baru (A1) |
| **D.** Verifikasi pasca gagal (S10). | Sudah terkoreksi, config UTC. | Jangan `up`. Diagnosis dulu. Bila perlu kembali, ikuti pilihan 2 pada cabang B. | R1–R3 |

**Restore penuh (cabang C dan D).** Pulihkan dump ke database baru, bukan menimpa dev:
```bash
mysql -e 'CREATE DATABASE `joyumkm_v2_restore` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
gunzip -c joyumkm-v2-full.sql.gz | mysql -D joyumkm_v2_restore
```
Setelah owner menyetujui, arahkan `DB_DATABASE` ke `joyumkm_v2_restore`. Dengan cara ini `joyumkm-v2` yang rusak tetap utuh untuk forensik. Pola restore ke database baru sudah diuji (A1).

---

## 9. Checklist eksekusi dev

**Persetujuan owner (wajib sebelum apa pun di dev):**
- [ ] O1. Setujui runbook ini dan jendela pemeliharaan.
- [ ] O2. Setujui rollback enam migration P2 di dev. Ini mengubah skema dev dan belum pernah disetujui.
- [ ] O3. Setujui koreksi 35 kolom beserta dasar buktinya (6 anchor). Tambah anchor lain bila ingin bukti lebih kuat.
- [ ] O4. Setujui perubahan `config/database.php` sebagai PR. Perubahan ini **harus** di-deploy bersama COMMIT (S8).
- [ ] O5. Konfirmasi bahwa tidak ada pembaca eksternal epoch (BI, klien DB, skrip). Pembaca eksternal tidak ditemukan di repo, sehingga ini belum terkonfirmasi. Bila ada, beri tahu mereka sebelum S7.
- [ ] O6. Setujui penyimpanan backup (terenkripsi, lokasi terbatas).
- [ ] O7. Konfirmasi bahwa `joyumkm-v2` tidak dipakai pihak lain selama jendela.

**Sebelum S3:**
- [ ] Dump S1 dan checksum tersimpan.
- [ ] Rehearsal ulang pada klon dari dump S1: rollback, koreksi, config UTC, migrasi, dan CMP = PASS. **Wajib** karena data dev sudah berubah sejak 23:32 (lihat §2).
- [ ] Gerbang S2 nol.

**Saat eksekusi:** ikuti §7 secara berurutan dan catat keluaran setiap langkah.

**Setelah eksekusi:**
- [ ] Laporkan angka: jumlah kolom, delta, anchor, `period_month`, dan hasil TST.
- [ ] Hapus berkas kredensial sementara.
- [ ] Hapus klon lab setelah owner menyetujui (lihat §11).

---

## 10. Verdict

**Lab: GO.** Prosedur post-P2 teruji pada full clone. Prosedur ini fail-closed dan dapat dipulihkan. Syarat tambahan: rehearsal ulang pada dump terbaru harus menghasilkan CMP = PASS.

**Dev: NO-GO sekarang.** Belum ada persetujuan (O1–O7). Config UTC belum ada di repo. Pembaca eksternal belum dikonfirmasi. Data dev sudah berubah dari preflight karena migrasi P2 sudah berjalan.

Menjadi **GO** bila: O1–O7 disetujui, rehearsal ulang lulus pada dump terbaru, gerbang S2 bernilai nol, dan PR config siap di-deploy bersamaan dengan COMMIT.

---

## 11. Batasan dan item terbuka

- Regresi MySQL (TST) dijalankan dengan config SYSTEM karena repo belum berubah. Regresi dengan config UTC wajib diulang setelah S8 (S10).
- Kasus batas WIB adalah data sintetis di klon. Data dev tidak memiliki kasus batas.
- Lab hanya di MySQL 8.4.3 lokal. Server produksi bisa memiliki zona sistem lain. Gerbang G1 (offset terukur) memblokir eksekusi bila berbeda.
- Pembaca eksternal epoch belum dikonfirmasi (O5).
- Pepper `INTELLIGENCE_RECIPIENT_PEPPER` dan consent (K4) di luar cakupan dokumen ini. Tetap terbuka sesuai preflight §6–7.
- Verdict P2-05 tetap PARTIAL sampai owner menerima runbook ini.

**Klon lab untuk review** (dihapus hanya atas persetujuan): `joyumkm_p2_lab`, `joyumkm_p2_ref`, `joyumkm_p2_lab_neg`, `joyumkm_p2_t_*` (termasuk `joyumkm_p2_t_bound` dan `joyumkm_p2_t_boundsys`). `joyumkm_tz_lab` dari preflight tidak disentuh.
