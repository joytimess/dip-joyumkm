# Laporan cakupan

- Rute halaman ditemukan: 11 (3 publik, 8 membutuhkan sesi).
- Rute dengan screenshot default tiga viewport: 11/11 (100% cakupan rute halaman); 33/33 kombinasi rute dan viewport (100%). Ini bukan 100% semua state interaktif.
- Total screenshot: 48; publik 9, workspace default 24, state interaktif 15.
- BLOCKED_ROLE: 0 ditemukan; BLOCKED_DATA: 0 rute; BLOCKED_ENV: 0 rute; FAIL: 0 rute.
- File PNG hilang/invalid: 0. Duplikat byte-identik: 0 kelompok.
- Browser memakai sesi login sebenarnya; tidak ada perubahan UMKM atau evaluasi yang dikirim. Login normal membuat sesi autentikasi.
- API HTTP >=400 selama sesi utama: tidak teramati.

## Interaksi

Validasi kosong login, registrasi dan tambah UMKM; dialog tambah, edit, konfirmasi hapus dan hasil historis; menu aksi dan navigasi mobile; profil toast; filter tanpa hasil; kuesioner terpilih dan tiga tab faktor. Lihat manifest untuk setiap file.

## Kesenjangan

- Menu navigasi dicoba pada desktop tetapi tombol tersembunyi; menu mobile berhasil direkam.
- Capture filter status final gagal karena menunggu state loading yang selesai terlalu cepat (timeout); filter tanpa hasil berhasil direkam. Status state filter final: FAIL, bukan kegagalan rute.
- Nilai slider enam faktor tidak diubah karena autosave; finalisasi, pembatalan, penyimpanan, penghapusan dan pembuatan tidak dilakukan. State hasil final historis direkam dari data tersedia; draft terisi/cancelled dan dialog yang mensyaratkan mutasi tidak diverifikasi.
- Dropdown native terbuka, loading singkat, pagination multi-halaman, berbagai kombinasi data dan semua variasi validasi tidak diverifikasi. Tidak ada data baru dibuat.
- Tidak ada inspeksi manual lengkap terhadap seluruh gambar, aksesibilitas, atau pengujian fungsional menyeluruh.

## Verifikasi berkas

Semua entri manifest ditemukan dan memiliki header PNG.
Tidak ada file identik berdasarkan SHA-256.
