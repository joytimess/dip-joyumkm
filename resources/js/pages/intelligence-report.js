import { element, showError, skeleton } from '../ui';
import { normalizeHistory, normalizeReport } from './intelligence-report-data';
import { renderIntelligenceReport } from './intelligence-report-view';

const card = 'card card-pad panel intelligence-stack';
const stack = 'intelligence-stack';
const paragraph = (text, className) => text ? element('p', { class: className, text }) : null;
const button = (text, onclick, options = {}) => element('button', {
    type: 'button', class: 'button button-outline', text, onclick, ...options,
});

function emptyState(message, description) {
    return element('div', { class: 'empty-state' }, [
        element('strong', { text: message }),
        paragraph(description, 'muted'),
    ]);
}

function reportMetadata(report) {
    const evaluation = report.evaluationId
        ? element('span', { text: `Evaluasi #${report.evaluationId}` }) : null;
    const date = report.date
        ? element('time', { datetime: report.dateTime, text: report.date })
        : element('span', { text: 'Tanggal laporan belum tersedia' });
    return element('p', { class: 'section-desc' }, [evaluation, evaluation ? ' · ' : null, date]);
}

function methodologyBadge(report) {
    return report.partial ? element('span', { class: 'pill pill-amber', text: 'Metodologi parsial' }) : null;
}

