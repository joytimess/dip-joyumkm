# P3-01 — Data Preparation & Historical Snapshot (JoyUMKM × Hermes)

Tanggal: 2026-11-24
Verdict: **PASS** untuk lingkup P3-01. Semua test baru hijau. Tidak ada regresi berdasarkan perbandingan identitas kegagalan. Batas yang belum diuji dilist di bagian 9.

Kontrak data: `docs/evidence/p3-01-data-contract.md`
Bukti test dan regresi: `docs/evidence/p3-01-test-regression.md`
Sampel snapshot dan manifest: `docs/evidence/p3-01/`

---

## 1. Hasil ringkas

| Item | Hasil |
|---|---|
| Endpoint pull historis Laravel | Dibuat. Read-only, HMAC, tanpa PII. |
| Service histori (`IntelligenceSnapshotService`) | Dibuat. Cutoff, WIB, 12 bulan, data gap = null. |
| Validator, builder, dan committer Hermes (`jmi/snapshot.py`) | Dibuat. Fail closed, atomik, checksum SHA-256. |
| Test baru | Laravel: 23 (11 service, 11 API, 1 vektor silang). Hermes: 53 (`test_snapshot`). |
| Regresi Laravel | 0 identitas baru. 412→435 test; 407→430 lulus; 3 FAIL + 2 ERROR pra-existing tidak berubah. |
| Regresi Hermes | 0 identitas baru. 51 identitas unik sebelum dan sesudah. |
| `HERMES_WEBHOOK_ENABLED` | Tetap `false` (dicek di `.env`). |
| Webhook nyata, backlog outbox, model AI, PDF, Excel, email | Tidak dijalankan. |
| Commit / push | Tidak dilakukan. |
| P3-02 | Tidak dimulai. |

## 2. Keputusan

**Transport: pull HTTP terautentikasi, bukan histori di webhook.**
- Payload v2 dibekukan saat finalisasi dan dipakai sebagai identitas (`payload_hash`). Menambah histori di sana menggandakan ukuran payload dan tidak bisa menjangkau event yang sudah terkirim.
- Hermes tidak membaca database Laravel secara langsung. Kredensial database tidak diberikan ke Hermes.
- Endpoint: `GET /api/internal/intelligence/events/{eventId}/snapshot`. Secret terpisah dari callback dan webhook. Signature mencakup path, sehingga satu signature tidak berlaku untuk event lain.

**Cutoff dan periode.**
- Cutoff = `finalized_at` UTC dari penilaian Enam Faktor anchor event.
- Periode = bulan WIB (`Asia/Jakarta`). Bulan sebelumnya = bulan kalender tepat sebelumnya.
- Layanan P2 (`SixFactorMonthlyRecapService`) diperluas dengan parameter cutoff opsional. Pemanggil P2 tidak berubah dan P2 tetap PASS.

**Tiga Dimensi (V1) dipisah.**
- Dipilih menurut `COALESCE(submitted_at, created_at)` dan dibatasi `<= cutoff`.
- Tidak ada `finalized_at` buatan untuk V1.

**Provenance profil jujur.**
- Profil berasal dari `business_context` pada event. Tidak diklaim sebagai as-of historis (`historical_as_of_supported: false`), karena perubahan profil tidak disimpan.

**Snapshot adalah tahap CLI eksplisit.**
- `python -m jmi snapshot <event_id>` dan `snapshot-verify <event_id>`. Tidak dipasang ke `intake` atau `recover`. Pemulihan dilakukan dengan menjalankan ulang perintah.
- Alasannya: intake dan recover sudah dipakai jalur callback dan proses lain. Menyambungkan snapshot ke sana berisiko mengubah perilaku yang sudah PASS. Ini bisa dijadwalkan atau dipasang di tahap berikutnya setelah owner menyetujui.

**Manifest sebagai commit marker.**
- `snapshot.json` ditulis lebih dulu, `snapshot_manifest.json` terakhir. Keberadaan manifest yang valid dengan SHA-256 yang cocok berarti snapshot lengkap.

**Event v1 = unsupported.**
- Event v1 tidak punya snapshot historis. Manifest `snapshot_status: unsupported`, `reason: LEGACY_EVENT_VERSION`, dan tidak ada pull.

