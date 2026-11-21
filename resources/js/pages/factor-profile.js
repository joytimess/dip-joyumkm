import { createUmkmPicker } from '../umkm-picker';
import { syncUmkmBreadcrumb, withListContext } from '../umkm-navigation';
import { chartHost, chartOptions, createChartPage, emptyState, evaluationLabel, portfolioLabel, scoreTable, tooltipText } from '../charts';
import { element, formatScore } from '../ui';

function renderStatusBadge(status) {
    if (status === 'final') return element('span', { class: 'pill pill-green', text: 'Final' });
    if (status === 'draft') return element('span', { class: 'pill pill-amber', text: 'Sementara' });
    return element('span', { class: 'pill pill-muted', text: 'Belum dinilai' });
}

export async function init(signal) {
    const root = document.getElementById('page-data');
    let selectedBusiness;
    const load = createChartPage(root, signal, (data, mount) => {
        const complete = data.selected_scores.every((score) => score !== null);
        const status = `${evaluationLabel(data.evaluation_status)} · ${data.answered_count}/${data.total_questions} soal terjawab`;
        const formUrl = withListContext(`/form-data?umkm=${data.umkm.id}`);
        const host = chartHost('Radar skor faktor UMKM dibandingkan rerata seluruh portofolio, skala 0 sampai 100');
        const colors = ['#0207CA', '#0297DE', '#FC734D'];
        const business = selectedBusiness?.id === data.umkm.id ? selectedBusiness : null;
        const average = business?.latest_score ?? null;

        const metrics = element('div', { class: 'grid-3 metric-strip', style: 'margin-bottom:20px;' }, data.factors.map((factor, index) => {
            const score = data.selected_scores[index];
            const base = data.portfolio_averages[index];
            return element('section', { class: 'card metric panel' }, [
                element('div', { class: 'metric-label', text: `Faktor ${factor}` }),
                element('div', { class: 'metric-value' }, [
                    element('span', { text: formatScore(score) }),
                    score !== null ? element('span', { class: 'metric-suffix', text: '/100' }) : null,
                ]),
                element('div', { class: 'metric-foot', text: base === null ? 'Rerata portofolio belum tersedia' : `Rerata portofolio ${formatScore(base)}/100` }),
            ]);
        }));

        const legend = element('div', { class: 'legend-inline', style: 'justify-content:center;margin-top:12px;' }, [
            element('span', {}, [
                element('i', { class: 'legend-swatch', style: 'background:#0207CA;', 'aria-hidden': true }),
                element('span', { text: data.umkm.name }),
            ]),
            element('span', {}, [
                element('i', { class: 'legend-swatch', style: 'background:#0297DE;', 'aria-hidden': true }),
                element('span', { text: portfolioLabel }),
            ]),
        ]);

        const highestFactorIndex = data.selected_scores.reduce((maxI, cur, i, arr) => (cur ?? -1) > (arr[maxI] ?? -1) ? i : maxI, 0);
        const highestFactor = data.factors[highestFactorIndex];

        const radar = element('section', { class: 'card card-pad panel chart-panel' }, [
                    element('div', { class: 'section-top section-heading' }, [
                        element('div', {}, [
                            element('h2', { class: 'section-title', text: 'Radar profil usaha' }),
                            element('p', { class: 'section-desc muted', text: 'Skor 0–100 · pembanding adalah rata-rata seluruh portofolio.' }),
                        ]),
                    ]),
                    complete ? host : emptyState('Data faktor belum lengkap', 'Nilai yang tersedia tetap ditampilkan. Lengkapi kuesioner untuk melihat radar lengkap.', formUrl, 'Lengkapi kuesioner'),
                    legend,
        ]);
        root.append(
            metrics,
            element('div', { class: 'two-col analytics-grid' }, [
                element('section', { class: 'card card-pad panel' }, [
                    element('div', { class: 'section-top section-heading' }, [
                        element('div', {}, [
                            element('h2', { class: 'section-title', text: 'Ringkasan performa' }),
                            element('p', { class: 'section-desc muted', text: [data.umkm.name, business?.sector?.name].filter(Boolean).join(' · ') }),
                        ]),
                    ]),
                    element('div', { class: 'summary-stat hstack wrap', 'data-profile-average': true }, [
                        element('strong', { class: average === null ? 'muted' : 'metric-value', text: average === null ? 'Skor keseluruhan belum tersedia' : formatScore(average) }),
                        average === null ? null : element('span', { class: 'muted', text: '/100' }),
                    ]),
                    element('div', { style: 'margin-bottom:18px;' }, [
                        renderStatusBadge(data.evaluation_status),
                        element('span', { class: 'tiny muted', style: 'margin-left:8px;', text: `${data.answered_count}/${data.total_questions} soal terjawab` }),
                    ]),
                    element('div', { style: 'margin:20px 0 8px;font-size:12px;font-weight:700;', text: 'Skor per faktor' }),
                    element('div', { class: 'factor-score-list' }, data.factors.map((factor, index) => {
                        const val = data.selected_scores[index];
                        return element('div', { class: 'factor-row' }, [
                            element('span', { class: 'factor-name', text: factor }),
                            element('div', { class: 'progress' }, [
                                element('span', { style: `width:${Math.max(0, Math.min(100, Number(val) || 0))}%;background:${colors[index]}` }),
                            ]),
                            element('span', { class: 'factor-number', text: formatScore(val) }),
                        ]);
                    })),
                    data.selected_scores[highestFactorIndex] === null ? null : element('div', { class: 'note-card mt-4' }, [
                        element('div', {}, [
                            element('h4', { text: 'Pembacaan singkat' }),
                            element('p', { text: `${data.umkm.name} mencatat skor tertinggi pada faktor ${highestFactor} (${formatScore(data.selected_scores[highestFactorIndex])}/100). Bandingkan dengan garis baseline untuk prioritas pendampingan.` }),
                        ]),
                    ]),
                    element('div', { style: 'margin-top:20px;' }, [
                        element('a', { href: formUrl, class: 'btn btn-primary btn-full button button-primary w-full', text: 'Isi atau perbarui kuesioner' }),
                    ]),
                ]),
                radar,
            ]),
            element('section', { class: 'card card-pad panel chart-panel section-block' }, [
                element('div', { class: 'section-top section-heading' }, [
                    element('div', {}, [
                        element('h2', { class: 'section-title', text: 'Detail perbandingan' }),
                        element('p', { class: 'section-desc muted', text: 'Nilai tiap faktor dan pembanding portofolio.' }),
                    ]),
                ]),
                scoreTable(data.factors, [
                    { name: data.umkm.name, scores: data.selected_scores, detail: status, color: '#0207CA' },
                    { name: portfolioLabel, scores: data.portfolio_averages, detail: data.factors.map((code, index) => `${code}: ${data.sample_count_per_factor[index]} UMKM`).join(' · '), color: '#0297DE' },
                ], 'Skor faktor 0–100; faktor kosong tidak dihitung dalam rerata.'),
            ]),
        );

        if (!complete) return;
        const options = chartOptions('radar', [
            { name: data.umkm.name, data: data.selected_scores },
            { name: portfolioLabel, data: data.portfolio_averages },
        ], data.factors);
        options.colors = ['#0207CA', '#0297DE'];
        options.fill = { opacity: [0.14, 0.05] };
        options.stroke.dashArray = [0, 5];
        options.markers = { size: 4, strokeColors: '#fff', strokeWidth: 2 };
        options.tooltip.custom = ({ seriesIndex, dataPointIndex }) => tooltipText([
            seriesIndex === 0 ? data.umkm.name : portfolioLabel,
            `${data.factors[dataPointIndex]}: ${formatScore((seriesIndex === 0 ? data.selected_scores : data.portfolio_averages)[dataPointIndex])}/100`,
            seriesIndex === 0 ? status : `${data.sample_count_per_factor[dataPointIndex]} UMKM berskor`,
        ]);
        mount(host, options);
    });

    async function select(umkm) {
        selectedBusiness = umkm;
        syncUmkmBreadcrumb(umkm);
        const url = new URL(window.location.href);
        if (umkm) url.searchParams.set('umkm_id', umkm.id);
        else url.searchParams.delete('umkm_id');
        history.replaceState(history.state, '', url);
        window.dispatchEvent(new Event('joyumkm:query-updated'));
        if (umkm) await load(`/dashboard/factor-profile?${new URLSearchParams({ umkm_id: umkm.id })}`);
        else { load.cancel(); root.replaceChildren(emptyState('Pilih UMKM terlebih dahulu', 'Pilih usaha untuk membandingkan skor INS, ECT, dan OPS.')); }
    }
    const initialId = new URLSearchParams(window.location.search).get('umkm_id');
    const picker = await createUmkmPicker(document.getElementById('umkm-picker'), { signal, initialId, autoSelect: initialId === null, onChange: select });
    if (!signal.aborted && !picker.selected) {
        root.setAttribute('aria-busy', 'false');
        root.replaceChildren(emptyState('Pilih UMKM terlebih dahulu', 'Pilih usaha untuk membandingkan skor INS, ECT, dan OPS.'));
    }
}
