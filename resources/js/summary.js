import { api } from './api';
import { element, formatNumber, formatScore, showError } from './ui';

function metricIconSvg(name) {
    const svgs = {
        store: '<path d="M3 10h18l-2-7H5zM4 10v11h16V10M9 21v-8h6v8M3 10c0 3 4 3 4 0 0 3 4 3 5 0 1 3 5 3 5 0 0 3 4 3 4 0"/>',
        trend: '<path d="m3 17 7-7 4 4 7-8M15 6h6v6"/>',
        circle: '<path d="M12 2v10h10A10 10 0 1 1 12 2Z"/><path d="M15 2a9.8 9.8 0 0 1 7 7h-7z"/>',
        clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 3"/>',
    };
    const node = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    node.setAttribute('class', 'icon');
    node.setAttribute('viewBox', '0 0 24 24');
    node.setAttribute('fill', 'none');
    node.setAttribute('stroke', 'currentColor');
    node.setAttribute('stroke-width', '1.8');
    node.setAttribute('stroke-linecap', 'round');
    node.setAttribute('stroke-linejoin', 'round');
    node.innerHTML = svgs[name] || '';
    return node;
}

export function createSummary(root, onRender = () => {}) {
    const cards = root.querySelector('#summary-cards');
    const state = document.getElementById('summary-state') || root.querySelector('#summary-state');
    let categories;
    let controller;
    let sequence = 0;

    async function reload() {
        const current = ++sequence;
        controller?.abort();
        controller = new AbortController();
        root.setAttribute('aria-busy', 'true');
        if (state) state.textContent = 'Memuat ringkasan…';
        try {
            const [summaryResult, optionsResult] = await Promise.all([
                api('/umkms/summary', { signal: controller.signal }),
                categories ? Promise.resolve(null) : api('/umkms/options', { signal: controller.signal }),
            ]);
            if (current !== sequence) return;
            if (optionsResult) categories = optionsResult.data.categories;
            render(summaryResult.data);
            if (state) state.textContent = '';
        } catch (error) {
            if (error.name === 'AbortError' || current !== sequence) return;
            if (state) state.textContent = 'Ringkasan belum dapat dimuat.';
            cards.replaceChildren();
            showError(cards, error, reload);
        } finally { if (current === sequence) root.setAttribute('aria-busy', 'false'); }
    }

    function createMetricCard({ iconName, theme = '', label, value, unit = '', foot, href = null }) {
        const card = element(href ? 'a' : 'article', {
            class: `card metric panel ${theme === 'orange' ? 'metric-accent' : ''}`,
            ...(href ? { href, style: 'text-decoration:none;color:inherit;display:block;' } : {}),
        }, [
            element('span', { class: 'soft-corner', 'aria-hidden': true }),
            element('div', { class: `metric-icon ${theme}` }, [metricIconSvg(iconName)]),
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
                iconName: 'store',
                label: 'Total responden',
                value: formatNumber(summary.total_umkms),
                unit: 'UMKM',
                foot: `${formatNumber(summary.total_respondents)} usaha dalam portofolio`,
                href: '/umkms',
            }),
            createMetricCard({
                iconName: 'trend',
                theme: 'blue',
                label: 'Rata-rata skor',
                value: formatScore(summary.average_score),
                unit: '/100',
                foot: `${summary.category || 'Kondisi portofolio'} · ${formatNumber(summary.scored_count)} berskor`,
            }),
            createMetricCard({
                iconName: 'circle',
                theme: 'orange',
                label: 'Sektor prioritas',
                value: critical ? formatScore(critical.average_score) : '—',
                unit: critical ? '/100' : '',
                foot: critical ? `${critical.name} · ${critical.needs_intervention ? 'Butuh intervensi' : 'Rerata terendah'}` : 'Belum ada data sektor',
            }),
            createMetricCard({
                iconName: 'clock',
                theme: 'blue',
                label: 'Draft aktif',
                value: formatNumber(summary.draft_count),
                unit: 'Draft',
                foot: 'Evaluasi yang belum final',
                href: '/umkms?status=draft',
            }),
        );
        onRender(summary);
    }

    reload();
    return { reload };
}
