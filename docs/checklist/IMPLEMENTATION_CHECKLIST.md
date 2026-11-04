# Implementation checklist — JoyUMKM Dashboard Relayout V1

> Gunakan sebagai daftar kerja implementer, bukan perintah memulai test suite.

## Foundation

- [ ] Baca global `C:\Users\itsba\.codex\Agents.md` jika ada.
- [ ] Cari agen yang benar-benar tersedia di `C:\Users\itsba\.codex\agents\`.
- [ ] Baca AGENTS.md repo dan identifikasi framework/route/data layer.
- [ ] Inventarisasi 6 halaman dan fitur existing sebelum mengubah kode.
- [ ] Implement exact brand tokens dan shared shell.
- [ ] Buat responsive drawer/mobile behavior.
- [ ] Reuse komponen existing dan hindari dependencies yang tidak diperlukan.

## Pages

- [ ] Home: welcome hero, KPI, portfolio/actions, empty/loading states.
- [ ] Factor Profile: selector, radar, baseline, numeric data dan status.
- [ ] Distribution: donut counts, sector average chart, legend.
- [ ] Comparison: 1–5 UMKM unique, chips, grouped chart dan tabel.
- [ ] UMKM & Recommendations: filter/sort/pagination/CRUD/links dengan policy.
- [ ] Form Data: authentic questionnaire, tab factors, draft/save, legitimate live score.

## Guardrails

- [ ] Skor/threshold/kategori/status mengikuti logic aplikasi sebenarnya.
- [ ] Tidak menyalin data contoh atau `localStorage` mockup menjadi sumber data produksi.
- [ ] `16/16 jawaban` tidak otomatis dianggap final.
- [ ] Missing score tidak dipaksa jadi angka nol.
- [ ] Rekomendasi UMKM tidak dipalsukan berdasarkan style UI.
- [ ] Tidak mengubah auth, migrations, API, atau permissions tanpa kebutuhan yang disepakati.
- [ ] Tidak ada CTA/informasi palsu yang terlihat aktif bila fiturnya belum ada.

## Handoff

- [ ] Layout bebas overflow kritikal pada mobile/tablet/desktop.
- [ ] Navigasi dan aksi utama tetap terhubung.
- [ ] Dokumentasikan file yang berubah dan cara jalan lokal.
- [ ] Tidak spawn agent QA/testing/regression; tidak jalankan full suite secara default.
