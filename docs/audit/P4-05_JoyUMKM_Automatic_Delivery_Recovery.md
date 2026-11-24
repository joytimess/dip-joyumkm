# P4-05 — Automatic Delivery & Recovery — PASS

Kontrak: [p4-05-delivery-contract.md](../evidence/p4-05-delivery-contract.md). QA: [integrated](../evidence/p4-05-integrated-qa.md), [recovery](../evidence/p4-05-recovery-evidence.md), [regresi](../evidence/p4-05-test-regression.md). Bukti mentah: `docs/evidence/p4-05/`.

Ringkas: `jmi/delivery.py` + hook `engine.process`/`engine.recover` + penanda enrolment `artifact_delivery` (saat intake baru) + `delivery.json` per event + retry independen (4 percobaan; 600/1800/5400 dtk) + lease `.delivery` + CLI `delivery-status|resume|verify`. Default produksi tetap mati.

Kriteria: worker otomatis ✓, enrolment durable ✓, job legacy/archived/non-enrolled tak tersentuh ✓, callback mendahului unggah ✓, retry independen dan terbatas ✓, lost-response ✓, crash recovery 6/6 lintas-container ✓, konkurensi ✓, lima paket lengkap (5 report, 5 manifest, 10 artefak, 10 file identik, `complete`) ✓, nol model/render tambahan setelah checkpoint ✓, nol identitas regresi baru ✓, state produksi tak berubah ✓, kode final di `hermes_data` ✓ (SHA-256 di `p4-05/deployment-sha256.txt`).

Batas yang diakui: fault injection dilakukan di batas transport (remote nyata); penjadwalan 600/1800/5400 dan eksaustasi diuji dengan fake clock; cooldown QA 2 dtk. Parallel multi-event hanya diuji lewat lima event berurutan dan tes lock per event, bukan beban paralel. Hermes tidak dapat mendeteksi korupsi remote otomatis (tanpa API status; P4-06).
