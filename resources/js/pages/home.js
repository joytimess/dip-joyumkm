import { createSummary } from '../summary';
import { pageApi } from '../api';
import { chartHost, chartOptions, createChartPage, emptyState, evaluationLabel, tooltipText } from '../charts';
import { element, formatNumber, formatScore, showError } from '../ui';
import { initSixFactorDashboard } from '../six-factor-dashboard';

function renderStatusBadge(status) {
    if (status === 'final') return element('span', { class: 'pill pill-green', text: 'Final' });
    if (status === 'draft') return element('span', { class: 'pill pill-amber', text: 'Sementara' });
    return element('span', { class: 'pill pill-muted', text: 'Belum dinilai' });
}

export function init(signal) {
    initSixFactorDashboard(signal);
    const api = pageApi(signal);
    const draftCaption = document.getElementById('home-draft-caption');
    const summary = createSummary(document.getElementById('umkm-summary'), signal, (data) => {
        if (draftCaption) {
            draftCaption.textContent = `${formatNumber(data.draft_count)} UMKM memiliki evaluasi berstatus sementara`;
        }
    });

    const health = document.getElementById('home-health');
    const loadHealth = createChartPage(health, signal, (data, mount) => {
        if (!data.total_umkms) {
            health.append(emptyState('Portofolio Anda dimulai di sini', 'Tambahkan UMKM pertama untuk mulai memahami kesehatan usaha.', '/umkms', 'Tambah UMKM'));
            return;
        }
        const categories = data.categories.filter((item) => item.count > 0);
        const host = chartHost('Distribusi jumlah UMKM berdasarkan kategori kesehatan');
        health.append(element('div', { class: 'health-row home-health-layout' }, [
            host,
            element('div', { class: 'legend-list' }, data.categories.map((item) => element('div', { class: 'legend-item' }, [
                element('span', { class: 'dot', style: `background:${item.color}` }),
                element('span', { text: item.label }),
                element('strong', { text: formatNumber(item.count) }),
            ]))),
        ]));
        const options = chartOptions('donut', categories.map((item) => item.count));
        options.chart.height = 230;
        options.labels = categories.map((item) => item.label);
        options.colors = categories.map((item) => item.color);
        options.stroke = { width: 3, colors: ['#fff'] };
        options.plotOptions = {
            pie: {
                donut: {
                    size: '76%',
                    labels: {
                        show: true,
                        value: { fontSize: '26px', fontWeight: 700, color: 'var(--text-primary)', formatter: formatNumber },
                        total: { show: true, showAlways: true, label: 'Total UMKM', color: options.chart.foreColor, fontSize: '11px', formatter: () => formatNumber(data.total_umkms) },
                    },
                },
            },
        };
        options.tooltip.custom = ({ seriesIndex }) => tooltipText([categories[seriesIndex].label, `${formatNumber(categories[seriesIndex].count)} UMKM`]);
        mount(host, options);
    });

    const priorities = document.getElementById('home-priorities');
    async function loadPriorities() {
        priorities.setAttribute('aria-busy', 'true');
        try {
            const { data } = await api('/umkms?sort=score&direction=asc&per_page=4');
            if (!data.length) {
                priorities.replaceChildren(emptyState('Belum ada UMKM', 'Tambahkan data usaha untuk memulai pendampingan.', '/umkms', 'Tambah UMKM'));
                return;
            }
            const table = element('div', { class: 'data-table-wrap' }, [
                element('table', { class: 'data-table' }, [
                    element('thead', {}, [
                        element('tr', {}, [
                            element('th', { text: 'UMKM' }),
                            element('th', { text: 'Sektor usaha' }),
                            element('th', { text: 'Skor kesehatan' }),
                            element('th', { text: 'Status' }),
                            element('th', { text: 'Aksi' }),
                        ]),
                    ]),
                    element('tbody', {}, data.map((umkm) => element('tr', {}, [
                        element('td', {}, [
                            element('strong', { text: umkm.name }),
                            element('div', { class: 'small muted', style: 'margin-top:3px', text: `${umkm.province}` }),
                        ]),
                        element('td', { text: umkm.sector?.name || '—' }),
                        element('td', {}, [
                            element('strong', { text: umkm.latest_score === null ? '—' : `${formatScore(umkm.latest_score)}/100` }),
                        ]),
                        element('td', {}, [renderStatusBadge(umkm.evaluation_status)]),
                        element('td', {}, [
                            element('a', { class: 'table-link row-action', href: `/factor-profile?umkm_id=${umkm.id}`, text: 'Lihat faktor' }),
                        ]),
                    ]))),
                ]),
            ]);
            priorities.replaceChildren(table);
        } catch (error) {
            if (!signal.aborted && error.name !== 'AbortError') showError(priorities, error, loadPriorities);
        } finally {
            priorities.setAttribute('aria-busy', 'false');
        }
    }

    loadHealth('/dashboard/distribution');
    loadPriorities();
    window.addEventListener('joyumkm:evaluation-saved', () => {
        summary.reload();
        loadHealth('/dashboard/distribution');
        loadPriorities();
    }, { signal });
}
