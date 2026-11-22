# Handoff R01 Wizard Tambah UMKM

Implementasi mengikuti [PRD R01](../../../prd/PRD_JoyUMKM_Refinement_OSM_Location_WhatsApp_Prefix.md) dan [DESIGN.md lokal](../../../../DESIGN.md). Lokasi dipilih lewat pencarian, klik, tap, geser pin, atau aksi pilih titik tengah untuk keyboard. Pengguna bisa mengoreksi detail alamat. Langkah Kontak menampilkan prefiks tetap `+62` dan menerima nomor lokal serta format paste dari PRD.

## Perubahan

- [Wizard Blade](../../../../resources/views/umkms.blade.php), [pengendali form](../../../../resources/js/umkm-form.js), dan [picker lokasi](../../../../resources/js/umkm-location.js) memakai dialog native, pilihan provinsi, serta token desain JoyUMKM. Leaflet 1.9.4 dimuat saat tahap Lokasi tampil. Build menambahkan chunk peta sebesar 45,47 kB gzip dan CSS peta sekitar 2,63 kB gzip.
- [Layanan geocoding](../../../../app/Services/GeocodingService.php) menyediakan `POST /api/locations/search` dan `POST /api/locations/reverse` lewat Sanctum. Teks dan koordinat berada di body request. Pencarian dibatasi 3–120 karakter dan 5 hasil. Throttle pengguna berlaku gabungan untuk kedua endpoint sebanyak 15 panggilan per menit.
- [Validasi Create](../../../../app/Http/Requests/Umkm/StoreUmkmRequest.php) mewajibkan koordinat dalam rentang geografis. Pemanggil `POST /api/umkms` perlu mengirim latitude dan longitude. State pin berada di browser. PATCH legacy tetap menerima perubahan parsial dan menerima koordinat opsional yang dikirim berpasangan.
- [Normalisasi WhatsApp](../../../../resources/js/whatsapp-number.js) mempertahankan digit lokal di input dan mengirim `628…` ke Laravel. Nomor valid tetap dapat disimpan pada ketiga status OpenWA. Provider OpenWA memakai konfigurasi yang sudah tersedia.
- Pengerjaan dilakukan pada thread utama. Skill yang dipakai meliputi Superpowers, Ponytail, Laravel Best Practices, Testing Best Practices, Tailwind CSS Development, dan Impeccable.

## Aktivasi geocoding

Isi `GEOCODING_URL`, `GEOCODING_ALLOWED_HOSTS` dan `GEOCODING_USER_AGENT` di Laravel dengan penyedia Nominatim-compatible serta identitas aplikasi dan kontak operasional yang sah. Gunakan cache database atau Redis bersama bila memilih public Nominatim. Pengaturan tersedia di [`.env.example`](../../../../.env.example). Jika provider belum dikonfigurasi, pengguna dapat memilih pin pada tile OSM dan melengkapi detail alamat secara manual.

