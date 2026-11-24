# P3-06 JoyUMKM Pipeline Integration dan Recovery

Worker Hermes kini menghubungkan kanonik, generator PDF/XLSX, manifest, retry terpisah, dan kesiapan downstream untuk intake yang enrolled. Deployment ke volume diperlakukan terpisah dari aktivasi rollout.

- [Kontrak pipeline](../evidence/p3-06-pipeline-contract.md)
- [Bukti pengujian](../evidence/p3-06-test-regression.md)
- [Laporan final P3](P3_JoyUMKM_FINAL_COMPLETION.md)

Jalur runtime berubah pada `jmi/pipeline.py`, `store.py`, `engine.py`, `callback.py`, `__main__.py`, `artifacts.py`, `pdf.py`, dan `xlsx.py`. Tes ada di `tests/test_pipeline.py` dalam volume Hermes.

**P3-06 FULL COMPLETION — PASS.** Kode tersedia di volume persisten. Ketiga belas tes pipeline lulus dalam suite akhir 156/156. Checkpoint implementasi tersimpan sebagai bukti uji, dan source sebelum deployment disimpan di `backups/p3-06-before-pipeline-20261124T035636Z`. [SHA-256 kode dan state](../evidence/p3-07/deployment-sha256.txt)
