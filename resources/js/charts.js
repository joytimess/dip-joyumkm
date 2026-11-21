import ApexCharts from 'apexcharts';
import { pageApi } from './api';
import { element, formatScore, showError, skeleton } from './ui';

export const portfolioLabel = 'Rerata seluruh portofolio';
export const seriesColors = ['#0207CA', '#0297DE', '#FC734D', '#147D5B', '#8B4A25'];
export const evaluationLabel = (status) => ({ draft: 'Sementara', final: 'Final', none: 'Belum dinilai' })[status];

export function chartOptions(type, series, categories = []) {
    const tokens = getComputedStyle(document.documentElement);
    const fontFamily = tokens.getPropertyValue('--font-sans').trim();
    const foreColor = tokens.getPropertyValue('--text-muted').trim();
    return {
        chart: { type, height: 320, fontFamily, foreColor, toolbar: { show: false }, animations: { enabled: false }, parentHeightOffset: 0 },
        series, colors: seriesColors,
        dataLabels: { enabled: false }, legend: { show: false },
        stroke: { width: 2, curve: 'straight' },
        grid: { borderColor: tokens.getPropertyValue('--border').trim(), strokeDashArray: 3 },
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
    const table = element('div', { class: 'chart-table-wrap', role: 'region', tabindex: 0, 'aria-label': caption }, [
        element('table', { class: 'chart-table' }, [
            element('caption', { text: caption }),
            element('thead', {}, [element('tr', {}, ['UMKM / pembanding', ...factors, 'Keterangan'].map((label) => element('th', { scope: 'col', text: label })))]),
            element('tbody', {}, rows.map((row) => element('tr', { class: row.baseline ? 'comparison-baseline' : null }, [
                element('th', { scope: 'row' }, [row.color ? element('span', { class: 'chart-key', style: `--series-color:${row.color}`, 'aria-hidden': true }) : null, row.name]),
                ...row.scores.map((score) => element('td', { text: score === null ? 'Belum dijawab' : formatScore(score) })),
                element('td', { class: 'chart-detail' }, row.statusClass
                    ? element('span', { class: `pill ${row.statusClass}`, text: row.detail })
                    : row.detail),
            ]))),
        ]),
    ]);
    return element('div', {}, [
        element('p', { class: 'chart-table-hint', text: 'Geser tabel untuk melihat semua kolom.' }),
        table,
    ]);
}

export function createChartPage(root, signal, render) {
    const api = pageApi(signal);
    let charts = [];
    let controller;
    let sequence = 0;
    let displayedPath;
    const placeholder = root.firstElementChild;
    function clear() {
        charts.forEach((chart) => chart.destroy());
        charts = [];
    }
    async function load(path) {
        if (signal.aborted) return;
        const requestSequence = ++sequence;
        controller?.abort();
        controller = new AbortController();
        root.setAttribute('aria-busy', 'true');
        if (displayedPath !== path) {
            displayedPath = undefined;
            clear();
            root.replaceChildren(placeholder?.cloneNode(true) || element('div', { class: 'panel card' }, [skeleton('h-64', 'h-3 w-1/3', 'h-3 w-1/4')]));
        }
        try {
            const { data } = await api(path, { signal: controller.signal });
            if (requestSequence !== sequence) return;
            displayedPath = undefined;
            clear();
            root.replaceChildren();
            const pending = [];
            render(data, (host, options) => {
                const chart = new ApexCharts(host, options);
                charts.push(chart);
                pending.push(chart.render());
            });
            await Promise.all(pending);
            if (requestSequence === sequence) displayedPath = path;
        } catch (error) {
            if (error.name !== 'AbortError' && requestSequence === sequence) {
                if (displayedPath === path && error.status !== 403) {
                    root.querySelector('[data-chart-error]')?.remove();
                    const feedback = element('div', { 'data-chart-error': true });
                    root.append(feedback);
                    showError(feedback, error, () => load(path));
                } else {
                    displayedPath = undefined;
                    clear();
                    showError(root, error, () => load(path));
                }
            }
        } finally {
            if (requestSequence === sequence) root.setAttribute('aria-busy', 'false');
        }
    }
    signal.addEventListener('abort', () => { sequence++; controller?.abort(); clear(); }, { once: true });
    load.cancel = () => { sequence++; controller?.abort(); displayedPath = undefined; clear(); root.setAttribute('aria-busy', 'false'); };
    return load;
}