**Guardrail P4-04C dipertahankan.**
- Hanya `jmi/callback.py` yang boleh mengimpor modul jaringan. `http_get` ditambahkan ke sana. `snapshot.py` tidak mengimpor modul jaringan. Test guardrail tidak diubah.

## 3. Lokasi persisten (Hermes)

- Volume Docker: `hermes_data`, path dalam container `/opt/data/joyumkm-intelligence/`.
- Job: `state/jobs/<event_id>/snapshot.json` dan `state/jobs/<event_id>/snapshot_manifest.json`.
- Lock: `state/locks/<event_id>.snap` (blocking, satu proses per event).
- Snapshot yang rusak dipindahkan ke `*.invalid-<ns>.json` untuk forensik, lalu dibangun ulang.

## 4. Konfigurasi

| Sisi | Variabel | Catatan |
|---|---|---|
| Laravel `.env` | `HERMES_INTELLIGENCE_SNAPSHOT_SECRET` | 64 hex, dibuat lokal. Nilainya **tidak** ditulis di dokumen. Minimal 32 karakter dan tidak boleh sama dengan secret callback atau webhook. |
| Laravel `.env` | `HERMES_INTELLIGENCE_SNAPSHOT_TOLERANCE_SECONDS` | Default 300. |
| Laravel `.env.example` | kedua variabel di atas | Dicantumkan tanpa nilai rahasia. |
| Hermes `state/snapshot.env` (mode 0600) atau environment | `INTELLIGENCE_SNAPSHOT_BASE_URL` | Mis. `http://host.docker.internal:8000/api/internal/intelligence/events` |
| Hermes `state/snapshot.env` (mode 0600) atau environment | `INTELLIGENCE_SNAPSHOT_SECRET` | Harus sama dengan Laravel. |

- Setelah mengubah `.env` Laravel jalankan `php artisan config:clear`. Tidak ada cache konfigurasi di `bootstrap/cache` saat ini.
- **Belum dibuat:** `snapshot.env` di volume live. Keputusan ada di owner karena menyangkut secret. Lihat bagian 9.

## 5. Penjelasan baseline (kejujuran angka)

- Handoff P2 mencatat 48 FAIL dan 25 ERROR. Pengukuran ulang di lingkungan ini (container `d6746a084e93`, volume salinan) menghasilkan **55 FAIL dan 27 ERROR**, dengan **51 identitas unik**.
- Pengukuran sebelum dan sesudah P3-01 menghasilkan daftar identitas yang sama persis. Kesimpulan regresi berdasarkan identitas, bukan jumlah.
- Laravel: baseline 3 FAIL + 2 ERROR (`DemoSeederTest`, `SampleDataSeederTest`), sama sebelum dan sesudah.

## 6. Berkas yang berubah

### Laravel (`C:\laragon\www\joyumkm`)

Diubah:
- `app/Services/SixFactorMonthlyRecapService.php`: parameter cutoff opsional, `asOfEvaluation()`, `periodsEndingAt()`, `sqlUtc()`. Perilaku P2 tidak berubah.
- `config/services.php`: blok `hermes_intelligence_snapshot`.
- `routes/api.php`: route GET snapshot dengan middleware.
- `.env.example`: dua variabel baru.

Baru:
- `app/Http/Controllers/Api/IntelligenceSnapshotController.php`
- `app/Http/Middleware/VerifyIntelligenceSnapshotSignature.php`
- `app/Services/IntelligenceSnapshotService.php`
- `tests/Feature/IntelligenceSnapshotServiceTest.php` (11 test)
- `tests/Feature/IntelligenceSnapshotApiTest.php` (11 test + vektor silang = 12, lihat catatan di bawah)
- `tests/Fixtures/intelligence-snapshot/pull-response.sample.json`
- `docs/evidence/p3-01-data-contract.md`
- `docs/evidence/p3-01-test-regression.md`
- `docs/evidence/p3-01/` (sampel JSON dan daftar identitas)
- `docs/audit/P3-01_JoyUMKM_Historical_Snapshot.md` (berkas ini)

Catatan jumlah: `IntelligenceSnapshotApiTest` berisi 12 test (11 awal + 1 vektor silang), sehingga total baru Laravel 23 (11 + 12).

Tidak disentuh: tujuh `archived_unmatched`, tiga historical report, `.env` (git-ignored; hanya ditambah satu variabel), P2 `IntelligenceCallbackController`, dan `HermesWebhookOutbox`.

