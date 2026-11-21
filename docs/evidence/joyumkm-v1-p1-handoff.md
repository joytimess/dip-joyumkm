# Handoff JoyUMKM V1 P1

**20 November 2026 · P1 PASS · Suite PHP penuh masih memiliki lima kasus gagal dari baseline.**

E1–E6 meliputi pembacaan dan penyimpanan Draft, Final, Batal, histori, serta hasil terbaru. Aksi terminal menargetkan ID evaluasi dalam UMKM yang sama dan memakai urutan lock P0. DELETE menerjemahkan MySQL 1451 dari FK enam faktor menjadi 409 di controller. Pemanggil layanan internal tetap menerima error FK yang sama seperti P0. Kontrak dan contoh untuk P2 tersedia di [API.md](../API.md#penilaian-enam-faktor-p1).

## Kandidat dan lingkungan

- Basis kandidat `main@804738b0a39f9dc2658e40e92e796077d131aca6`. Ketujuh SHA-256 dari handoff P0 cocok sebelum implementasi. Manifest akhir mencakup perubahan tracked dan file untracked.
- Verifikasi menggunakan PHP 8.3.36, Laravel 13.40.0, Sanctum 4.3.3 dan MySQL 8.4.3. Tes menulis hanya pada `joyumkm_testing`. Proses HTTP paralel memeriksa database aktif sebelum memakai koneksi.
- Pemeriksaan `migrate:status` pada database aplikasi `joyumkm-v2` menunjukkan migration P0 sudah tercatat sebagai batch 2 sebelum implementasi P1. P1 memakai migration P0 yang sama.

## Hasil pengujian

| Pemeriksaan | Status |
|---|---|
| P1 HTTP | **PASS** · 63/63, 825 assertion. |
| P1 konkurensi HTTP pada MySQL | **PASS** · 6/6, 52 assertion. |
| Regresi P0 | **PASS** · 35/35, 355 assertion. |
| Fokus P0 dan P1 | **PASS** · 104/104, 1.232 assertion. |
| Suite PHP penuh | **FAIL baseline** · 197/202 lulus, 2.030 assertion, tiga kegagalan dan dua error lama. |
| Regresi JavaScript | **PASS** · 26/26. |
| Bentuk route, format, sintaks, dan whitespace | **PASS** · `route:list` menunjukkan enam route P1 dengan Sanctum dan dua route evaluasi V1. Pint, lint PHP kandidat, dan `git diff --check` lulus. |
| Review independen | **PASS** · Temuan tentang method override dan timestamp P0 yang nullable ditutup. Reviewer mengonfirmasi perbaikan dengan probe PHP. Eksekusi terarah setelah perbaikan lulus 12/12. |

Perintah verifikasi utama di PowerShell.

```powershell
$report = Join-Path ([IO.Path]::GetTempPath()) 'joyumkm-p1-verified-focused.xml'
php vendor/bin/phpunit --filter SixFactorEvaluation --log-junit $report
$report = Join-Path ([IO.Path]::GetTempPath()) 'joyumkm-p1-verified-full.xml'
php vendor/bin/phpunit --log-junit $report
npm test
php vendor/bin/pint --dirty --format agent
php artisan route:list --path=api/umkms -v --no-interaction
git diff --check
```

Perbandingan JUnit dengan `joyumkm-p0-baseline.xml` menemukan status yang sama pada seluruh 98 kasus lama. Lima kasus gagal cocok menurut nama dan jenis. Pada awal pekerjaan, `joyumkm-p1-start.xml` juga mencatat satu kegagalan tambahan di tes migration P0. Tes mengambil nama tabel dari semua skema dan tabel enam faktor juga sudah ada di skema aplikasi. Pemeriksaan metadata kini hanya membaca nama tabel di skema uji aktif. Tes tersebut lulus pada dua eksekusi akhir.

| Jenis | Kasus baseline | Gejala |
|---|---|---|
| FAIL | `DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores` | Empat akun ditemukan, ekspektasi satu. |
| FAIL | `DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` | Empat akun ditemukan, ekspektasi satu. |
| FAIL | `SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` | Empat evaluasi pada profil dengan batas tiga. |
| ERROR | `SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` | Argumen tes tidak disediakan. |
| ERROR | `SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` | Pesan assertion terbaca sebagai nama koneksi. |

## Bukti kriteria penerimaan

Seluruh baris mengacu pada kandidat dengan manifest di bawah.

| AC | Bukti | Status |
|---|---|---|
| 01 | Daftar route dan tes HTTP memeriksa metode, batas ID, token pada setiap endpoint, serta evaluasi V1. Tes memverifikasi body, header, dan query tidak dapat menimpa metode untuk P1. [Route](../../routes/api.php) · [Tes HTTP](../../tests/Feature/SixFactorEvaluationApiTest.php) | PASS |
| 02–04 | E1 kosong tanpa insert. PUT/PATCH memverifikasi 201/200 dan ID tetap, pembaruan parsial, null eksplisit, body kosong, objek kosong, serta snapshot identik setelah payload invalid saat create dan resume. [Tes HTTP](../../tests/Feature/SixFactorEvaluationApiTest.php) | PASS |
| 05–08 | Finalisasi diuji pada total 6, 12, 13, 18, 19, 24, 25, dan 30. E4 mempertahankan skor. Final parsial tetap Draft. Simpan setelah transisi menghasilkan ID baru. Aksi pada ID terminal mengembalikan 409 dan tidak menyentuh Draft baru. [Tes HTTP](../../tests/Feature/SixFactorEvaluationApiTest.php) · [Tes layanan](../../tests/Feature/SixFactorEvaluationServiceTest.php) | PASS |
| 09 | Histori kosong berbentuk array. Dua Final dengan timestamp sama diurutkan dengan ID terbesar, termasuk saat Final ketiga dibuat belakangan dengan timestamp lebih lama. E6 sama dengan E5[0]. Draft, Cancelled, dan Final UMKM lain tidak masuk histori. Respons dan baris fixture V1 tetap identik. [Tes HTTP](../../tests/Feature/SixFactorEvaluationApiTest.php) | PASS |
| 10–11 | Tanpa token dan token invalid mendapat 401. UMKM asing atau hilang mendapat 404 walau payload invalid. Evaluasi salah induk atau hilang mendapat 404 dan snapshot tetap sama. [Tes HTTP](../../tests/Feature/SixFactorEvaluationApiTest.php) | PASS |
| 12 | Tiga request save dengan koneksi independen menghasilkan 201/200/200, satu ID, dan ketiga skor tersimpan. Final/Final dan kedua urutan Final/Cancel menghasilkan satu 200 dan satu 409. Tes melihat query kompetitor menunggu lock induk di MySQL. [Tes konkurensi](../../tests/Feature/SixFactorEvaluationConcurrencyTest.php) | PASS |
| 13 | DELETE pada ketiga status mendapat 409. Tanpa riwayat, DELETE tetap 200 dan cascade V1 berjalan. Saat save menang, status 201/409 dan fixture V1 tetap ada. Saat DELETE menang, status 200/404 dan tidak ada baris yatim. Error FK lain tetap menggunakan 500 generik. [Tes HTTP](../../tests/Feature/SixFactorEvaluationApiTest.php) · [Tes konkurensi](../../tests/Feature/SixFactorEvaluationConcurrencyTest.php) · [Tes FK P0](../../tests/Feature/SixFactorEvaluationDatabaseTest.php) | PASS |
| 14–15 | Tes memeriksa nama dan tipe field, timestamp UTC enam digit, kolom nullable P0, 422 per field, pesan konflik, serta 500 generik meski debug aktif. Kontrak, contoh request, hasil kosong, Final, dan error tersedia untuk P2. [Tes HTTP](../../tests/Feature/SixFactorEvaluationApiTest.php) · [Kontrak P2](../API.md#penilaian-enam-faktor-p1) | PASS |
| 16–17 | P1 dan P0 lulus pada kandidat yang sama. Nama dan jenis lima masalah baseline identik. Review independen selesai dan regresi untuk kedua temuannya lulus. | PASS |

## Identitas kandidat

SHA-256 manifest `6b29523e414d95a3076269f32a2bc9412339ca5c54dea57e65c2e9d38fa6a250`. Bentuk yang di-hash adalah satu baris `<sha256 huruf kecil>  <path relatif>` per file, diurutkan menurut path secara ordinal, dengan LF termasuk setelah baris terakhir dan UTF-8 tanpa BOM. Handoff ini dikecualikan dari manifestnya sendiri.

| File | SHA-256 |
|---|---|
| `app/Http/Controllers/Api/SixFactorEvaluationController.php` | `f6dec7d270e17d6033ee323d6ad503dae1ebcfe767f28aa32941beb44c7299ce` |
| `app/Http/Controllers/Api/UmkmController.php` | `54b31828136128679bb934af7116b2f367032f82cbd6dd00d9d3fe4a766b8a28` |
| `app/Http/Middleware/PreserveSixFactorHttpMethod.php` | `ef9b64d878684c87b3a5696447f19512d9d28d81ced84caaaea731d4cfd8537e` |
| `app/Http/Requests/Evaluation/SixFactorEvaluationRequest.php` | `95974dcefe79cc01afc5146819c1c76dd8f991c76c011780b921c33ab65df39d` |
| `app/Http/Resources/SixFactorEvaluationResource.php` | `f37949420391ca41b5ebefa3347fab1d0f31475944b6576c0340735bf18b1a0c` |
| `app/Models/SixFactorEvaluation.php` | `a02bd8b5a2b7e0eb371e61f335829cf368f90a148da9e4fb2c5f66f1109da867` |
| `app/Models/Umkm.php` | `64fcdf32aff0abbc0b8592c44349ac0d58d9649c27480d0308ee390bfb76e1e6` |
| `app/Services/SixFactorEvaluationService.php` | `cf142b5dd57df7f91f5d6ad47e1b695259ab6c4e24c02954f0798cbf7b2cc73f` |
| `bootstrap/app.php` | `f28317414aa0a0adf4dc683434c4dd1a2baff6b530db29bed5aa90966a5927fd` |
| `database/factories/SixFactorEvaluationFactory.php` | `20ee8f4617d07b829c8d12a5fcd1a494ddfb46f36d166d49553091c53f96f3d7` |
| `database/migrations/2026_11_20_084534_create_six_factor_evaluations_table.php` | `573bf2f968559e70c5ccf6997498cc524082290db8c80185c4371ea608f86b32` |
| `docs/API.md` | `fd7937a5423fb16b3446f99a7ed88ca21bb407beca37d375588101d0725d8a60` |
| `docs/evidence/joyumkm-v1-p0-handoff.md` | `2c3e32c1746aab9f9482cb0e9c2811dfda7c3510f4775eb8ee04fde5f5b55c06` |
| `docs/prd/JoyUMKM_V1_PRD_Brief_P0.md` | `23a8ab912b941be5e7235e32f088fcd15dbc681e70078819c8fa7adbc4a7c185` |
| `docs/prd/JoyUMKM_V1_PRD_Brief_P1.md` | `9b45b7366d4db18cb68e04c38320c2be29c6c07837437eebc6a3f12a9d94617b` |
| `routes/api.php` | `50b86b3386a3c40357cef9043d2bb55aee1caf8bb5a1f02c62da09b525acd131` |
| `tests/Feature/SixFactorEvaluationApiTest.php` | `b01702e50d84bffed7847e05c51adc388c6ee7f48dc4ef6ebd97baf394438980` |
| `tests/Feature/SixFactorEvaluationConcurrencyTest.php` | `5a698a1d74b8a421e096af4503fbc2320b3633bf39089a70abde860cf23cb056` |
| `tests/Feature/SixFactorEvaluationDatabaseTest.php` | `bd9e8d694672688e8fc598844cb524c760cd0a7c8f27fc858ffdb7c066bc7fd1` |
| `tests/Feature/SixFactorEvaluationServiceTest.php` | `09649d89690931def783df17f05df6bdf863377425453596ed76f2789ad31b27` |

## Batas verifikasi

Bukti berasal dari HTTP kernel Laravel dan MySQL lokal. Integrasi UI P2 dan staging belum diuji.

