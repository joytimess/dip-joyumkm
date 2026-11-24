# P2 — Data Contract JoyUMKM × Hermes

Status: dokumen kontrak untuk review owner. Dokumen ini menjelaskan bentuk data yang dihasilkan oleh migrasi dan layanan P2. Tidak ada endpoint, pengiriman email, atau webhook baru yang diaktifkan.

## 1. Event webhook versi 2 (tambahan opsional)

Kontrak tetap `event_version: 2`. Perubahan bersifat additive.

| Path | Tipe | Keterangan |
|---|---|---|
| `data.six_factor.period_month` | `string` `YYYY-MM` atau tidak ada | Bulan periode menurut WIB (`Asia/Jakarta`) dari `finalized_at`. Hanya ada pada event yang dibuat setelah migrasi P2. Event lama tidak di-backfill di payload. |

Aturan:
- Konsumen wajib memperlakukan `period_month` sebagai opsional.
- Hermes saat ini mengabaikan field ini karena parser hanya membaca field yang dikenal (`docs/HERMES_WEBHOOK.md`).
- Payload tidak berisi email, nama pemilik, nama PIC, atau nomor WhatsApp. Ini diverifikasi oleh `IntelligenceRecipientConsentTest::test_outbox_snapshot_and_no_mail_keep_contact_details_out_of_the_event`.

## 2. Periode bulanan (WIB)

- `six_factor_evaluations.period_month` berformat `CHAR(7)`, diisi hanya untuk status `final`, dan dihitung dari `finalized_at` dengan zona `Asia/Jakarta`.
- Kolom `TIMESTAMP` disimpan dalam UTC. Koneksi MySQL Laravel memakai `timezone => '+00:00'` (`config/database.php`). Offset 7 jam pada data historis (T-1) sudah dikoreksi pada 35 kolom bersama config tersebut. Periode tetap dihitung dalam WIB.
- Rekap bulanan memilih Final terbaru per bulan menurut `finalized_at` lalu `id` terbesar. Semua Final tetap disimpan sebagai histori.
- Bulan tanpa Final menghasilkan `status: data_gap`, bukan nol.
- V1 Tiga Dimensi memakai aturan waktu yang ada: `submitted_at`, cadangan `created_at` untuk data lama. V1 tidak diberi `finalized_at` buatan.

## 3. Manifest artefak

Sumber kebenaran: `App\Services\IntelligenceArtifactContract`.

