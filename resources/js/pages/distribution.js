import { chartHost, chartOptions, createChartPage, emptyState, tooltipText } from '../charts';
import { element, formatNumber, formatScore } from '../ui';

export function init() {
    const root = document.getElementById('page-data');
    const load = createChartPage(root, (data, mount) => {
        if (!data.total_umkms) { root.append(emptyState('Belum ada UMKM', 'Tambahkan UMKM untuk melihat distribusi kategori dan sektor.', '/umkms', 'Tambah UMKM')); return; }
        const donut = chartHost('Donut jumlah UMKM per kategori, termasuk belum dinilai');
        const bar = chartHost('Bar rerata skor kesehatan per sektor, skala 0 sampai 100');
        const categories = data.categories.filter((category) => category.count > 0);
        const scoredSectors = data.sectors.filter((sector) => sector.average_score !== null);
        const legend = element('ul', { class: 'category-legend', 'aria-label': 'Jumlah UMKM per kategori' }, data.categories.map((category) => element('li', {}, [
            element('span', {}, [element('i', { class: 'chart-key', style: `--series-color:${category.color}`, 'aria-hidden': true }), category.label]),
            element('strong', { text: formatNumber(category.count) }),
        ])));
        const sectors = element('ul', { class: 'sector-list', 'aria-label': 'Rincian skor dan sampel per sektor' }, data.sectors.map((sector) => element('li', {}, [
            element('div', {}, [element('strong', { text: sector.name }), element('span', { text: sector.average_score === null ? 'Belum dinilai' : `${formatScore(sector.average_score)}/100` })]),
            element('p', { class: 'muted', text: `${sector.scored_count} UMKM berskor dari ${sector.total_count} UMKM · ${sector.draft_count} Sementara` }),
        ])));
        root.append(element('div', { class: 'distribution-grid' }, [
            element('section', { class: 'panel chart-panel' }, [element('h2', { text: 'Kategori kesehatan' }), element('p', { class: 'muted chart-caption', text: `${formatNumber(data.total_umkms)} UMKM · termasuk yang belum dinilai` }), donut, legend]),
            element('section', { class: 'panel chart-panel' }, [element('h2', { text: 'Rerata skor per sektor' }), element('p', { class: 'muted chart-caption', text: 'Evaluasi terbaru per UMKM; skor kosong dikeluarkan dari rerata.' }), scoredSectors.length ? bar : emptyState('Belum ada skor sektor', 'Simpan evaluasi untuk melihat rerata. UMKM belum dinilai tetap dihitung pada kategori.'), sectors]),
        ]));
        const donutOptions = chartOptions('donut', categories.map((category) => category.count));
        donutOptions.labels = categories.map((category) => category.label);
        donutOptions.colors = categories.map((category) => category.color);
        donutOptions.plotOptions = { pie: { donut: { size: '78%' } } };
        donutOptions.stroke = { width: 2, colors: ['#fff'] };
        donutOptions.tooltip.custom = ({ seriesIndex }) => tooltipText([categories[seriesIndex].label, `${categories[seriesIndex].count} dari ${data.total_umkms} UMKM`]);
        mount(donut, donutOptions);
        if (!scoredSectors.length) return;
        const barOptions = chartOptions('bar', [{ name: 'Rerata skor sektor', data: scoredSectors.map((sector) => sector.average_score) }], scoredSectors.map((sector) => sector.name));
        barOptions.plotOptions = { bar: { columnWidth: '42%', borderRadius: 3 } };
        barOptions.tooltip.custom = ({ dataPointIndex }) => {
            const sector = scoredSectors[dataPointIndex];
            return tooltipText([sector.name, `${formatScore(sector.average_score)}/100`, `${sector.scored_count} UMKM berskor dari ${sector.total_count} UMKM`, `${sector.draft_count} Sementara`]);
        };
        mount(bar, barOptions);
    });
    load('/dashboard/distribution');
}
