# Inventaris rute halaman

Sumber: routes/web.php, Blade, navigasi dan resources/js/app.js. Semua view dihidangkan tanpa middleware web auth; delapan halaman workspace dijaga oleh guard JavaScript dan API Sanctum. ID yang dipakai adalah entitas yang tersedia bagi akun pengujian.

| Rute | Halaman | Akses | Desktop | Tablet | Mobile | Status |
|---|---|---|---|---|---|---|
| / | Landing | Publik | PASS | PASS | PASS | PASS |
| /login | Login | Publik | PASS | PASS | PASS | PASS |
| /register | Registrasi | Publik | PASS | PASS | PASS | PASS |
| /home | Dashboard | Sesi | PASS | PASS | PASS | PASS |
| /umkms | Daftar UMKM | Sesi | PASS | PASS | PASS | PASS |
| /umkms/{id} | Detail UMKM | Sesi | PASS | PASS | PASS | PASS |
| /umkms/{id}/six-factor-evaluations | Penilaian enam faktor | Sesi | PASS | PASS | PASS | PASS |
| /form-data | Kuesioner tiga faktor | Sesi | PASS | PASS | PASS | PASS |
| /factor-profile | Profil faktor | Sesi | PASS | PASS | PASS | PASS |
| /distribution | Distribusi | Sesi | PASS | PASS | PASS | PASS |
| /comparison | Perbandingan | Sesi | PASS | PASS | PASS | PASS |

Rute lainnya: halaman lupa/reset kata sandi, profil dan pusat bantuan tidak diimplementasikan sebagai rute; tombol profil/bantuan menampilkan toast. Halaman error khusus tidak ditemukan. NOT_APPLICABLE. Tidak ditemukan aturan role pada rute halaman yang ditinjau.

Rute API bukan halaman visual: POST /api/auth/register, POST /api/auth/login; autentikasi Sanctum: GET /api/auth/me, POST /api/auth/logout, GET /api/dashboard/{factor-profile,distribution,comparison}, GET /api/questionnaire, GET /api/umkms/{id}/evaluations/latest, POST /api/umkms/{id}/evaluations, GET /api/umkms/{id}/six-factor-evaluations/{draft,latest}, PUT/PATCH draft, POST {evaluationId}/{finalize,cancel}, GET /api/umkms/{id}/six-factor-evaluations, GET /api/umkms/{options,summary}, GET/POST /api/umkms, GET/PUT/PATCH/DELETE /api/umkms/{id}. NOT_APPLICABLE untuk screenshot halaman; tidak diuji sebagai inventaris API.
