import { createUmkmPicker } from '../umkm-picker';
import { chartHost, chartOptions, createChartPage, emptyState, evaluationLabel, portfolioLabel, scoreTable, seriesColors, tooltipText } from '../charts';
import { element, formatScore, toast } from '../ui';

export async function init() {
    const root = document.getElementById('page-data');
    const selectedList = document.getElementById('comparison-selected');
    const add = document.getElementById('comparison-add');
    const clear = document.getElementById('comparison-clear');
    let selected = [];
    let picker;
    const status = (umkm) => `${evaluationLabel(umkm.evaluation_status)} · ${umkm.answered_count}/${umkm.total_questions} soal terjawab`;
    const load = createChartPage(root, (data, mount) => {
        selected = data.umkms;
        renderSelection();
        if (!selected.length) {
            root.append(emptyState('Belum ada UMKM dipilih', 'Pilih maksimal lima UMKM untuk membandingkan skor faktor. Pilihan otomatis memakai UMKM berskor tertinggi.'));
            return;
        }
        const host = chartHost('Bar skor faktor tiap UMKM dan garis rerata seluruh portofolio, skala 0 sampai 100');
        root.append(element('section', { class: 'panel chart-panel' }, [
            element('h2', { text: 'Perbandingan skor faktor' }),
            element('p', { class: 'muted chart-caption', text: `${selected.length} UMKM dibandingkan dengan seluruh portofolio. Faktor kosong ditandai “Belum dijawab”.` }),
            host,
            scoreTable(data.factors, [
                ...selected.map((umkm, index) => ({ name: umkm.name, scores: umkm.scores, detail: status(umkm), color: seriesColors[index] })),
                { name: portfolioLabel, scores: data.portfolio_averages, detail: data.factors.map((code, index) => `${code}: ${data.sample_count_per_factor[index]} UMKM`).join(' · '), color: '#334155' },
            ], 'Skor faktor 0–100 dan jumlah sampel baseline seluruh UMKM Anda.'),
        ]));
        const options = chartOptions('line', [
            ...selected.map((umkm, index) => ({ name: `UMKM ${index + 1}`, type: 'column', data: umkm.scores })),
            { name: portfolioLabel, type: 'line', data: data.portfolio_averages },
        ], data.factors);
        options.colors = [...selected.map((_, index) => seriesColors[index]), '#334155'];
        options.stroke = { width: [...selected.map(() => 0), 3], curve: 'straight', dashArray: [...selected.map(() => 0), 5] };
        options.markers = { size: [...selected.map(() => 0), 4] };
        options.fill = { opacity: 1 };
        options.plotOptions = { bar: { columnWidth: '65%', borderRadius: 2, hideZeroBarsWhenGrouped: false } };
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
        selectedList.replaceChildren(...selected.map((umkm) => element('li', {}, [
            element('span', { text: umkm.name }),
            element('button', { type: 'button', class: 'row-action', text: 'Hapus pilihan', 'aria-label': `Hapus ${umkm.name} dari perbandingan`, onclick: () => { selected = selected.filter((item) => item.id !== umkm.id); update(); } }),
        ])));
        document.getElementById('comparison-count').textContent = `${selected.length} dari maksimal 5 UMKM dipilih`;
        add.disabled = !picker?.selected || selected.length >= 5;
        clear.disabled = !selected.length;
    }

    function update() {
        renderSelection();
        const url = new URL(window.location.href);
        url.search = '';
        if (selected.length) selected.forEach((umkm) => url.searchParams.append('umkm_ids[]', umkm.id));
        else url.searchParams.set('umkm_ids', '');
        history.replaceState(null, '', url);
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
        history.replaceState(null, '', window.location.pathname);
        load('/dashboard/comparison');
    });
    load(`/dashboard/comparison${window.location.search}`);
    picker = await createUmkmPicker(document.getElementById('umkm-picker'), { onChange: (umkm) => { add.disabled = !umkm || selected.length >= 5; } });
    renderSelection();
}
