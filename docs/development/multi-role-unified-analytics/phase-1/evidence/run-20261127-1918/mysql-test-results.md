# Evidence: P1-03 Targeted MySQL Automated Test Results (`run-20261127-1918`)

- **Evidence ID:** `EV-P1-MYSQL-001`
- **Database Target:** `mysql` / `127.0.0.1:3306` / **`joyumkm_testing`** (enforced by `phpunit.xml` `force="true"` and `tests/TestCase.php:21-24`)
- **Execution Window:** `2026-11-27T19:25:41+07:00` – `19:38:00+07:00` (`Asia/Jakarta`)
- **Total Files Executed:** `51 / 51` PHPUnit files (`49` clean baseline files + `2` known-failing seeder test files run separately)

---

## 1. Suite Execution Summary

| Track / Group | Command | Tests | Passed | Failed | Errors | Skipped | Assertions | Duration (ms) | Exit Code | Classification |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| **A1 (Auth)** | `php artisan test --compact tests/Feature/AuthApiTest.php` | 17 | 17 | 0 | 0 | 0 | 114 | 21,269 | 0 | **PASS** |
| **A2 (UMKM / Ownership / 3D / Dashboard)** | `php artisan test --compact tests/Feature/UmkmApiTest.php tests/Feature/UmkmSummaryApiTest.php tests/Feature/OwnershipApiTest.php tests/Feature/DashboardApiTest.php tests/Feature/ThreeDimensionHealthApiTest.php tests/Feature/SixFactorSummaryApiTest.php tests/Feature/EvaluationApiTest.php tests/Feature/ScoringServiceTest.php` | 82 | 82 | 0 | 0 | 0 | 887 | 11,025 | 0 | **PASS** |
| **A3 (Six Factor / Predict / Monitoring)** | `php artisan test --compact tests/Feature/SixFactorEvaluationApiTest.php tests/Feature/SixFactorEvaluationServiceTest.php tests/Feature/SixFactorEvaluationDatabaseTest.php tests/Feature/SixFactorEvaluationConcurrencyTest.php tests/Feature/SixFactorPredictionApiTest.php tests/Feature/SixFactorMonitoringApiTest.php tests/Feature/SixFactorMonthlyRecapTest.php tests/Feature/SixFactorPeriodMonthTest.php` | 140 | 140 | 0 | 0 | 0 | 1,398 | 25,594 | 0 | **PASS** |
| **A4 (Outbox / Callback / Artifacts / Consent / Delivery)** | `php artisan test --compact tests/Feature/HermesWebhookOutboxTest.php ... tests/Unit/IntelligencePdfValidatorTest.php` (20 files) | 274 | 273 | 0 | 0 | 1* | 2,260 | 96,900 | 0 | **PASS** (*1 Windows skip in `IntelligenceArtifactStorageTest`) |
| **A5 (Analytics Planner & Seeder)** | `php artisan test --compact tests/Unit/AnalyticsDatasetPlannerTest.php tests/Feature/AnalyticsDatasetSeederTest.php` | 27 | 27 | 0 | 0 | 0 | 45,877 | 77,621 | 0 | **PASS** |
| **A_EXTRA (Integrity / Migrations / Cache / Contracts)** | `php artisan test --compact tests/Feature/DataIntegrityTest.php tests/Feature/LocationApiTest.php tests/Feature/PreventApiResponseCachingTest.php tests/Feature/UmkmProfileMigrationTest.php tests/Feature/UmkmProfilePagesTest.php tests/Feature/SixFactorPeriodMigrationTest.php tests/Feature/IntelligenceArtifactContractTest.php tests/Feature/IntelligenceHermesManifestContractTest.php tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php` | 26 | 26 | 0 | 0 | 0 | 215 | 20,160 | 0 | **PASS** |
| **DemoSeederTest (Known Pre-Existing)** | `php artisan test --compact tests/Feature/DemoSeederTest.php` | 12 | 10 | 2 | 0 | 0 | 31 | 8,660 | 1 | **`KNOWN_BASELINE_FAILURE`** |
| **SampleDataSeederTest (Known Pre-Existing)** | `php artisan test --compact tests/Feature/SampleDataSeederTest.php` | 17 | 14 | 1 | 2 | 0 | 119 | 20,361 | 2 | **`KNOWN_BASELINE_FAILURE`** |
| **TOTAL (All 51 PHPUnit Files)** | — | **595** | **589** | **3** | **2** | **1** | **50,891** | **~281,490** | — | **565/566 clean on core 49 files; 5 known seeder failures** |

*\*Note on Skipped Test (`IntelligenceArtifactStorageTest` standalone run: 24 tests, 23 passed, 1 skipped, 146 assertions, exit 0): Windows lacks symlink privilege or libzip encryption support (`IntelligenceArtifactStorageTest.php:146` or `:282`).*

---

## 2. Exact Failure Details (`KNOWN_BASELINE_FAILURE` — Unmodified)

1. **`DemoSeederTest::test_local_demo_uses_real_answers_and_expected_scores` (`DemoSeederTest.php:34`)**
   - Failure: `Failed asserting that table [users] matches expected entries count of 1. Entries found: 4.`
   - Cause: `.env` defines `SEED_PASSWORD`, so `DatabaseSeeder` invokes both `DemoSeeder` (1 user) and `SampleDataSeeder` (+3 users). Matches P0 `RSK-14`.
2. **`DemoSeederTest::test_rerun_preserves_account_and_umkm_edits_and_does_not_restore_deleted_fixture` (`DemoSeederTest.php:58`)**
   - Failure: Same (`Expected 1 user, found 4`).
3. **`SampleDataSeederTest::test_each_umkm_has_at_most_max_evaluations_evaluations` (`SampleDataSeederTest.php:142`)**
   - Failure: `UMKM Sumber Kencana memiliki 4 evaluasi, melebihi batas profil small (3).` Matches P0 `RSK-14`.
4. **`SampleDataSeederTest::test_invalid_seed_password_does_not_create_sample` (`SampleDataSeederTest.php:80`)**
   - Error: `Too few arguments ... 0 passed ... 1 expected` (legacy docblock `@dataProvider` incompatible with PHPUnit 12). Matches P0 `RSK-14`.
5. **`SampleDataSeederTest::test_rollback_on_failure_leaves_no_partial_data` (`SampleDataSeederTest.php:435`)**
   - Error: `Database connection [Rollback harus menghapus semua data partial.] not configured.` Pre-existing test helper defect.
