import { pageApi } from './api';
import { buildSummaryQuery, categoryTone, formatAverage, pageRange, summaryPageSize } from './six-factor-dashboard-data';
import { createSelectControl } from './select-control';
import { renderFinalResult } from './six-factor-final-result';
import { sixFactors } from './six-factor-draft';
import { bindDialog, element, formatNumber, openDialog, showError } from './ui';

const factorEntries = Object.entries(sixFactors);
const shortLabels = ['SD', 'KP', 'LK', 'NO', 'ST', 'KI'];
const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
const formatDate = (value) => (value && Number.isFinite(new Date(value).getTime()) ? dateFormat.format(new Date(value)) : null);
const searchDelay = 300;

export function initSixFactorDashboard(signal) {
    const api = pageApi(signal);
    const section = document.getElementById('six-factor-section');
    const kpis = document.getElementById('six-kpis');
    const search = document.getElementById('six-search');
    const status = document.getElementById('six-status');
    const sort = document.getElementById('six-sort');
    const statusControl = createSelectControl(status, signal);
    const sortControl = createSelectControl(sort, signal);
    const cards = document.getElementById('six-cards');
    const pager = document.getElementById('six-pager');
    const count = document.getElementById('six-count');
    const previous = document.getElementById('six-prev');
    const next = document.getElementById('six-next');
    let page = 1;
    let sequence = 0;
    let controller;
    let searchTimer;

    function renderKpis(summary) {
        const items = [
            ['Total UMKM', formatNumber(summary.total_umkm)],
            ['Sudah Final', formatNumber(summary.sudah_final)],
            ['Belum ada Final', formatNumber(summary.belum_ada_final)],
            ['Rata-rata skor Final', formatAverage(summary.rata_rata_skor_final)],
        ];
        kpis.replaceChildren(...items.map(([label, value]) => element('div', { class: 'card home-six-kpi' }, [
            element('span', { class: 'home-six-kpi-label', text: label }),
            element('strong', { class: 'home-six-kpi-value num', text: value }),
        ])));
    }

    function renderFactors(final) {
        return element('div', { class: 'home-six-factors', role: 'list' }, factorEntries.map(([key, label], index) => {
            const value = final.factors[key];
            return element('div', { class: 'home-six-factor-box', role: 'listitem', title: label, 'aria-label': `${label}: ${value} dari 5` }, [
                element('small', { text: shortLabels[index], 'aria-hidden': true }),
                element('strong', { class: 'num', text: `${value}/5`, 'aria-hidden': true }),
            ]);
        }));
    }

    function renderCard(item) {
        const place = [item.sector, item.province].filter(Boolean).join(' · ');
        const final = item.latest_final;
        const body = final
            ? [
                element('div', { class: 'home-six-score' }, [
                    element('strong', { class: 'home-six-total num', text: `${final.total_score}/30` }),
                    element('span', { class: `pill ${categoryTone(final.category)}`, text: final.category }),
                ]),
                renderFactors(final),
                element('p', { class: 'muted small', text: `Final terbaru: ${formatDate(final.finalized_at) ?? 'Tidak tersedia'}` }),
            ]
            : [element('div', { class: 'home-six-none' }, element('p', { text: 'Belum ada hasil Final untuk UMKM ini.' }))];

        return element('article', { class: 'card home-six-card' }, [
            element('div', { class: 'home-six-card-head' }, [
                element('div', { class: 'min-w-0' }, [
                    element('h3', { class: 'break-words', text: item.name }),
                    place ? element('p', { text: place }) : null,
                ]),
                element('span', { class: `pill ${final ? 'pill-green' : 'pill-muted'}`, text: final ? 'Final' : 'Belum ada Final' }),
            ]),
            ...body,
            element('div', { class: 'home-six-card-foot' }, [
                element('span', { class: 'muted', text: final ? 'Hasil Final resmi' : 'Belum tersedia' }),
                final
                    ? element('button', { type: 'button', class: 'text-link home-six-detail-button', text: 'Lihat detail →', onclick: (event) => openDetail(item, event.currentTarget) })
                    : element('a', { class: 'text-link', href: `/umkms/${item.umkm_id}/six-factor-evaluations`, text: 'Buka penilaian →' }),
            ]),
        ]);
    }

    const detail = document.getElementById('six-factor-detail');
    const detailTitle = document.getElementById('six-factor-detail-title');
    const detailPlace = document.getElementById('six-factor-detail-place');
    const detailContent = document.getElementById('six-factor-detail-content');
    const detailLink = document.getElementById('six-factor-detail-link');
    let detailChart = null;

    function clearDetail() {
        detailChart?.destroy();
        detailChart = null;
        detailContent.replaceChildren();
    }

    function openDetail(item, opener) {
        const final = item.latest_final;
        const place = [item.sector, item.province].filter(Boolean).join(' · ');
        clearDetail();
        detailTitle.textContent = item.name;
        detailPlace.textContent = place;
        detailPlace.hidden = !place;
        detailLink.href = `/umkms/${item.umkm_id}/six-factor-evaluations`;
        detailChart = renderFinalResult(detailContent, {
            total_score: final.total_score,
            category: final.category,
            finalized_at: final.finalized_at,
            scores: final.factors,
        }, { name: item.name, place, label: 'Final terbaru' });
        openDialog(detail, opener);
    }

    bindDialog(detail);
    detail.addEventListener('close', clearDetail, { signal });

    function resetFilters() {
        clearTimeout(searchTimer);
        search.value = '';
        status.value = 'all';
        sort.value = 'name_asc';
        statusControl.sync();
        sortControl.sync();
        page = 1;
        void load();
    }

    function renderEmpty(title, description, action) {
        return element('div', { class: 'home-six-empty' }, [
            element('h3', { text: title }),
            element('p', { text: description }),
            action,
        ]);
    }

    function render({ data, summary, meta }) {
        renderKpis(summary);
        if (!summary.total_umkm) {
            cards.replaceChildren(renderEmpty('Belum ada UMKM', 'Tambahkan UMKM untuk mulai menilai enam faktor.', element('a', { class: 'text-link', href: '/umkms', text: 'Tambah UMKM' })));
            pager.hidden = true;
            return;
        }
        if (!data.length) {
            if (page > 1 && meta.last_page >= 1) {
                page = meta.last_page;
                void load();
                return;
            }
            cards.replaceChildren(renderEmpty('Tidak ada UMKM yang cocok', 'Ubah kata kunci atau filter untuk melihat UMKM lain.', element('button', { type: 'button', class: 'button button-outline', text: 'Reset filter', onclick: resetFilters })));
            pager.hidden = true;
            return;
        }
        cards.replaceChildren(...data.map(renderCard));
        const range = pageRange(meta.current_page, meta.per_page, meta.total);
        count.textContent = `Menampilkan ${range.from}–${range.to} dari ${formatNumber(meta.total)} UMKM`;
        previous.disabled = meta.current_page <= 1;
        next.disabled = meta.current_page >= meta.last_page;
        pager.hidden = false;
    }

    async function load() {
        const current = ++sequence;
        controller?.abort();
        controller = new AbortController();
        section.setAttribute('aria-busy', 'true');
        kpis.replaceChildren(...Array.from({ length: 4 }, () => element('div', { class: 'card home-six-kpi skeleton h-16', 'aria-hidden': true })));
        cards.replaceChildren(...Array.from({ length: summaryPageSize }, () => element('div', { class: 'card home-six-card skeleton h-40', 'aria-hidden': true })));
        pager.hidden = true;
        const query = buildSummaryQuery({ page, perPage: summaryPageSize, search: search.value, status: status.value, sort: sort.value });
        try {
            const response = await api(`/dashboard/six-factor-summary?${query}`, { signal: controller.signal });
            if (current !== sequence || signal.aborted) return;
            render(response);
        } catch (error) {
            if (current !== sequence || signal.aborted || error.name === 'AbortError') return;
            kpis.replaceChildren();
            showError(cards, error, load);
            pager.hidden = true;
        } finally {
            if (current === sequence) section.setAttribute('aria-busy', 'false');
        }
    }

    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            page = 1;
            void load();
        }, searchDelay);
    }, { signal });
    [status, sort].forEach((control) => control.addEventListener('change', () => {
        clearTimeout(searchTimer);
        page = 1;
        void load();
    }, { signal }));
    previous.addEventListener('click', () => { page = Math.max(1, page - 1); void load(); }, { signal });
    next.addEventListener('click', () => { page += 1; void load(); }, { signal });
    signal.addEventListener('abort', () => {
        clearTimeout(searchTimer);
        controller?.abort();
        clearDetail();
    }, { once: true });

    void load();
}
