# P3-05 JoyUMKM × Hermes — Artifact Validation & Manifest

**Status P3-05 PASS.** Hermes 143/143, Laravel P2 5/5, lima paket persisten dan nol identitas regresi baru.

Paket bulanan dapat divalidasi dan dikomit secara lokal di Hermes. Implementasi memakai laporan kanonik P3-02, validator PDF P3-03, dan validator XLSX P3-04. Lima paket sintetis memakai berkas ekspor asli beserta sidecar. Container kedua membaca volume yang sama, memverifikasi lima paket, dan memakai ulang marker yang identik. Kedua container berjalan dengan `network=none`.

## Handoff

Jalankan `python -m jmi build-manifest EVENT_ID` dari `/opt/data/joyumkm-intelligence` sebagai `hermes` setelah kedua artefak tersedia. Gunakan `python -m jmi manifest-verify EVENT_ID` sebelum paket dipakai. P3-06 dapat memanggil `build_manifest` dan `verify_manifest`. `get_transport_manifest` mengembalikan proyeksi yang lulus validator Laravel P2. Status ready menandai kesiapan lokal Hermes.

| Lokasi | Perubahan |
|---|---|
| `hermes_data:/joyumkm-intelligence/jmi/artifacts.py` | Validasi konteks dan artefak, schema, hash, proyeksi P2, lock, publikasi dan recovery. |
| `hermes_data:/joyumkm-intelligence/jmi/__main__.py` | Registrasi `build-manifest` dan `manifest-verify`. |
| `hermes_data:/joyumkm-intelligence/tests/test_artifacts.py` | Suite manifest menggunakan lima ekspor sintetis. |
| `hermes_data:/joyumkm-intelligence/backups/p3-05-before-manifest/jmi__main__.py` | Backup CLI sebelum P3-05. |
| `tests/Feature/IntelligenceHermesManifestContractTest.php` | Uji proyeksi Hermes dan hash terhadap kontrak PHP, tanpa koneksi database atau HTTP. |
| `docs/evidence/p3-05/` | Sampel, proyeksi, vektor, checksum, hasil uji dan bukti deployment. |

- [Schema, pemetaan, hash, CLI dan recovery](../evidence/p3-05-manifest-contract.md)
- [QA, regresi dan batas verifikasi](../evidence/p3-05-test-regression.md)
- [Hash file deployment](../evidence/p3-05/deployment-sha256.txt)

Deployment memasang kode pada volume `hermes_data`. Job sintetis berada pada volume `jmi-p305-final` dengan `JMI_HOME=/qa/state`. Dependency image yang diverifikasi ialah pypdf 6.21.4, Openpyxl 3.1.5, ReportLab 5.1.0 dan XlsxWriter 3.3.0. Cakupan readiness adalah job bulanan P3-02 dengan konteks lengkap, template aktif, dan filesystem Linux yang mendukung flock dan rename atomik. Perubahan artefak setelah verifikasi mengharuskan verifikasi ulang oleh konsumen. Validasi produksi terhadap job historis serta tahapan pengiriman dan orkestrasi berada di luar eksekusi QA ini.

