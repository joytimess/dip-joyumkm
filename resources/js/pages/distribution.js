import { chartHost, chartOptions, createChartPage, emptyState, tooltipText } from '../charts';
import { element, formatNumber, formatScore } from '../ui';

function metricIconSvg(name) {
    const svgs = {
        store: '<path d="M3 10h18l-2-7H5zM4 10v11h16V10M9 21v-8h6v8M3 10c0 3 4 3 4 0 0 3 4 3 5 0 1 3 5 3 5 0 0 3 4 3 4 0"/>',
        trend: '<path d="m3 17 7-7 4 4 7-8M15 6h6v6"/>',
        clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 3"/>',
        circle: '<path d="M12 2v10h10A10 10 0 1 1 12 2Z"/><path d="M15 2a9.8 9.8 0 0 1 7 7h-7z"/>',
    };
    const node = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    node.setAttribute('class', 'icon');
    node.setAttribute('viewBox', '0 0 24 24');
    node.setAttribute('fill', 'none');
    node.setAttribute('stroke', 'currentColor');
    node.setAttribute('stroke-width', '1.8');
    node.setAttribute('stroke-linecap', 'round');
    node.setAttribute('stroke-linejoin', 'round');
    node.innerHTML = svgs[name] || '';
    return node;
}

export function init() {
    const root = document.getElementById('page-data');
    const load = createChartPage(root, (data, mount) => {
        if (!data.total_umkms) {
            root.append(emptyState('Belum ada UMKM', 'Tambahkan UMKM untuk melihat distribusi kategori dan sektor.', '/umkms', 'Tambah UMKM'));
            return;
        }
        const donut = chartHost('Donut jumlah UMKM per kategori, termasuk belum dinilai');
        const bar = chartHost('Bar rerata skor kesehatan per sektor, skala 0 sampai 100');
        const categories = data.categories.filter((category) => category.count > 0);
        const scoredSectors = data.sectors.filter((sector) => sector.average_score !== null);
        const scoredCount = data.sectors.reduce((total, sector) => total + sector.scored_count, 0);
        const draftCount = data.sectors.reduce((total, sector) => total + sector.draft_count, 0);

        const metricsData = [
            { icon: 'store', theme: '', label: 'Total UMKM', value: data.total_umkms, unit: 'UMKM', foot: 'Seluruh usaha dalam portofolio' },
            { icon: 'trend', theme: 'blue', label: 'Memiliki skor', value: scoredCount, unit: 'UMKM', foot: 'Berdasarkan evaluasi terbaru' },
            { icon: 'clock', theme: 'orange', label: 'Evaluasi sementara', value: draftCount, unit: 'Draft', foot: 'Status tersimpan dari server' },
            { icon: 'circle', theme: 'blue', label: 'Sektor terwakili', value: data.sectors.filter((s) => s.total_count > 0).length, unit: 'Sektor', foot: 'Sektor dengan data UMKM' },
        ];

        const metricStrip = element('div', { class: 'grid-4 metric-strip', style: 'margin-bottom:20px;' }, metricsData.map((item) => element('section', { class: `card metric panel ${item.theme === 'orange' ? 'metric-accent' : ''}` }, [
            element('span', { class: 'soft-corner', 'aria-hidden': true }),
            element('div', { class: `metric-icon ${item.theme}` }, [metricIconSvg(item.icon)]),
            element('div', { class: 'metric-label', text: item.label }),
            element('div', { class: 'metric-value' }, [
                element('span', { text: formatNumber(item.value) }),
                element('span', { class: 'metric-suffix', text: item.unit }),
            ]),
            element('div', { class: 'metric-foot', text: item.foot }),
        ])));

        const legend = element('div', { class: 'legend-list' }, data.categories.map((category) => element('div', { class: 'legend-item' }, [
            element('span', { class: 'dot', style: `background:${category.color};` }),
            element('span', { text: category.label }),
            element('strong', { text: formatNumber(category.count) }),
        ])));

        root.append(
            metricStrip,
            element('div', { class: 'two-col distribution-grid', style: 'margin-bottom:20px;' }, [
                element('section', { class: 'card card-pad panel chart-panel' }, [
                    element('div', { class: 'section-top section-heading' }, [
                        element('div', {}, [
                            element('h2', { class: 'section-title', text: 'Kategori kesehatan' }),
                            element('p', { class: 'section-desc muted', text: 'Komposisi seluruh responden, termasuk yang belum dinilai.' }),
                        ]),
                    ]),
                    element('div', { class: 'health-row' }, [donut, legend]),
                ]),
                element('section', { class: 'card card-pad panel chart-panel' }, [
                    element('div', { class: 'section-top section-heading' }, [
                        element('div', {}, [
                            element('h2', { class: 'section-title', text: 'Skor rata-rata per sektor' }),
                            element('p', { class: 'section-desc muted', text: 'Skor dari evaluasi terbaru. Nilai kosong tidak dihitung sebagai nol.' }),
                        ]),
                    ]),
                    scoredSectors.length ? bar : emptyState('Belum ada skor sektor', 'Simpan evaluasi untuk melihat rerata. UMKM belum dinilai tetap dihitung pada kategori.'),
                ]),
            ]),
            element('section', { class: 'card card-pad panel chart-panel section-block' }, [
                element('div', { class: 'section-top section-heading' }, [
                    element('div', {}, [
                        element('h2', { class: 'section-title', text: 'Wawasan per sektor' }),
                        element('p', { class: 'section-desc muted', text: 'Lihat skor bersama jumlah sampel dan kelengkapan evaluasinya.' }),
                    ]),
                ]),
                element('div', { class: 'sector-insights grid-2', style: 'display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;' }, data.sectors.map((sector) => element('article', { class: 'sector-insight card-inset', style: 'padding:18px;background:var(--canvas);border-radius:12px;' }, [
                    element('h3', { style: 'margin:0 0 6px;font-size:13px;font-weight:800;', text: sector.name }),
                    element('strong', { class: 'metric-value', style: 'font-size:24px;margin:6px 0 8px;display:block;', text: sector.average_score === null ? '—' : `${formatScore(sector.average_score)} /100` }),
                    element('div', { class: 'progress', style: 'margin-bottom:10px;' }, sector.average_score === null ? [] : [element('span', { style: `width:${sector.average_score}%;background:#0297DE` })]),
                    element('p', { class: 'muted tiny', style: 'margin:0;', text: sector.average_score === null ? 'Belum ada skor pada sektor ini.' : `${sector.scored_count} UMKM berskor dari ${sector.total_count} UMKM` }),
                    element('small', { class: 'muted tiny', style: 'display:block;margin-top:4px;', text: `${sector.total_count} UMKM · ${sector.draft_count} evaluasi Sementara` }),
                ]))),
                element('div', { class: 'note-card', style: 'margin-top:20px;' }, [
                    element('div', {}, [
                        element('h4', { text: 'Prioritas perhatian, bukan label kritis' }),
                        element('p', { text: 'Rerata sektor yang lebih rendah dapat membantu menentukan urutan pendampingan. Kategori kesehatan tetap mengikuti hasil evaluasi tiap UMKM secara individual.' }),
                    ]),
                ]),
            ]),
        );

        const donutOptions = chartOptions('donut', categories.map((category) => category.count));
        donutOptions.labels = categories.map((category) => category.label);
        donutOptions.colors = categories.map((category) => category.color);
        donutOptions.plotOptions = {
            pie: {
                donut: {
                    size: '76%',
                    labels: {
                        show: true,
                        name: { show: true, color: '#70819D' },
                        value: { show: true, formatter: (value) => formatNumber(value), fontSize: '26px', fontWeight: 800, color: '#101E40' },
                        total: { show: true, showAlways: true, label: 'Total UMKM', color: '#70819D', fontSize: '11px', formatter: () => formatNumber(data.total_umkms) },
                    },
                },
            },
        };
        donutOptions.stroke = { width: 3, colors: ['#fff'] };
        donutOptions.tooltip.custom = ({ seriesIndex }) => tooltipText([categories[seriesIndex].label, `${categories[seriesIndex].count} dari ${data.total_umkms} UMKM`]);
        mount(donut, donutOptions);

        if (!scoredSectors.length) return;
        const barOptions = chartOptions('bar', [{ name: 'Rerata skor sektor', data: scoredSectors.map((sector) => sector.average_score) }], scoredSectors.map((sector) => sector.name));
        barOptions.colors = ['#0297DE'];
        barOptions.chart.height = Math.max(280, scoredSectors.length * 52);
        barOptions.plotOptions = { bar: { horizontal: true, barHeight: '38%', borderRadius: 6 } };
        barOptions.xaxis = { ...barOptions.xaxis, min: 0, max: 100, tickAmount: 4 };
        barOptions.yaxis = { labels: { maxWidth: 180, style: { fontSize: '12px', fontWeight: 600 } } };
        barOptions.tooltip.custom = ({ dataPointIndex }) => {
            const sector = scoredSectors[dataPointIndex];
            return tooltipText([sector.name, `${formatScore(sector.average_score)}/100`, `${sector.scored_count} UMKM berskor dari ${sector.total_count} UMKM`, `${sector.draft_count} Sementara`]);
        };
        mount(bar, barOptions);
    });
    load('/dashboard/distribution');
}
