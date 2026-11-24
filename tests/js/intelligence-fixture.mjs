export const unsafeText = '<img src=x onerror="window.reportExecuted=true"><script>window.reportExecuted=true</script>';

export function reportFixture(id = 14, umkmId = 5) {
    return {
        id,
        umkm_id: umkmId,
        evaluation_id: id,
        generated_at: '2026-11-23T08:00:00Z',
        methodology_status: 'partial',
        report: {
            executive_summary: `Ringkasan evaluasi ${id} untuk usaha ${umkmId}. ${unsafeText}`,
            business_profile: { name: 'Usaha Contoh', sector: 'Jasa', data_gaps: ['Data pendapatan belum tersedia.'] },
            six_factor_analysis: {
                summary: 'Pencatatan rutin membantu pemilik menilai pengeluaran.',
                findings: [
                    { type: 'fact', statement: 'Skor pencatatan yang tercatat adalah nol.', score: 0, evidence_refs: ['internal.score'] },
                    { type: 'fact', statement: `Kategori Enam Faktor (dari Laravel): Sehat. Total skor Enam Faktor: ${id === 14 ? 25 : 23} dari skala 6-30.`, evidence_refs: ['six_factor.category', 'six_factor.total_score'] },
                    { type: 'fact', statement: 'Sumber Daya: skor 5 dari skala 1-5. Kinerja: skor 5 dari skala 1-5.', evidence_refs: ['six_factor.scores.sumber_daya', 'six_factor.scores.kinerja'] },
                    { type: 'fact', statement: 'Lingkungan Kerja: skor 3 dari skala 1-5.', evidence_refs: ['six_factor.scores.lingkungan_kerja'] },
                ],
            },
            three_dimension_analysis: {
                available: true,
                summary: 'Arus kas masih berfluktuasi.',
                findings: [
                    { type: 'interpretation', statement: 'Pencatatan dapat membantu menjelaskan fluktuasi.' },
                    { type: 'fact', statement: 'Kategori Tiga Dimensi (dari Laravel): SANGAT BAIK. Rata-rata Tiga Dimensi: 90,3 dari skala 0-100.', evidence_refs: ['three_dimension.category', 'three_dimension.average_score'] },
                    { type: 'fact', statement: `INS: skor ${id === 14 ? '83,3333' : '0'} dari skala 0-100. ECT: skor 100 dari skala 0-100. OPS: skor 87,5 dari skala 0-100.`, evidence_refs: ['three_dimension.scores.ins', 'three_dimension.scores.ect', 'three_dimension.scores.ops'] },
                ],
            },
            perspectives: {
                finance: { findings: [{ type: 'risk', statement: 'Pemasukan bergantung pada satu pelanggan.' }] },
                marketing_sales: { findings: [] },
                operations_people: { abstain_reason: 'Data beban kerja belum tersedia.' },
            },
            cross_instrument_insights: [{ observation: 'Kedua penilaian menyoroti pencatatan.', caveat: 'Periode datanya berbeda.' }],
            top_priorities: [
                {
                    id: 'P1', title: 'Rapikan pencatatan harian', perspective: 'finance', priority: 'medium', confidence: 'low',
                    rationale: 'Pengeluaran tercatat di beberapa tempat.', actions: ['Catat kas harian.'],
                    success_metrics: ['Catatan lengkap 30 hari.'], assumptions: ['Sediakan waktu setiap sore.'],
                },
                { id: 'P2', title: 'Kumpulkan catatan penjualan', priority: 'high' },
            ],
            action_plan: {
                days_1_30: [
                    { priority_id: 'P1', action: 'Mulai buku kas.', metric: '30 hari tercatat.' },
                    { priority_id: 'P2', action: 'Catat transaksi penjualan setiap hari.', metric: 'Pantau kelengkapan catatan transaksi.' },
                ],
                days_31_60: [{ action: 'Bandingkan pengeluaran bulanan.' }],
                days_61_90: [],
            },
            limitations: ['Laporan ini memakai data yang tersedia saat evaluasi.'],
        },
    };
}