### Hermes (`/opt/data/joyumkm-intelligence/` pada volume `hermes_data`)

Baru:
- `jmi/snapshot.py`
- `tests/test_snapshot.py` (53 test)
- `tests/fixtures/p3-01/pull-response.sample.json` (salinan identik dari Laravel)

Diubah:
- `jmi/callback.py`: tambah fungsi `http_get` (tanpa redirect, pembacaan terbatas). Tidak ada perubahan perilaku lain.
- `jmi/store.py`: pola lock diperluas ke `.snap`.
- `jmi/__main__.py`: perintah `snapshot` dan `snapshot-verify`.

Backup kode sebelumnya: `backups/jmi-pre-p3-01-20261124083643.tar.gz`.

## 7. Cara menjalankan (operator)

```text
# di Laravel, setelah mengisi secret di .env
php artisan config:clear

# di Hermes, setelah membuat state/snapshot.env (mode 0600)
docker exec <container> python3 -m jmi snapshot <event_id>
docker exec <container> python3 -m jmi snapshot-verify <event_id>
```

- Kode keluar: `0` untuk `created`, `exists`, atau `unsupported`. `1` untuk kegagalan yang bisa dicoba ulang. `3` untuk kegagalan permanen.
- Tidak ada model AI yang dipanggil. Tidak ada webhook. Tidak ada email.

## 8. Yang dilakukan dan tidak dilakukan

Dilakukan:
- Implementasi Laravel dan Hermes sesuai kontrak.
- Test unit, integrasi, dan kontrak. Vektor silang Python→PHP.
- Perbandingan identitas kegagalan sebelum dan sesudah.
- Sinkronisasi enam file ke volume live dengan verifikasi SHA-256.
- Sampel snapshot dan manifest dari fixture sintetis.

Tidak dilakukan (disengaja):
- Container `hermes-agent` tidak dijalankan. Entrypoint-nya menjalankan `gateway run` dan cron `jmi-recover` yang bisa memanggil model.
- Tidak ada `snapshot.env` di volume live.
- Tidak ada webhook, backlog, model AI, PDF, Excel, email, atau migration.
- Tidak ada commit atau push.

## 9. Batas dan pekerjaan lanjutan

1. **Pull live belum diuji dari container Hermes.** Pull diuji dengan stub HTTP yang memverifikasi HMAC, dan endpoint Laravel diuji lewat test HTTP Laravel. Pengujian end-to-end memerlukan container dan `snapshot.env`, yang menunggu persetujuan owner.
2. **`snapshot.env` belum dibuat di volume live.** Ini menyangkut secret. Owner perlu membuat file (mode 0600) dengan nilai yang sama dengan `.env` Laravel.
3. **Profil hanya as-of event.** Histori perubahan profil tidak tersedia. `profile.historical_as_of_supported` bernilai `false`.
4. **Snapshot belum terhubung ke intake atau recover.** Pemulihan dilakukan dengan menjalankan ulang `snapshot`. Menyambungkannya perlu keputusan owner.
5. **Perbaikan tamper.** Snapshot yang diubah manual terdeteksi oleh `snapshot-verify` dan dipindahkan saat `snapshot` dijalankan ulang. Tidak ada tanda tangan kriptografis pada file itu sendiri, hanya checksum.
6. **Lingkungan baseline.** Jumlah kegagalan Hermes (55/27) tidak sama dengan handoff P2 (48/25). Angka P2 tidak direproduksi di sini. Perbandingan regresi memakai identitas, sehingga kesimpulan tetap berlaku.
7. **Folder `docs/evidence/p3-01/` sudah berisi berkas untracked sebelum sesi ini.** Isi itu tidak dibaca sebelum berkas baru ditulis ke sana. Berkas baru memakai nama sampel dan daftar identitas. Jika ada berkas lama dengan nama yang sama, isinya sudah tertimpa dan tidak bisa dipulihkan dari repo. Owner perlu memeriksa riwayat folder itu.
8. **Salinan kerja sementara di scratchpad** memuat `state/callback.env` (secret). Dihapus setelah dokumen ini selesai.

## 10. Pemetaan ke 19 skenario QA

Lihat `docs/evidence/p3-01-test-regression.md` bagian D.
