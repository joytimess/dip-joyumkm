import { api } from './api';
import { element, formatNumber, formatScore, showError } from './ui';

export function createSummary(root) {
    const cards = root.querySelector('#summary-cards');
    const state = root.querySelector('#summary-state');
    let categories;
    let controller;
    let sequence = 0;

    async function reload() {
        const current = ++sequence;
        controller?.abort();
        controller = new AbortController();
        root.setAttribute('aria-busy', 'true');
        state.textContent = 'Memuat ringkasan…';
        try {
            const [summaryResult, optionsResult] = await Promise.all([
                api('/umkms/summary', { signal: controller.signal }),
                categories ? Promise.resolve(null) : api('/umkms/options', { signal: controller.signal }),
            ]);
            if (current !== sequence) return;
            if (optionsResult) categories = optionsResult.data.categories;
            render(summaryResult.data);
            state.textContent = '';
        } catch (error) {
            if (error.name === 'AbortError' || current !== sequence) return;
            state.textContent = 'Ringkasan belum dapat dimuat.';
            cards.replaceChildren();
            showError(cards, error, reload);
        } finally { if (current === sequence) root.setAttribute('aria-busy', 'false'); }
    }

    function render(summary) {
        const category = categories.find((item) => item.label === summary.category);
        const categoryLabel = element('strong', { class: 'summary-category', text: summary.category });
        categoryLabel.style.color = category?.color || '';
        const averageProgress = element('progress', { max: 100, value: summary.average_score ?? 0, 'aria-label': 'Rerata skor kesehatan', 'aria-valuetext': summary.average_score === null ? 'Belum dinilai' : `${formatScore(summary.average_score)} dari 100` });
        averageProgress.style.setProperty('--summary-color', category?.color || '#6b7280');
        const critical = summary.critical_sector;
        cards.replaceChildren(
            element('article', { class: 'panel summary-total' }, [
                element('h3', { class: 'summary-label', text: 'TOTAL RESPONDEN' }),
                element('p', { class: 'summary-value num', text: formatNumber(summary.total_respondents) }),
                element('p', { class: 'summary-caption', text: 'Evaluasi terbaru per UMKM' }),
                element('p', { class: 'summary-detail', text: `${formatNumber(summary.total_umkms)} UMKM terdaftar · ${formatNumber(summary.draft_count)} draft` }),
            ]),
            element('article', { class: 'panel summary-average' }, [
                element('h3', { class: 'summary-label', text: 'RERATA SKOR KESEHATAN' }),
                element('p', { class: 'summary-value num' }, [element('span', { text: formatScore(summary.average_score) }), element('small', { text: '/100' })]),
                averageProgress,
                categoryLabel,
                element('p', { class: 'summary-detail', text: `${formatNumber(summary.scored_count)} UMKM berskor · ${formatNumber(summary.draft_count)} draft` }),
            ]),
            element('article', { class: `panel ${critical ? 'summary-critical' : 'summary-neutral'}` }, [
                element('h3', { class: 'summary-label', text: 'SEKTOR KRITIS' }),
                element('p', { class: 'summary-sector', text: critical ? critical.name : 'Belum ada data' }),
                critical ? element('strong', { class: `intervention-label ${critical.needs_intervention ? 'needs-intervention' : 'is-controlled'}`, text: critical.needs_intervention ? 'BUTUH INTERVENSI SEGERA' : 'TERKENDALI' }) : element('p', { class: 'summary-caption', text: 'Belum ada UMKM dengan skor evaluasi.' }),
                critical ? element('p', { class: 'summary-detail', text: `Rerata ${formatScore(critical.average_score)}/100 · ${formatNumber(critical.scored_count)} UMKM berskor · ${formatNumber(critical.draft_count)} draft` }) : null,
            ]),
        );
    }

    reload();
    return { reload };
}
