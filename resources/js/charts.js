import ApexCharts from 'apexcharts';
import { api } from './api';
import { element, formatScore, showError } from './ui';

export const portfolioLabel = 'Rerata seluruh portofolio';
export const seriesColors = ['#0207CA', '#0297DE', '#FC734D', '#147D5B', '#8B4A25'];
export const evaluationLabel = (status) => ({ draft: 'Sementara', final: 'Final', none: 'Belum dinilai' })[status];

export function chartOptions(type, series, categories = []) {
    return {
        chart: { type, height: 320, fontFamily: 'Plus Jakarta Sans, sans-serif', foreColor: '#59657a', toolbar: { show: false }, animations: { enabled: false }, parentHeightOffset: 0 },
        series, colors: seriesColors,
        dataLabels: { enabled: false }, legend: { show: false },
        stroke: { width: 2, curve: 'straight' },
        grid: { borderColor: '#e9ecf2', strokeDashArray: 3 },
        xaxis: { categories, tooltip: { enabled: false }, axisBorder: { show: false }, axisTicks: { show: false } },
        yaxis: { min: 0, max: 100, tickAmount: 4, labels: { formatter: (value) => formatScore(value) } },
        tooltip: { shared: false, intersect: false, custom: ({ seriesIndex, dataPointIndex, w }) => tooltipText([
            w.config.series[seriesIndex].name,
            `${categories[dataPointIndex]}: ${formatScore(w.config.series[seriesIndex].data[dataPointIndex])}/100`,
        ]) },
    };
}

export function tooltipText(lines) {
    return element('div', { class: 'chart-tooltip' }, lines.map((text) => element('div', { text })));
}

export function chartHost(label) {
    return element('div', { class: 'chart-host', role: 'img', 'aria-label': label });
}

export function emptyState(title, description, href = '/umkms', action = 'Kelola UMKM') {
    return element('div', { class: 'empty-state chart-empty' }, [
        element('h2', { text: title }), element('p', { class: 'muted', text: description }),
        element('a', { class: 'text-link', href, text: action }),
    ]);
}

export function scoreTable(factors, rows, caption) {
    return element('div', { class: 'chart-table-wrap', tabindex: 0, 'aria-label': caption }, [
        element('table', { class: 'chart-table' }, [
            element('caption', { text: caption }),
            element('thead', {}, [element('tr', {}, ['UMKM / pembanding', ...factors, 'Keterangan'].map((label) => element('th', { scope: 'col', text: label })))]),
            element('tbody', {}, rows.map((row) => element('tr', {}, [
                element('th', { scope: 'row' }, [row.color ? element('span', { class: 'chart-key', style: `--series-color:${row.color}`, 'aria-hidden': true }) : null, row.name]),
                ...row.scores.map((score) => element('td', { text: score === null ? 'Belum dijawab' : formatScore(score) })),
                element('td', { class: 'chart-detail', text: row.detail }),
            ]))),
        ]),
    ]);
}

export function createChartPage(root, render) {
    let charts = [];
    let controller;
    let sequence = 0;
    function clear() {
        charts.forEach((chart) => chart.destroy());
        charts = [];
    }
    async function load(path) {
        const requestSequence = ++sequence;
        controller?.abort();
        controller = new AbortController();
        clear();
        root.setAttribute('aria-busy', 'true');
        root.replaceChildren(element('div', { class: 'panel', role: 'status', 'aria-label': 'Memuat grafik' }, [element('div', { class: 'skeleton h-64' })]));
        try {
            const { data } = await api(path, { signal: controller.signal });
            if (requestSequence !== sequence) return;
            root.replaceChildren();
            const pending = [];
            render(data, (host, options) => {
                const chart = new ApexCharts(host, options);
                charts.push(chart);
                pending.push(chart.render());
            });
            await Promise.all(pending);
        } catch (error) {
            if (error.name !== 'AbortError' && requestSequence === sequence) {
                clear();
                showError(root, error, () => load(path));
            }
        } finally {
            if (requestSequence === sequence) root.setAttribute('aria-busy', 'false');
        }
    }
    window.addEventListener('pagehide', () => { sequence++; controller?.abort(); clear(); });
    load.cancel = () => { sequence++; controller?.abort(); clear(); root.setAttribute('aria-busy', 'false'); };
    return load;
}