- Manifest bersifat opsional. `null` dinormalisasi menjadi daftar kosong.
- Maksimum 2 entri. `kind` unik. Urutan kanonik: `pdf`, lalu `xlsx`.
- `kind` hanya `pdf` atau `xlsx`. MIME harus sesuai: `application/pdf` atau `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
- `size_bytes` bilangan bulat 1 sampai 10 MiB (10485760).
- `sha256` 64 karakter hex huruf kecil.
- Field tidak dikenal diabaikan dan tidak ikut disimpan.
- Hash manifest (`setHash`) adalah SHA-256 dari JSON hasil normalisasi. Urutan input tidak memengaruhi hash.
- Penyimpanan: disk privat `local`. Binary tidak pernah masuk database. Tabel `intelligence_artifacts` menyimpan `storage_disk`, `storage_path`, MIME, ukuran, dan SHA-256 saja.
- Unduhan dilakukan lewat endpoint GET privat (K10), belum dibangun di P2.

## 4. Pengiriman (`intelligence_deliveries`)

Saluran: `artifact`, `email`.

Status: `queued`, `sent`, `failed_retryable`, `failed_permanent`, `skipped_ineligible`.

Aturan yang ditegakkan di database:
- Saluran `artifact` selalu memakai `recipient_fingerprint = ''` dan tidak pernah `skipped_ineligible`.
- Saluran `email` wajib memakai fingerprint 64 hex, kecuali baris `skipped_ineligible` tanpa alamat.
- `sent` wajib memiliki `sent_at`.
- `failed_*` dan `skipped_ineligible` wajib memiliki `error_code`.

Kunci idempotensi:

```
idempotency_key = sha256("intelligence|" + lower(event_id) + "|" + channel + "|" + recipient_fingerprint + "|" + artifact_set_hash)
```

Unik di `idempotency_key` dan di kombinasi `(event_id, channel, recipient_fingerprint)`. Kolom fingerprint memakai sentinel `''` agar unique index tetap aman terhadap NULL.

## 5. Kontrak pull-recipient untuk Hermes (dirancang, belum dibangun — P5)

Layanan: `App\Services\IntelligenceRecipientEligibilityService::evaluate(IntelligenceReport)`.

Hasil selalu berbentuk:

```json
{ "eligible": true, "reason": null, "recipient_fingerprint": "<64 hex>" }
```

`recipient_fingerprint` bersifat nullable. Nilainya 64 karakter hex untuk `eligible: true` dan `suppressed`. Nilainya `null` untuk `evaluation_not_final`, `no_consent`, `no_business_email`, dan `invalid_email`.

Urutan alasan saat tidak eligible (alasan pertama yang berlaku):

1. `evaluation_not_final`: evaluasi enam faktor belum final.
2. `no_consent`: `umkms.notification_consent_at` kosong. Default untuk UMKM lama adalah tidak ada persetujuan.
3. `no_business_email`: email usaha kosong.
4. `invalid_email`: format email tidak valid.
5. `suppressed`: alamat ada di `email_suppressions`.

Aturan:
- Respons tidak pernah berisi alamat email, nama pemilik, nama PIC, atau nomor WhatsApp. Fingerprint adalah HMAC-SHA256 atas email yang sudah dinormalisasi (trim + lowercase) dengan pepper `services.intelligence.recipient_pepper` (env `INTELLIGENCE_RECIPIENT_PEPPER`, minimal 32 karakter). Tanpa pepper yang valid, layanan melempar `RuntimeException`.
- Pepper tidak boleh diganti setelah dipakai untuk fingerprint. Penggantian memutus `email_suppressions` dan idempotensi. Rotasi hanya boleh dilakukan bersama prosedur hitung ulang fingerprint suppression, dan prosedur itu belum ada.
- Pull recipient (Hermes mengambil alamat untuk pengiriman) bukan bagian P2. Autentikasi (GET bertanda tangan dengan secret terpisah), batas PII, TTL, dan kode error ditetapkan dan diimplementasikan di P5. P2 tidak membuat endpoint pengambilan alamat.
- `email_suppressions` hanya menyimpan fingerprint, alasan (`bounce`, `complaint`, `unsubscribe`, `manual`), dan sumber. Tidak ada alamat mentah.

## 6. Persetujuan notifikasi (`umkms`)

- `notification_consent_at` (timestamp, nullable).
- `notification_consent_version` (string, nullable).
- `notification_consent_by_user_id` (FK users, nullable, `nullOnDelete`).
- CHECK: `notification_consent_at` dan `notification_consent_version` harus sama-sama NULL atau sama-sama terisi.
- Nilai default untuk UMKM yang sudah ada: tidak ada persetujuan.
- Persetujuan terikat ke alamat penerima. Penggantian `business_email` mencabut ketiga kolom persetujuan di atas (`App\Models\Umkm`, event `updating`). Persetujuan yang dicatat dalam penyimpanan yang sama dengan alamat baru tetap berlaku. Ditegakkan oleh `IntelligenceRecipientConsentTest`.
- Penulisan lewat query builder (`DB::table`) tidak melewati event model dan tidak mencabut persetujuan. Jalur tulis aplikasi wajib memakai model.
- Alur pemberian persetujuan (siapa yang mencatat dan bagaimana) belum didefinisikan. Ini keputusan owner sebelum P5 (gap A pada preflight).

## 7. Penyedia email P5

- Penyedia yang dipilih untuk P5: Gmail. Pemilihan ini hanya menentukan penyedia. Kontrak pengiriman dan pull tetap mengikuti §4 dan §5.
