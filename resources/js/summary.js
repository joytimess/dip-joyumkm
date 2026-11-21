import { pageApi } from './api';
import { element, formatNumber, formatScore, showError } from './ui';

export function createSummary(root, signal, onRender = () => {}) {
    const api = pageApi(signal);
    const cards = root.querySelector('#summary-cards');
    const state = document.getElementById('summary-state') || root.querySelector('#summary-state');
    const placeholders = [...cards.children];
    let hasData = false;
    let controller;
    let sequence = 0;
    const feedback = element('div', { hidden: true });
    root.append(feedback);

    async function reload() {
        const current = ++sequence;
        controller?.abort();
        controller = new AbortController();
        root.setAttribute('aria-busy', 'true');
        feedback.hidden = true;
        if (!hasData) cards.replaceChildren(...placeholders.map((node) => node.cloneNode(true)));
        if (state) state.textContent = 'Memuat ringkasan…';
        try {
            const { data } = await api('/umkms/summary', { signal: controller.signal });
            if (current !== sequence || signal.aborted) return;
            render(data);
            hasData = true;
            if (state) state.textContent = '';
        } catch (error) {
            if (error.name === 'AbortError' || current !== sequence) return;
            if (state) state.textContent = 'Ringkasan belum dapat dimuat.';
            if (!hasData) cards.replaceChildren();
            feedback.hidden = false;
            showError(feedback, error, reload);
        } finally { if (current === sequence) root.setAttribute('aria-busy', 'false'); }
    }

    function createMetricCard({ label, value, unit = '', foot, href = null }) {
        const card = element(href ? 'a' : 'article', {
            class: 'card metric panel',
            href,
        }, [
            element('div', { class: 'metric-label', text: label }),
            element('div', { class: 'metric-value' }, [
                element('span', { text: value }),
                unit ? element('span', { class: 'metric-suffix', text: unit }) : null,
            ]),
            element('div', { class: 'metric-foot', text: foot }),
        ]);
        return card;
    }

    function render(summary) {
        const critical = summary.critical_sector;
        cards.replaceChildren(
            createMetricCard({
                label: 'Total UMKM',
                value: formatNumber(summary.total_umkms),
                foot: `${formatNumber(summary.total_respondents)} usaha dengan evaluasi`,
                href: '/umkms',
            }),
            createMetricCard({
                label: 'Rata-rata skor',
                value: formatScore(summary.average_score),
                unit: summary.average_score === null ? '' : '/100',
                foot: summary.average_score === null ? 'Belum ada skor portofolio' : `${summary.category} · ${formatNumber(summary.scored_count)} usaha berskor`,
            }),
            createMetricCard({
                label: 'Sektor prioritas',
                value: critical ? formatScore(critical.average_score) : '—',
                unit: critical ? '/100' : '',
                foot: critical ? `${critical.name} · ${critical.needs_intervention ? 'Butuh intervensi' : 'Rerata terendah'}` : 'Belum ada data sektor',
            }),
            createMetricCard({
                label: 'Draft aktif',
                value: formatNumber(summary.draft_count),
                foot: 'Evaluasi yang belum final',
                href: '/umkms?status=draft',
            }),
        );
        onRender(summary);
    }

    reload();
    return { reload };
}
