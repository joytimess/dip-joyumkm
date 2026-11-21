# Smoke aksesibilitas UI-1

Chrome 158 pada 1440×900, 768×1024 dan 390×844. Pemeriksaan otomatis ada di [ui-foundation-browser.mjs](../../../tests/js/ui-foundation-browser.mjs). Regresi workspace memeriksa skip link, fokus heading, navigasi keyboard, dan fokus shell.

| Area | Hasil |
| --- | --- |
| Keyboard | Menu aksi terbuka dengan Enter, aksi pertama mendapat fokus terlihat, Escape mengembalikan fokus ke pemicu. Tombol mata sandi dapat dioperasikan dengan Space dan tetap memiliki label aksesibel. |
| Dialog | Konfirmasi hapus menampilkan nama lengkap, Escape mengembalikan fokus ke pemicu dan tombol Batal berfungsi dengan sentuhan. Form invalid menampilkan pesan teks dan memfokuskan field omzet. |
| Form | Input menerima teks yang diketik. Browser mencatat tombol penyimpanan disabled dan busy saat PATCH tertahan. Setelah API merespons, toast sukses tampil dan bisa ditutup. Radio yang inputnya transparan menampilkan ring pada label. Atribut autocomplete untuk password login diverifikasi. |
| Sentuhan | Pada emulasi pointer kasar 390×844, tombol notifikasi dan aksinya dapat diketuk. Pemicu menu UMKM sedikitnya 44×44 px dan konfirmasi dapat dibatalkan lewat tombol Batal. |
| Reflow | Dokumen muat tanpa scroll horizontal tambahan. Tabel UMKM memakai scroll internal dan nama fixture panjang tetap di dalam sel. |
| Motion dan fallback | Reduced motion menekan durasi transisi. Saat request font diblokir, login tetap terbaca dan tidak overflow pada 390 px. |
| Status | Tautan, tombol, status penilaian, tooltip, dan pesan validasi memiliki teks. SVG dekoratif memakai `aria-hidden`. |

## Kontras teks biasa

Rasio berasal dari pasangan token yang dibaca di browser. Batas yang dipakai 4,5:1.

| Teks | Permukaan | Rasio |
| --- | --- | ---: |
| Electric Blue `#0207CA` | Putih `#FFFFFF` | 11,14:1 |
| Navy `#101E40` | Putih `#FFFFFF` | 16,38:1 |
| Muted aktif `#566781` | Putih `#FFFFFF` | 5,75:1 |
| Muted aktif `#566781` | Canvas `#F4F7FC` | 5,35:1 |
| Sukses `#147D5B` | Tint sukses `#E8F7F0` | 4,62:1 |
| Peringatan `#9B6508` | Tint peringatan `#FFF6E0` | 4,58:1 |
| Destruktif `#A52135` | Tint error `#FFF1F2` | 6,66:1 |

[Nilai dari browser](final/contrast.json) · [Fokus menu](final/1440-umkm-menu-focus.png) · [Form invalid](final/390-umkm-form-invalid.png) · [Fallback font](final/390-login-font-fallback.png)

Cakupan berupa smoke pada kontrol dan token inti. Pembaca layar langsung dan autofill password manager belum diuji.
