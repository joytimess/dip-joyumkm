# P5-05 — Pengiriman otomatis dan recovery

**PASS untuk QA lokal.** Enrollment terjadi saat intake event final v2 baru dengan pipeline terdaftar dan flag email aktif. Worker menjalankan email setelah Package Ready lokal. `engine.recover` memberi cabang email kuota terpisah dari kerja analisis dan delivery P4. Uji membuktikan kegagalan P4 tetap memberi kesempatan pada email, dan kegagalan email mempertahankan hasil P4 serta status analisis.

Lease event dan state privat menahan percobaan paralel. Percobaan dicatat sebelum network. Worker mencatat `sending` sebelum DATA dan `sent` segera setelah acknowledgment 250. Proses pengganti membaca `sent` sebagai terminal dan mengubah `sending` yang tertinggal menjadi `delivery_unknown`. Keduanya berhenti tanpa pull dan SMTP ulang. Uji juga menunjukkan state yang hilang setelah enrollment diblokir.

Fake clock membuktikan tiga cooldown 600, 1.800, dan 5.400 detik dengan maksimum empat percobaan. Fingerprint berubah dan consent dicabut menghentikan retry. State hanya menyimpan identitas yang diperlukan untuk audit dan handoff status P5-06.

- [Kontrak state dan recovery](../evidence/p5-05-email-delivery-contract.md)
- [Bukti QA](../evidence/p5-05-qa-summary.md)
