export const dimensionCodes = ['INS', 'ECT', 'OPS'];

export const dimensionNames = {
    INS: 'Institutional Resources / Sumber Daya Kelembagaan',
    ECT: 'Economic Connectivity / Konektivitas Ekonomi',
    OPS: 'Operational Stability / Stabilitas Operasional',
};

export const statusFilters = [
    ['all', 'Semua status'],
    ['final', 'Final saja'],
    ['draft', 'Draft saja'],
    ['none', 'Belum dinilai'],
];

/**
 * @returns {number|null} persentase bulat, atau null bila total tidak diketahui (hindari pembagian nol).
 */
export function completionPercent(answered, total) {
    if (!total) return null;

    return Math.round((answered / total) * 100);
}

/** Jumlah UMKM tanpa hasil Final: Draft saja + belum dinilai. */
export function notFinalCount(summary) {
    return (summary?.draft_only ?? 0) + (summary?.not_assessed ?? 0);
}
