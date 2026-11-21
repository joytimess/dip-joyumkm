import { createUmkmPicker } from '../umkm-picker';
import { chartHost, chartOptions, createChartPage, emptyState, evaluationLabel, portfolioLabel, scoreTable, seriesColors, tooltipText } from '../charts';
import { element, formatScore, icon, toast } from '../ui';

export async function init(signal) {
    const root = document.getElementById('page-data');
    const selectedList = document.getElementById('comparison-selected');
    const add = document.getElementById('comparison-add');
    const clear = document.getElementById('comparison-clear');
    let selected = [];
    let picker;
    const status = (umkm) => `${evaluationLabel(umkm.evaluation_status)} · ${umkm.answered_count}/${umkm.total_questions} soal terjawab`;
    const load = createChartPage(root, signal, (data, mount) => {
        selected = data.umkms;
        renderSelection();
        if (!selected.length) {
            root.append(emptyState('Belum ada UMKM dipilih', 'Pilih maksimal lima UMKM untuk membandingkan skor faktor. Pilihan otomatis memakai UMKM berskor tertinggi.'));
            return;
        }
        const host = chartHost('Bar skor faktor tiap UMKM dan garis rerata seluruh portofolio, skala 0 sampai 100');
        const hasScores = [...selected.flatMap((umkm) => umkm.scores), ...data.portfolio_averages].some((score) => score !== null);
        const legend = element('div', { class: 'legend-inline', style: 'justify-content:center;margin-top:14px;' }, [
            ...selected.map((umkm, index) => element('span', {}, [
                element('i', { class: 'legend-swatch', style: `background:${seriesColors[index]};`, 'aria-hidden': true }),
                element('span', { text: umkm.name, title: umkm.name }),
            ])),
            element('span', {}, [
                element('i', { class: 'baseline-key', style: '--series-color:#334155;', 'aria-hidden': true }),
                element('span', { text: portfolioLabel }),
            ]),
        ]);
        root.append(
            element('section', { class: 'card card-pad panel chart-panel', style: 'margin-bottom:20px;' }, [
                element('div', { class: 'section-top section-heading' }, [
                    element('div', {}, [
                        element('h2', { class: 'section-title', text: 'Perbandingan skor faktor' }),
                        element('p', { class: 'section-desc muted', text: `${selected.length} UMKM dibandingkan dengan seluruh portofolio. Faktor kosong ditandai “Belum dijawab”.` }),
                    ]),
                ]),
                hasScores ? host : emptyState('Belum ada skor untuk ditampilkan', 'Tabel di bawah menunjukkan faktor yang belum dijawab.'),
                hasScores ? legend : null,
            ]),
            element('section', { class: 'card card-pad panel chart-panel section-block comparison-detail' }, [
                element('div', { class: 'section-top section-heading' }, [
                    element('div', {}, [
                        element('h2', { class: 'section-title', text: 'Detail perbandingan' }),
                        element('p', { class: 'section-desc muted', text: 'Nilai numerik setiap faktor dan status evaluasi tersimpan.' }),
                    ]),
                ]),
                scoreTable(data.factors, [
                    ...selected.map((umkm, index) => ({ name: umkm.name, scores: umkm.scores, detail: status(umkm), color: seriesColors[index], statusClass: ({ final: 'pill-green', draft: 'pill-amber' })[umkm.evaluation_status] || 'pill-muted' })),
                    { name: portfolioLabel, scores: data.portfolio_averages, detail: data.factors.map((code, index) => `${code}: ${data.sample_count_per_factor[index]} UMKM`).join(' · '), color: '#334155', baseline: true },
                ], 'Skor faktor 0–100 dan jumlah sampel baseline seluruh UMKM Anda.'),
            ]),
        );
        if (!hasScores) return;
        const options = chartOptions('line', [
            ...selected.map((umkm) => ({ name: umkm.name, type: 'column', data: umkm.scores })),
            { name: portfolioLabel, type: 'line', data: data.portfolio_averages },
        ], data.factors);
        options.colors = [...selected.map((_, index) => seriesColors[index]), '#334155'];
        options.stroke = { width: [...selected.map(() => 0), 3], curve: 'straight', dashArray: [...selected.map(() => 0), 5] };
        options.markers = { size: [...selected.map(() => 0), 4] };
        options.fill = { opacity: 1 };
        options.plotOptions = { bar: { columnWidth: '58%', borderRadius: 5, hideZeroBarsWhenGrouped: false } };
        options.tooltip.custom = ({ seriesIndex, dataPointIndex }) => {
            const umkm = selected[seriesIndex];
            const value = (umkm ? umkm.scores : data.portfolio_averages)[dataPointIndex];
            return tooltipText([
                umkm ? umkm.name : portfolioLabel,
                `${data.factors[dataPointIndex]}: ${value === null ? 'Belum dijawab' : `${formatScore(value)}/100`}`,
                umkm ? `${status(umkm)} · ${umkm.sample_count_per_factor[dataPointIndex]} sampel faktor` : `${data.sample_count_per_factor[dataPointIndex]} UMKM berskor`,
            ]);
        };
        mount(host, options);
    });

    function renderSelection() {
        selectedList.replaceChildren(...selected.map((umkm, index) => element('li', { class: 'select-chip' }, [
            element('span', { class: 'comparison-chip-key', style: `background:${seriesColors[index]}`, 'aria-hidden': true }),
            element('span', { class: 'comparison-chip-content' }, [
                element('strong', { text: umkm.name, title: umkm.name }),
                element('small', { text: evaluationLabel(umkm.evaluation_status) || 'Memuat status…' }),
            ]),
            element('button', {
                type: 'button',
                'aria-label': `Hapus ${umkm.name} dari perbandingan`,
                onclick: () => { selected = selected.filter((item) => item.id !== umkm.id); update(); },
            }, icon('close')),
        ])));
        document.getElementById('comparison-count').textContent = `${selected.length} dari maksimal 5 UMKM dipilih`;
        document.getElementById('comparison-capacity').textContent = `${selected.length} / 5 terisi${selected.length === 5 ? ' · Penuh' : ''}`;
        add.disabled = !picker?.selected || selected.length >= 5 || selected.some((item) => item.id === picker.selected.id);
        clear.disabled = !selected.length;
    }

    function update() {
        renderSelection();
        const url = new URL(window.location.href);
        url.search = '';
        if (selected.length) selected.forEach((umkm) => url.searchParams.append('umkm_ids[]', umkm.id));
        else url.searchParams.set('umkm_ids', '');
        history.replaceState(history.state, '', url);
        window.dispatchEvent(new Event('joyumkm:query-updated'));
        load(`/dashboard/comparison?${url.searchParams}`);
    }

    add.addEventListener('click', () => {
        if (!picker?.selected) return;
        if (selected.some((item) => item.id === picker.selected.id)) { toast('UMKM ini sudah dipilih.', 'error'); return; }
        if (selected.length >= 5) { toast('Pilih maksimal lima UMKM.', 'error'); return; }
        selected.push(picker.selected);
        update();
    });
    clear.addEventListener('click', () => { selected = []; update(); });
    document.getElementById('comparison-default').addEventListener('click', () => {
        history.replaceState(history.state, '', window.location.pathname);
        window.dispatchEvent(new Event('joyumkm:query-updated'));
        load('/dashboard/comparison');
    });
    load(`/dashboard/comparison${window.location.search}`);
    picker = await createUmkmPicker(document.getElementById('umkm-picker'), { signal, onChange: (umkm) => { add.disabled = !umkm || selected.length >= 5 || selected.some((item) => item.id === umkm.id); } });
    if (!signal.aborted) renderSelection();
}
