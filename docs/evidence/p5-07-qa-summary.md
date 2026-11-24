# P5-07 · Bukti QA terarah

QA 24 November 2026 17:44:04 UTC sampai 17:58:08 UTC, sekitar **14 menit 4 detik** termasuk setup, rerun, smoke, dan upaya cleanup. Waktu setara 25 November 2026 00:44–00:58 WIB. Staging Windows yang tertahan dicatat pada AC-15.

| Gate | Hasil | Bukti |
|---|---|---|
| AC-01 | PASS | Golden vector PHP/Python identik. FastCGI aktual dari Hermes memberi 201, 200 duplicate, dan ack yang divalidasi terhadap revisi, digest, serta hash. |
| AC-02 | PASS | HMAC salah/kosong, body berubah, timestamp ±301 detik, config hilang, JSON rusak/key ganda, query, metode GET, trailing slash, dan limit 16 KiB ditolak. Probe Apache trailing slash 400 dan oversize 413. |
| AC-03 | PASS | Unknown/legacy event, mismatch UMKM di outbox, kanal lain, dan perubahan hash laporan/manifest ditolak. |
| AC-04 | PASS | Dua POST FastCGI paralel memberi 201 + 200. Setelah ack kreator disimulasikan hilang, retry identik 200 duplicate. PHPUnit membuktikan satu row saat fingerprint kosong berubah ke nilai pinned. |
| AC-05 | PASS | Lower revision stale tanpa write, same revision digest berbeda conflict, terminal regression dan attempt turun ditolak, retry ke queued hanya pada attempt lebih tinggi, dan hasil terminal bisa tiba pertama. |
| AC-06 | PASS | Fake clock 600/1800/5400 detik, empat slot, satu resume operator terbatas. Satu fake SMTP dan hash file outcome identik setelah retry, tanpa AI atau render. |
| AC-07 | PASS | Hermes mencatat sent dengan fake SMTP dan dua lampiran identik. FastCGI memberi 409 sebelum report, lalu menerima setelah report+manifest saat kedua upload masih pending. |
| AC-08 | PASS | Proses Python pengganti melanjutkan snapshot yang tersimpan, lost ack menjadi duplicate, dan matching ack untuk outcome lama tidak mengakui outcome baru. |
| AC-09 | PASS | SMTP fault tests dan state terminal ambiguous, sync ke status `delivery_unknown`, proyeksi UI, dan terminal tanpa submit ulang diperiksa. |
| AC-10 | PASS | Fingerprint kosong untuk skip/block, alasan suppression aman, perubahan penerima dengan fingerprint lama, dan status sync tanpa recipient pull. Label consent diperiksa pada browser dengan respons simulasi. |
| AC-11 | PASS | FastCGI status 200 pemilik, 404 lintas UMKM, 401 anonim. Proyeksi status tidak memuat identitas SMTP. Regresi download P4 lulus. |
| AC-12 | PASS | Chrome 1440 dan 390 px dengan status sent dari API nyata, waktu WIB, dan tanpa overflow. Respons simulasi untuk retry/unknown/skipped/changed/blocked/legacy serta loading, error, dan retry baca-saja. Vite lulus dengan warning ukuran chunk chart. |
| AC-13 | PASS | Fresh, down/up, upgrade dari restore dump dev, dan regresi P2/P4. Digest riwayat cocok. Job tanpa marker sync dilewati. |
| AC-14 | PASS | Secret dedicated cocok/berbeda diverifikasi privat. ACL Windows terproteksi dan mode Hermes 0600. Signed dev unknown event 404 tanpa penulisan. Rollout tetap off. |
| AC-15 | PARTIAL | Backup/restore, migrasi, hash runtime, durasi, dan inventaris Docker lulus. Review otomatis menolak penghapusan folder staging Windows. |

## Perintah dan hitungan

- `php vendor/bin/phpunit tests/Feature/IntelligenceDeliveryStatusTest.php tests/Feature/IntelligenceDeliverySchemaTest.php tests/Feature/IntelligenceArtifactDownloadTest.php` pada server MySQL terisolasi, **14 tes, 152 assertions PASS**.
- `python -m unittest discover -s tests -p test_email_status_sync.py`, **6 PASS** pada volume Hermes. Dua regresi `SMTPTests` dan tiga `RecipientTests` pada `test_email.py` juga PASS. Semua transport SMTP yang digunakan uji bersifat palsu.
- Pint file PHP yang disentuh, `git diff --check` dan `npm run build` PASS. Chrome menggunakan backend FastCGI QA untuk status sent, dengan simulasi tambahan terbatas pada browser.
- Inventaris sebelum/sesudah sama, 16 kontainer. QA MySQL `82e4a85d4fde` dihapus. Listener 18056, 19256, dan 33317 sudah tertutup. Tidak membuat network tambahan. Screenshot sintetis dipertahankan di backup privat.

## Hash deploy SHA-256

| File | Hash |
|---|---|
| `jmi/email_delivery.py` | `0220fa4393805116471b824a0248cb89f922478c055a4d11543187963f5f080b` |
| `jmi/email_status_sync.py` | `59af90d9b55b121a37df7eb4cca501c7ebf481c4de1a165ed1944ef6b4310fdc` |
| `jmi/engine.py` | `eee61af2a2cc6b113228110675795593364bb50a90900815d39a7ba995bebfa1` |
| `jmi/__main__.py` | `6bba7d53b1a8b6d3b3e0d2ec7e069011742615ecfbfedd771e6f2e23e5c9312d` |
| `tests/test_email_status_sync.py` | `b4cc324800b60b811913d544858b0eeb2a6fc63f77210c3498b3c6bdb5dde8e2` |
| Dump privat dev | `991e50aa1ffc26d39cdee54f01d5d8dd08dacc3ae2a09669d6d0d18593a4288d` |

**NOT RUN** Gmail AUTH/send, inbox delivery, AI nyata, worker/scheduler live dispatch, dan host nonlokal. Registrasi recovery existing ditemukan satu kali. Worker dan scheduler lokal tidak dijalankan.
