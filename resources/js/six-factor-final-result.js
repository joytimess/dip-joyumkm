import ApexCharts from 'apexcharts';
import { chartHost, chartOptions } from './charts';
import { categoryTone } from './six-factor-dashboard-data';
import { sixFactors } from './six-factor-draft';
import { element, formatNumber } from './ui';

const factors = Object.entries(sixFactors);
const shortLabels = ['SD', 'KP', 'LK', 'NO', 'ST', 'KI'];
const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

function finalDate(result) {
    const time = result.finalized_at && new Date(result.finalized_at);
    if (!time || !Number.isFinite(time.getTime())) return element('span', { text: 'Waktu Final tidak tersedia' });
    return element('time', { datetime: result.finalized_at, text: dateFormat.format(time) });
}

/**
 * Menampilkan hasil Final tiga panel (ringkasan, nilai per faktor, radar) di dalam root.
 * @param {HTMLElement} root
 * @param {{ total_score: number, category: string, finalized_at: string, scores: Record<string, number> }} result
 * @param {{ name: string, place?: string, label?: string }} details
 * @returns {ApexCharts | null} instance radar; panggil destroy() saat konten dilepas
 */
export function renderFinalResult(root, result, { name, place = '', label = 'Final terbaru' }) {
    const radar = chartHost('Radar penilaian enam faktor, skala 1 sampai 5');
    radar.classList.add('six-factor-radar');
    const summary = element('section', { class: 'six-final-panel six-final-summary', 'aria-label': 'Ringkasan hasil Final' }, [
        element('div', { class: 'six-final-summary-top' }, [
            element('span', { text: label }),
            element('span', { class: `pill ${categoryTone(result.category)}`, text: result.category }),
        ]),
        element('strong', { class: 'six-final-major num', text: formatNumber(result.total_score) + '/30' }),
        element('strong', { class: 'six-final-name', text: name }),
        place ? element('p', { class: 'six-final-place small', text: place }) : null,
        element('div', { class: 'six-final-meta' }, [
            element('p', { class: 'small', text: 'Tanggal / pembaruan' }),
            element('strong', {}, finalDate(result)),
            element('p', { class: 'small', text: 'Jenis evaluasi' }),
            element('strong', { text: 'Penilaian Enam Faktor · 6 variabel' }),
        ]),
    ]);
    const bars = element('section', { class: 'six-final-panel', 'aria-label': 'Nilai tiap faktor' }, [
        element('h3', { text: 'Nilai tiap faktor' }),
        element('p', { class: 'section-desc', text: 'Nilai tersimpan pada hasil Final dan tidak dapat diubah.' }),
        ...factors.map(([key, factorName], index) => {
            const value = result.scores[key];
            return element('div', { class: 'six-final-factor' }, [
                element('div', { class: 'six-final-factor-title' }, [
                    element('strong', { text: `${shortLabels[index]} · ${factorName}` }),
                    element('span', { class: 'num', text: `${value} / 5` }),
                ]),
                element('div', { class: 'six-final-track', 'aria-hidden': 'true' }, element('div', { class: 'six-final-fill', style: `width:${value * 20}%` })),
            ]);
        }),
    ]);
    const profile = element('section', { class: 'six-final-panel six-final-radar', 'aria-label': 'Profil enam faktor' }, [
        element('h3', { text: 'Profil enam faktor' }),
        radar,
        element('p', { class: 'section-desc', text: 'Skala 1–5 · SD, KP, LK, NO, ST, KI' }),
    ]);
    root.append(element('div', { class: 'six-final-grid' }, [summary, bars, profile]));

    const options = chartOptions('radar', [{ name: 'Nilai faktor', data: factors.map(([key]) => result.scores[key]) }], shortLabels);
    options.chart.height = 260;
    options.yaxis = { min: 1, max: 5, tickAmount: 4, labels: { formatter: (value) => formatNumber(value) } };
    options.xaxis.labels = { style: { colors: shortLabels.map(() => 'var(--text-muted)'), fontSize: '12px' } };
    options.grid.padding = { top: 16, left: 12, right: 12 };
    options.tooltip = { enabled: false };
    options.markers = { size: 4 };
    const chart = new ApexCharts(radar, options);
    chart.render().catch(() => {
        if (radar.isConnected) radar.replaceWith(element('p', { class: 'muted', text: 'Radar belum dapat ditampilkan.' }));
    });
    return chart;
}
