import ApexCharts from 'apexcharts';
import { pageApi } from './api';
import { chartHost, chartOptions } from './charts';
import { sixFactors } from './six-factor-draft';
import { draftProgress } from './six-factor-dashboard-data';
import { createUmkmPicker } from './umkm-picker';
import { element, showError } from './ui';

const factors = Object.entries(sixFactors);
const labels = ['SD', 'KP', 'LK', 'NO', 'ST', 'KI'];
const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
const date = (value) => value && Number.isFinite(new Date(value).getTime()) ? dateFormat.format(new Date(value)) : 'Tidak tersedia';

export function initSixFactorDashboard(signal) {
    const api = pageApi(signal);
    const root = document.getElementById('six-factor-section');
    const body = document.getElementById('home-six-body');
    const link = document.getElementById('home-six-open');
    const note = document.getElementById('home-six-note');
    const buttons = [...root.querySelectorAll('[data-six-tab]')];
    let selected = null;
    let tab = 'final';
    let history = [];
    let draft = null;
    let historical = null;
    let sequence = 0;
    let chart;

    function setTab(next) {
        tab = next;
        historical = null;
        render();
    }

    buttons.forEach((button) => button.addEventListener('click', () => setTab(button.dataset.sixTab), { signal }));
    document.getElementById('home-six-history').addEventListener('click', () => setTab('history'), { signal });
    signal.addEventListener('abort', () => chart?.destroy(), { once: true });

    function infoCard(title, value, status, updated) {
        return element('div', { class: 'home-six-summary' }, [
            element('div', { class: 'home-six-summary-top' }, [element('span', { text: title }), element('span', { class: `pill ${tab === 'draft' ? 'pill-amber' : 'pill-green'}`, text: status })]),
            element('div', { class: 'home-six-major num', text: value }),
            element('strong', { text: selected.name }),
            element('p', { class: 'muted small', text: [selected.sector?.name, selected.province].filter(Boolean).join(' · ') }),
            element('div', { class: 'home-six-meta' }, [element('p', { class: 'muted small', text: 'Tanggal / pembaruan' }), element('strong', { text: date(updated) }), element('p', { class: 'muted small', text: 'Jenis evaluasi' }), element('strong', { text: 'Penilaian Enam Faktor · 6 variabel' })]),
        ]);
    }

    function factorCard(result, isDraft) {
        return element('div', { class: 'home-six-feature' }, [
            element('h3', { text: isDraft ? 'Progres pengisian Draft' : 'Nilai tiap faktor' }),
            element('p', { class: 'muted small', text: isDraft ? 'Hanya nilai yang sudah dipilih; belum ada total atau kategori Final.' : 'Nilai tersimpan pada hasil Final dan tidak dapat diubah.' }),
            ...factors.map(([key, label], index) => {
                const value = result.scores[key];
                return element('div', { class: 'home-six-factor' }, [
                    element('div', { class: 'home-six-factor-title' }, [element('strong', { text: `${labels[index]} · ${label}` }), element('span', { text: value == null ? 'Belum dipilih' : `${value} / 5` })]),
                    element('div', { class: 'home-six-track', 'aria-hidden': 'true' }, element('div', { class: 'home-six-fill', style: `width:${value == null ? 0 : value * 20}%` })),
                ]);
            }),
        ]);
    }

    function render() {
        chart?.destroy();
        chart = null;
        buttons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.sixTab === tab)));
        note.textContent = tab === 'draft' ? 'Draft menyimpan nilai sementara. Total skor dan kategori hanya tersedia pada hasil Final.' : 'Hasil Final terpisah dari skor kesehatan tiga dimensi.';
        body.replaceChildren();
        if (!selected) {
            body.append(element('p', { class: 'home-six-empty muted', text: 'Pilih UMKM untuk meninjau penilaian enam faktor.' }));
            return;
        }
        const result = tab === 'draft' ? draft : (historical || history[0]);
        if (tab === 'history') {
            if (!history.length) {
                body.append(element('div', { class: 'home-six-empty' }, [element('h3', { text: 'Belum ada histori Final' }), element('p', { class: 'muted', text: 'UMKM ini belum mempunyai penilaian yang pernah difinalisasi.' })]));
                return;
            }
            const list = element('div', { class: 'home-six-feature home-six-history' }, [element('h3', { text: 'Histori Final' }), element('p', { class: 'muted small', text: 'Urutan terbaru dulu; pilih hasil untuk melihat nilai dan radar Final.' })]);
            history.forEach((item) => list.append(element('button', { type: 'button', class: 'home-six-history-item', onclick: () => { tab = 'final'; historical = item; render(); } }, [element('span', {}, [element('strong', { text: `Final #${item.id} · ${item.category}` }), element('small', { text: date(item.finalized_at) })]), element('strong', { text: `${item.total_score}/30 →` })])));
            body.append(infoCard('UMKM terpilih', `${history.length} hasil`, 'Final', history[0].finalized_at), list);
            return;
        }
        if (!result) {
            body.append(element('div', { class: 'home-six-empty' }, [element('h3', { text: tab === 'draft' ? 'Tidak ada Draft aktif' : 'Belum ada hasil Final' }), element('p', { class: 'muted', text: tab === 'draft' ? 'UMKM ini belum memiliki Draft yang sedang dikerjakan.' : 'Mulai penilaian dari halaman penilaian enam faktor.' })]));
            return;
        }
        const isDraft = tab === 'draft';
        const count = draftProgress(result.scores);
        body.append(infoCard(isDraft ? 'Draft aktif' : historical ? 'Final historis' : 'Final terbaru', isDraft ? `${count}/6 terisi` : `${result.total_score}/30`, isDraft ? 'Draft belum Final' : result.category, isDraft ? result.updated_at : result.finalized_at), factorCard(result, isDraft));
        const chartBox = element('div', { class: 'home-six-chart' });
        body.append(chartBox);
        if (isDraft) {
            chartBox.append(element('h3', { text: `${count} dari 6 faktor terisi` }), element('p', { class: 'muted small', text: 'Draft belum ditampilkan sebagai radar Final.' }), element('div', { class: 'home-six-track' }, element('div', { class: 'home-six-fill', style: `width:${count / 6 * 100}%` })), element('span', { class: 'pill pill-amber', text: 'Draft aktif' }));
        } else {
            const host = chartHost('Radar hasil Final enam faktor skala satu sampai lima');
            chartBox.append(element('h3', { text: 'Profil enam faktor' }), host, element('p', { class: 'muted small', text: 'Skala 1–5 · SD, KP, LK, NO, ST, KI' }));
            const options = chartOptions('radar', [{ name: 'Nilai faktor', data: factors.map(([key]) => result.scores[key]) }], labels);
            options.chart.height = 260;
            options.yaxis = { min: 1, max: 5, tickAmount: 4, labels: { formatter: (value) => new Intl.NumberFormat('id-ID').format(value) } };
            options.xaxis.labels = { style: { colors: labels.map(() => 'var(--text-muted)'), fontSize: '12px' } };
            options.grid.padding = { top: 16, left: 12, right: 12 };
            options.tooltip = { enabled: false };
            options.markers = { size: 4 };
            chart = new ApexCharts(host, options);
            chart.render().catch(() => { if (host.isConnected) host.replaceChildren(element('p', { text: 'Radar belum dapat ditampilkan.' })); });
        }
    }

    async function load() {
        const current = ++sequence;
        chart?.destroy(); chart = null;
        historical = null;
        history = []; draft = null;
        if (!selected) {
            buttons.forEach((button) => { button.disabled = false; });
            document.getElementById('home-six-history').disabled = false;
            body.setAttribute('aria-busy', 'false');
            render();
            return;
        }
        buttons.forEach((button) => { button.disabled = true; });
        document.getElementById('home-six-history').disabled = true;
        body.setAttribute('aria-busy', 'true');
        body.replaceChildren(element('p', { class: 'muted', text: 'Memuat penilaian…' }));
        let loaded = false;
        try {
            const path = `/umkms/${selected.id}/six-factor-evaluations`;
            const [historyResponse, draftResponse] = await Promise.all([api(path), api(`${path}/draft`)]);
            if (current !== sequence || signal.aborted) return;
            history = historyResponse.data;
            draft = draftResponse.data;
            loaded = true;
            render();
        } catch (error) {
            if (current === sequence && !signal.aborted && error.name !== 'AbortError') showError(body, error, load);
        } finally {
            if (current === sequence && !signal.aborted) {
                body.setAttribute('aria-busy', 'false');
                buttons.forEach((button) => { button.disabled = !loaded; });
                document.getElementById('home-six-history').disabled = !loaded;
            }
        }
    }

    createUmkmPicker(document.getElementById('home-six-picker'), { signal, onChange: (item) => {
        selected = item;
        link.href = item ? `/umkms/${item.id}/six-factor-evaluations` : '/umkms?mode=six-factor';
        return load();
    } });
    render();
}