export function createIntelligencePanel(panel, { api, path, signal, umkmId }) {
    const find = (name) => panel.querySelector(`[data-intelligence-${name}]`);
    const latest = find('latest');
    const reportMeta = find('report-meta');
    const selection = find('selection');
    const detail = find('detail');
    const history = find('history');
    const historyContent = find('history-content');
    const refresh = find('refresh');
    const status = find('status');
    const historyTitle = panel.querySelector('#intelligence-history-title');
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    const endpoint = `${path}/intelligence-reports`;
    let tabRequest;
    let detailRequest;
    let latestRequested = false;
    let pendingHistoryPage;
    let latestView;
    let detailView;

    const announce = (message) => { status.textContent = message; };
    const settleViews = () => {
        latestView?.settle();
        detailView?.settle();
    };

    function renderReport(payload, prefix, reportId) {
        const report = normalizeReport(payload, umkmId, reportId);
        const view = renderIntelligenceReport(report, prefix, historyTitle, reducedMotion);
        return { ...view, metadata: [reportMetadata(report), view.badge].filter(Boolean) };
    }

    function showMetadata(view) {
        reportMeta.replaceChildren(...(view?.metadata ?? []));
        reportMeta.hidden = !view;
    }

    async function loadRegion(region, requestSignal, label, request, render, retry) {
        const loadingMessage = `Memuat ${label}…`;
        region.setAttribute('aria-busy', 'true');
        const placeholder = skeleton('h-4 w-1/3', 'h-4 w-2/3', 'h-4 w-full');
        region.replaceChildren(region === latest ? element('div', { class: card }, [placeholder]) : placeholder);
        announce(loadingMessage);
        try {
            const result = await request();
            requestSignal.throwIfAborted();
            render(result);
        } catch (error) {
            if (requestSignal.aborted || error.name === 'AbortError' || error.status === 401) return;
            const missing = error.status === 404;
            const errorRegion = region === latest ? element('div', { class: 'card panel' }) : region;
            showError(errorRegion, {
                message: missing ? 'Data tidak ditemukan.' : `Gagal memuat ${label}. Silakan coba lagi.`,
            }, missing ? undefined : retry);
            if (errorRegion !== region) region.replaceChildren(errorRegion);
        } finally {
            if (!requestSignal.aborted) {
                region.setAttribute('aria-busy', 'false');
                if (status.textContent === loadingMessage) announce('');
            }
        }
    }

    async function fetchLatest() {
        if (refresh.getAttribute('aria-disabled') === 'true') return;
        tabRequest?.abort();
        tabRequest = new AbortController();
        const requestSignal = tabRequest.signal;
        latestRequested = true;
        refresh.setAttribute('aria-disabled', 'true');
        latestView?.settle();
        latestView = undefined;
        resetSelection();
        pendingHistoryPage = undefined;
        history.hidden = true;
        historyContent.replaceChildren();

        await loadRegion(latest, requestSignal, 'laporan terbaru', () => api(`${endpoint}/latest`, { signal: requestSignal }), (payload) => {
            if (payload.status === 'awaiting_report' && payload.data === null) {
                const message = 'Laporan Intelligence belum tersedia';
                latest.replaceChildren(element('div', { class: card }, [
                    emptyState(message, 'Muat ulang untuk memeriksa apakah laporan sudah tersedia.'),
                ]));
                announce(message);
                return;
            }
            if (payload.status !== 'available') throw new Error('Invalid latest state');
            latestView = renderReport(payload.data, 'intelligence-latest');
            showMetadata(latestView);
            latest.replaceChildren(...latestView.sections);
            history.hidden = false;
            void fetchHistory(1);
        }, () => {
            panel.querySelector('#intelligence-title').focus();
            void fetchLatest();
        });
        if (!requestSignal.aborted) refresh.setAttribute('aria-disabled', 'false');
    }

    async function fetchHistory(page, moveFocus = false) {
        const requestSignal = tabRequest.signal;
        pendingHistoryPage = page;
        if (moveFocus) historyTitle.focus();
        await loadRegion(historyContent, requestSignal, 'riwayat laporan',
            () => api(`${endpoint}?page=${page}&per_page=10`, { signal: requestSignal }),
            (payload) => {
                const { items, lastPage } = normalizeHistory(payload, umkmId, page);
                const entries = items.length ? element('ul', { class: `${stack} intelligence-history-list`, role: 'list' },
                    items.map((report) => element('li', { class: 'detail-panel-head intelligence-history-entry' }, [
                        element('div', { class: 'intelligence-report-meta' }, [reportMetadata(report), methodologyBadge(report)]),
                        button('Lihat laporan', () => void fetchDetail(report.id), {
                            'aria-label': report.evaluationId ? `Lihat laporan evaluasi ${report.evaluationId}` : 'Lihat laporan',
                        }),
                    ]))) : emptyState('Belum ada riwayat laporan');
                historyContent.replaceChildren(entries);
                if (page > 1 || page < lastPage) {
                    historyContent.append(element('nav', { class: 'table-footer', 'aria-label': 'Halaman riwayat laporan' }, [
                        paragraph(`Halaman ${page} dari ${lastPage}`, 'muted'),
                        element('div', { class: 'pagination' }, [
                            button('Sebelumnya', () => void fetchHistory(page - 1, true), { disabled: page <= 1 }),
                            button('Berikutnya', () => void fetchHistory(page + 1, true), { disabled: page >= lastPage }),
                        ]),
                    ]));
                }
                announce(items.length ? `Riwayat laporan, halaman ${page}.` : 'Belum ada riwayat laporan.');
            }, () => void fetchHistory(page, true));
        if (!requestSignal.aborted) pendingHistoryPage = undefined;
    }

    function resetSelection() {
        detailRequest?.abort();
        detailRequest = undefined;
        detailView?.settle();
        detailView = undefined;
        selection.hidden = true;
        detail.replaceChildren();
        detail.removeAttribute('aria-busy');
        latest.hidden = false;
        showMetadata(latestView);
    }

    async function fetchDetail(id) {
        resetSelection();
        const request = new AbortController();
        detailRequest = request;
        const requestSignal = AbortSignal.any([tabRequest.signal, request.signal]);
        selection.hidden = false;
        panel.querySelector('#intelligence-selection-title').focus();
        await loadRegion(detail, requestSignal, 'laporan terpilih', () => api(`${endpoint}/${id}`, { signal: requestSignal }), (payload) => {
            detailView = renderReport(payload.data, 'intelligence-selected', id);
            latestView?.settle();
            latest.hidden = true;
            showMetadata(detailView);
            detail.replaceChildren(...detailView.sections);
            announce('Laporan terpilih tersedia.');
        }, () => void fetchDetail(id));
        if (detailRequest === request) detailRequest = undefined;
    }

    function deactivate() {
        settleViews();
        tabRequest?.abort();
        tabRequest = undefined;
        if (latest.getAttribute('aria-busy') === 'true') latestRequested = false;
        refresh.setAttribute('aria-disabled', 'false');
        if (detailRequest) resetSelection();
        announce('');
    }

    refresh.addEventListener('click', () => void fetchLatest(), { signal });
    reducedMotion.addEventListener('change', settleViews, { signal });
    find('back').addEventListener('click', () => {
        resetSelection();
        panel.querySelector('#intelligence-title').focus();
        announce('Menampilkan laporan terbaru.');
    }, { signal });
    signal.addEventListener('abort', deactivate, { once: true });

    return {
        activate(name) {
            find('name').textContent = name;
            tabRequest = new AbortController();
            if (!latestRequested) void fetchLatest();
            else if (pendingHistoryPage !== undefined) void fetchHistory(pendingHistoryPage);
        },
        deactivate,
    };
}
