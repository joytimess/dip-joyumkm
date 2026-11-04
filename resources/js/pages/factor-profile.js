import { createUmkmPicker } from '../umkm-picker';
import { chartHost, chartOptions, createChartPage, emptyState, evaluationLabel, portfolioLabel, scoreTable, tooltipText } from '../charts';
import { element, formatScore } from '../ui';

export async function init() {
    const root = document.getElementById('page-data');
    const load = createChartPage(root, (data, mount) => {
        const complete = data.selected_scores.every((score) => score !== null);
        const status = `${evaluationLabel(data.evaluation_status)} · ${data.answered_count}/${data.total_questions} soal terjawab`;
        const host = chartHost('Radar skor faktor UMKM dibandingkan rerata seluruh portofolio, skala 0 sampai 100');
        const panel = element('section', { class: 'panel chart-panel' }, [
            element('div', { class: 'chart-heading' }, [element('h2', { text: data.umkm.name }), element('span', { class: data.evaluation_status === 'draft' ? 'draft-badge' : 'muted', text: status })]),
            complete ? host : emptyState('Data faktor belum lengkap', 'Nilai yang tersedia ditampilkan di bawah. Lengkapi kuesioner untuk melihat radar.', `/form-data?umkm=${data.umkm.id}`, 'Lengkapi kuesioner'),
            scoreTable(data.factors, [
                { name: data.umkm.name, scores: data.selected_scores, detail: status, color: '#5844b6' },
                { name: portfolioLabel, scores: data.portfolio_averages, detail: data.factors.map((code, index) => `${code}: ${data.sample_count_per_factor[index]} UMKM`).join(' · '), color: '#64748b' },
            ], 'Skor faktor 0–100; faktor kosong tidak dihitung dalam rerata.'),
            element('a', { href: `/form-data?umkm=${data.umkm.id}`, class: 'text-link', text: 'Isi atau perbarui kuesioner' }),
        ]);
        root.append(panel);
        if (!complete) return;
        const options = chartOptions('radar', [
            { name: 'UMKM terpilih', data: data.selected_scores },
            { name: portfolioLabel, data: data.portfolio_averages },
        ], data.factors);
        options.colors = ['#5844b6', '#64748b'];
        options.fill = { opacity: [0.13, 0.04] };
        options.stroke.dashArray = [0, 5];
        options.markers = { size: 3 };
        options.tooltip.custom = ({ seriesIndex, dataPointIndex }) => tooltipText([
            seriesIndex === 0 ? data.umkm.name : portfolioLabel,
            `${data.factors[dataPointIndex]}: ${formatScore((seriesIndex === 0 ? data.selected_scores : data.portfolio_averages)[dataPointIndex])}/100`,
            seriesIndex === 0 ? status : `${data.sample_count_per_factor[dataPointIndex]} UMKM berskor`,
        ]);
        mount(host, options);
    });
    async function select(umkm) {
        const url = new URL(window.location.href);
        if (umkm) url.searchParams.set('umkm_id', umkm.id);
        else url.searchParams.delete('umkm_id');
        history.replaceState(null, '', url);
        if (umkm) await load(`/dashboard/factor-profile?${new URLSearchParams({ umkm_id: umkm.id })}`);
        else { load.cancel(); root.replaceChildren(emptyState('Pilih UMKM terlebih dahulu', 'Pilih usaha untuk membandingkan skor INS, ECT, dan OPS.')); }
    }
    const initialId = new URLSearchParams(window.location.search).get('umkm_id');
    root.setAttribute('aria-busy', 'false');
    root.replaceChildren(emptyState('Pilih UMKM terlebih dahulu', 'Pilih usaha untuk membandingkan skor INS, ECT, dan OPS.'));
    await createUmkmPicker(document.getElementById('umkm-picker'), { initialId, autoSelect: initialId === null, onChange: select });
}