Untuk public Nominatim, lock bersama menserialkan request pencarian dan reverse yang menuju upstream. Request berikutnya baru boleh dikirim setidaknya satu detik setelah request sebelumnya selesai. Hasil disimpan 24 jam dan hasil kosong disimpan 5 menit. Pencarian berjalan setelah pengguna menekan Cari atau Enter. Operator perlu memastikan penggunaan dan alamat yang dikirim sesuai kebijakan provider. [Kebijakan penggunaan Nominatim](https://operations.osmfoundation.org/policies/nominatim/)

Tile diminta Leaflet melalui browser dengan atribusi yang terlihat, Referer standar, dan caching HTTP normal. `OSM_TILE_URL` dapat menunjuk penyedia tile lain. [Kebijakan tile OSM](https://operations.osmfoundation.org/policies/tiles/) · [Dokumentasi Leaflet 1.9.4](https://leafletjs.com/reference-1.9.4.html)

## Bukti QA

MySQL 8.4.3 memakai skema khusus `joyumkm_testing`. Chrome 158.0.8127.36 menguji aplikasi Laravel di loopback. Matrik interaksi memakai fixture tile dan respons geocoder browser. Create, detail, dan PATCH berjalan terhadap Laravel dan MySQL. Uji tambahan memakai tile OSM langsung dengan cache browser normal. PHPUnit memakai HTTP fake untuk provider geocoding dan OpenWA.

| Pemeriksaan | Hasil |
| --- | --- |
| PHPUnit terarah | PASS, 105 tes dan 1.395 assertion. |
| Suite PHPUnit penuh | FAIL, 235 dari 240 tes lulus dengan 2.390 assertion. Tiga failure dan dua error seeder cocok dengan [baseline wizard](../handoff.md#bukti-verifikasi) dan [rincian baseline UI-3](../../ui-3/handoff.md#regresi-php-sebelum-dan-sesudah). |
| `npm test` | PASS, 39 tes. |
| `npm run build` | PASS, dengan peringatan ukuran chunk ApexCharts yang sudah tercatat pada baseline. |
| Pint dan `git diff --check` | PASS. |
| `node tests/js/umkm-wizard-browser.mjs` | PASS di 320×720, 390×844, 768×1024, dan 1440×900. |
| Runner browser dengan `--live-tiles` | PASS. Tile OSM nyata tampil pada 390 dan 1440 px. Klik pada peta desktop menghasilkan pin. |
| Geocoding live dan OpenWA live | NOT RUN. Laravel QA menjalankan fallback provider dan fixture pengujian. |
| Runner workspace lama | NOT RUN pada R01. Selector tautan nama UMKM di desktop masih tercatat pada [baseline wizard](../handoff.md#bukti-verifikasi). |

Runner wizard memakai konfigurasi loopback dan database uji dari [handoff awal](../handoff.md#bukti-verifikasi). Untuk uji otomatis, jalankan Laravel dengan OpenWA dan geocoder eksternal dinonaktifkan. Opsi `--live-tiles` membuka tile OSM memakai cache normal browser.

### Acceptance criteria

| ID | Hasil | Bukti |
| --- | --- | --- |
| R01-AC01 | PASS | Tile OSM live, atribusi, tombol zoom, dan pan keyboard. |
| R01-AC02 | PASS | Cari dan Enter memicu pencarian eksplisit. Hasil fixture dapat dipilih dan memasang pin. |
| R01-AC03 | PASS | Klik desktop, tap mobile, dan drag pin dengan mouse serta touch memperbarui koordinat. |
| R01-AC04 | PASS | Fake provider mengisi field yang tersedia. Variasi kota, kabupaten, dan desa diuji. Field yang tidak tersedia tetap kosong. |
| R01-AC05 | PASS | Respons lama diabaikan. Koreksi alamat saat reverse berlangsung dan koreksi sebelumnya tetap tersedia. |
| R01-AC06 | PASS | Koordinat readonly. Lanjut diblokir dan fokus menuju peta saat pin belum dipilih, termasuk ketika alamat sudah diisi manual. |
| R01-AC07 | PASS | Create menolak koordinat kosong atau di luar rentang dengan 422. Payload valid menghasilkan 201. PATCH legacy tetap lulus. |
| R01-AC08 | PASS | Hasil kosong, 503, timeout, kegagalan tile dan tombol Coba lagi diuji. Pin tetap ada saat reverse tidak tersedia. |
| R01-AC09 | PASS | Pin, koordinat, dan koreksi bertahan saat B → A → B. Buka ulang menghasilkan satu kontrol zoom dan peta tanpa pin awal. |
| R01-AC10 | PASS | Sanctum, validasi, allowlist, cache, throttle, cooldown agregat, dan request eksplisit diperiksa. |
| R01-AC11 | PASS | Prefiks tetap, label input, deskripsi kode negara, dan fokus keyboard di Chrome. |
| R01-AC12 | PASS | Tes Node mencakup format paste. Browser dan API menyimpan bentuk `628…`. |
| R01-AC13 | PASS | Format invalid memblokir create. Tiga hasil OpenWA diuji dan respons nomor lama diabaikan. |
| R01-AC14 | PASS | Wizard, guard perubahan, double-submit, daftar, detail parsial, edit, ownership, dan penilaian tercakup. |
| R01-AC15 | PASS | Tes, formatter, build, perbandingan dengan baseline, dan screenshot tersedia. |

### Screenshot

- 390 px · [Lokasi sebelum](../screenshots/after-390-B.png) · [Lokasi dengan tile OSM](screenshots/after-390-B-osm.png) · [Kontak sebelum](../screenshots/after-390-C.png) · [Kontak dengan +62](screenshots/after-390-C.png)
- 1440 px · [Lokasi sebelum](../screenshots/after-1440-B.png) · [Lokasi dengan pin OSM](screenshots/after-1440-B-osm.png) · [Kontak sebelum](../screenshots/after-1440-C.png) · [Kontak dengan +62](screenshots/after-1440-C.png)
- 320 px · [Lokasi dengan fixture tile](screenshots/after-320-B-interaction.png) · [Tile gagal](screenshots/after-320-tile-failed.png) · [Kontak](screenshots/after-320-C.png)
- 768 px · [Lokasi dengan fixture tile](screenshots/after-768-B-interaction.png) · [Kontak](screenshots/after-768-C.png)
