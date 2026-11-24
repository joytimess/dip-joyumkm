# QA P5-01 dan P5-02

**Verdict lokal: PASS.** Setup, pemeriksaan dan rerun berlangsung sekitar 12 menit wall-clock pada 24 November 2026 WIB, termasuk pemeriksaan konfigurasi. Cleanup tiap percobaan dijalankan langsung, dengan pemeriksaan akhir sekitar 3 detik. Batas kumulatif QA adalah 15 menit.

| Gate | Hasil | Bukti |
| --- | --- | --- |
| AC-01 | PASS | Signed GET 200 dari Hermes ke QA Apache/FastCGI. Alamat dan fingerprint cocok secara boolean, identitas sesuai, TTL 60 detik, dan no-store. |
| AC-02 | PASS | HTTP aktual menolak signature salah 401 dan path dengan trailing slash 400. PHPUnit menolak signature hilang, path tertukar, timestamp kedaluwarsa atau terlalu jauh di masa depan, query, body, HEAD, POST dan OPTIONS. |
| AC-03 | PASS | Apache dev mengembalikan signed unknown 404 dengan body aman. Tes event v1 juga menghasilkan 404. |
| AC-04 | PASS | Query pemilih UMKM ditolak, payload dengan UMKM yang berbeda menghasilkan 409. |
| AC-05 | PASS | Grant owner mencatat UTC, versi dan actor server. UMKM baru default inactive. |
| AC-06 | PASS | Revoke berulang membersihkan metadata. Pull aktual setelah revoke mengembalikan 200 `no_consent` tanpa kontak. |
| AC-07 | PASS | MySQL menguji grant lama setelah alamat berubah, grant baru dalam update email, serta update PIC yang mempertahankan consent. |
| AC-08 | PASS | P2 dan jalur pull menggunakan urutan eligibility yang sama. Tes P2 mencakup alamat kosong dan invalid. Pull untuk evaluasi Draft menghasilkan `evaluation_not_final`. |
| AC-09 | PASS | Suppression tetap menolak alamat dengan consent aktif dan menang sebelum testing allowlist. |
| AC-10 | PASS | Exact match setelah normalisasi memberi akses. Alamat lain ditolak dan allowlist kosong menutup akses dengan 503. |
| AC-11 | PASS | Tes P2 memeriksa payload outbox bebas data kontak. Probe hanya mencatat status dan hasil cocok. Respons ineligible memakai exact shape tanpa PII. Pemeriksaan log lokal terbaru tidak menemukan secret recipient atau alamat allowlist. Report dan manifest P4 tidak ditulis oleh alur ini. |
| AC-12 | PASS | Signed GET berhasil pada event sintetis tanpa report atau upload P4. |
| AC-13 | PASS | Edge 390 px menguji default unchecked, grant, pesan reset saat email berubah, grant dengan alamat baru, dan revoke. Pemeriksaan DOM tidak menemukan overflow horizontal. PHPUnit menolak guest dan akun lain. |
| AC-14 | PASS | Secret dedicated cocok, berbeda dari secret lain dan disimpan dengan izin privat. Secret pendek, pepper tidak valid dan mode uji invalid menghasilkan 503. Nilai runtime SMTP dan pepper Laravel tetap sama. |
| AC-15 | PASS | QA memakai data sintetis. Tidak ada SMTP, AI, P4 regeneration, live dispatch, replay, atau perubahan data historis. |
| AC-16 | PASS | 42 tes terarah dan 469 assertions lulus. HTTP Docker, browser, Pint dan Vite lulus. Fixture serta resource temporer dibersihkan. |

## Lingkungan dan cleanup

- MySQL `joyumkm_testing` diperiksa sebelum fixture, dengan timezone UTC dan isolasi `REPEATABLE-READ`. Bootstrap uji hanya memakai schema yang sudah tersedia. Tes berjalan dalam transaksi dengan rollback.
- Probe berjalan dari `hermes-agent` menuju PHP lokal terisolasi lalu ke instance Apache/FastCGI Laragon terisolasi. Instance Apache mengonfirmasi akses token sintetis dari DB uji sebelum mutasi. Probe terpisah di Apache dev hanya menguji request tanpa data bisnis.
- Inventaris Docker sebelum dan sesudah sama. Ada **16 container** (2 aktif), **4 network**, dan **7 volume**. `hermes-agent`, `hermes-webhook-proxy`, `hermes_data` dan `joyumkm-p0-mysql` tetap tersedia. Harness memakai container Hermes existing.
- Setelah pengujian, DB QA menunjukkan nol baris di users, UMKM, evaluasi Enam Faktor, outbox, report, manifest, suppression dan token. Instance HTTP, listener, profil browser serta bootstrap temporer sudah dihapus. Percobaan awal sempat gagal pada framing helper PHPUnit, injeksi sesi browser, dan cleanup evaluator immutable. Harness diperbaiki dan baseline QA dipastikan kosong setelah rerun.
- Laravel `.env` dan backup privat hanya dapat diakses akun runtime, SYSTEM dan Administrators. Backup isi dan DACL ada di folder owner `%LOCALAPPDATA%/JoyUMKM-P5-Private-20261124-234712`. Hermes `state/recipient.env` mode 0600 dan milik runtime.
- Vite melaporkan satu chunk chart melebihi ambang peringatan ukuran. Pemeriksaan hosting nonlokal dan matriks konkurensi tambahan tidak dijalankan.
