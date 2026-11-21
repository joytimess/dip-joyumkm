# Inventaris rute UI-0 JoyUMKM

Checkout kerja berangkat dari `804738b` dan sudah memuat perubahan lokal P0–P2. Inventaris diambil dari `routes/web.php` dan dikonfirmasi melalui `php artisan route:list --except-vendor --no-interaction`.

| Rute workspace | Bagian yang dimuat | Skenario yang dicakup |
| --- | --- | --- |
| `/home` | KPI portofolio, grafik kesehatan, tabel Snapshot UMKM | Login, kembali dari menu, KPI tertunda sementara grafik sudah tampil |
| `/umkms` | Filter, KPI, tabel, modal tambah/ubah/hapus | Search URL, reload, hasil kosong, 403 dan retry tabel, tablet/mobile, edit modal saat Back |
| `/umkms/{id}` | Informasi usaha dan tindakan lanjutan | Dari tabel, cold entry, Back/Forward, akses pemilik dan akun lain |
| `/umkms/{id}/six-factor-evaluations` | Identitas UMKM, Draft, Final terbaru, histori Final dan radar | Dari detail, Back/Forward, slider, autosave, finalisasi, pembatalan, histori, recovery per panel |
| `/form-data` | Picker, skema kuesioner, evaluasi terbaru dan kalkulasi | Memilih UMKM, perubahan belum tersimpan, konfirmasi klik menu dan Back |
| `/factor-profile` | Picker, metrik dan radar | Tautan langsung `?umkm_id=`, pilih otomatis, perpindahan cepat |
| `/distribution` | KPI distribusi, grafik kategori dan sektor | 500, retry lokal, kondisi lambat, peralihan rute |
| `/comparison` | Picker, daftar pembanding, grafik dan tabel skor | Perpindahan dari rute lain, respons navigasi gagal dan retry |

`/`, `/login` dan `/register` adalah entri publik. Login dan logout memakai navigasi dokumen biasa. Histori Final ada di panel Penilaian Enam Faktor.
