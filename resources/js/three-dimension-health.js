import { pageApi } from './api';
import { completionPercent, dimensionCodes, dimensionNames, notFinalCount, statusFilters } from './three-dimension-health-data';
import { createSelectControl } from './select-control';
import { element, formatNumber, formatScore, icon, showError, skeleton } from './ui';

const PER_PAGE = 5;
const QUESTION_TOTAL_FALLBACK = 16;
const stateLabel = { final: 'Final', draft: 'Draft', none: 'Belum dinilai' };
const statePill = { final: 'pill-green', draft: 'pill-amber', none: 'pill-muted' };
const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

const dateText = (value) => {
    if (!value) return 'Tidak tersedia';
    const parsed = new Date(value);

    return Number.isFinite(parsed.getTime()) ? dateFormat.format(parsed) : 'Tidak tersedia';
};
const percentText = (part, total) => {
    const percent = completionPercent(part, total);

    return percent === null ? '—' : `${percent}%`;
};
const scoreNodes = (value) => (value === null || value === undefined
    ? ['—']
    : [formatScore(value), element('span', { class: 'home-three-unit', text: '/100' })]);
const scoreLine = (value) => (value === null || value === undefined ? '—' : `${formatScore(value)}/100`);
const idLabel = (id) => `UMKM-${String(id).padStart(5, '0')}`;

function dimensionRow(code, value) {
    const hasValue = value !== null && value !== undefined;

    return element('div', { class: 'home-three-dim' }, [
        element('div', { class: 'home-three-dim-top' }, [
            element('strong', { text: `${code} · ${dimensionNames[code]}` }),
            element('span', { text: hasValue ? `${formatScore(value)} dari 100` : 'Belum ada data' }),
        ]),
        element('div', { class: 'home-three-track', 'aria-hidden': 'true' }, [
            element('div', { class: 'home-three-fill', style: `width:${hasValue ? Math.min(100, Math.max(0, value)) : 0}%` }),
        ]),
    ]);
}

function stateLine(item) {
    if (item.state === 'final') return `Skor Final ${scoreLine(item.latest_final.overall_score)}`;
    if (item.state === 'draft') return `${formatNumber(item.draft.answered_count)} dari ${formatNumber(item.draft.question_count)} soal terjawab`;

    return 'Belum ada kuesioner';
}

export function initThreeDimensionHealth(signal) {
    const root = document.getElementById('three-dimension-section');
    if (!root) return;

    const api = pageApi(signal);
    const kpis = document.getElementById('three-kpis');
    const profile = document.getElementById('three-profile');
    const selectedCard = document.getElementById('three-selected');
    const search = document.getElementById('three-search');
    const filter = document.getElementById('three-status-filter');
    const total = document.getElementById('three-total');
    const list = document.getElementById('three-list');
    const pager = document.getElementById('three-pager');

    let statusValue = 'all';
    let searchValue = '';
    let page = 1;
    let rows = [];
    let meta = null;
    let selected = null;
    let listController;
    let listSequence = 0;
    let searchTimer;
    let loaded = false;

    filter.replaceChildren(...statusFilters.map(([value, label]) => element('option', { value, text: label })));
    createSelectControl(filter, signal);
    filter.addEventListener('change', () => {
        statusValue = filter.value;
        page = 1;
        loadPortfolio();
    }, { signal });
    search.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            const next = search.value.trim();
            if (next === searchValue) return;
            searchValue = next;
            page = 1;
            loadPortfolio();
        }, 300);
    }, { signal });
    signal.addEventListener('abort', () => { listController?.abort(); window.clearTimeout(searchTimer); }, { once: true });

    function renderKpis(summary) {
        const count = summary.total_umkms;
        const finalPercent = percentText(summary.with_final, count);
        const notFinal = notFinalCount(summary);
        const cards = [
            { label: 'Rata-rata kesehatan', value: scoreNodes(summary.overall_final_average), note: 'Rerata Final terbaru tiap UMKM' },
            { label: 'UMKM terpantau', value: [formatNumber(count)], note: 'Sesuai cakupan akun Anda' },
            {
                label: 'Evaluasi Final',
                value: [formatNumber(summary.with_final)],
                badge: count ? [finalPercent, 'pill-green'] : null,
                note: count ? `Dari ${formatNumber(count)} UMKM terpantau` : 'Belum ada UMKM',
            },
            {
                label: 'Belum Final',
                value: [formatNumber(notFinal)],
                badge: notFinal > 0 ? ['Perlu Cek', 'pill-amber'] : null,
                note: `Draft ${formatNumber(summary.draft_only)} · Belum dinilai ${formatNumber(summary.not_assessed)}`,
            },
        ];
        kpis.replaceChildren(...cards.map((card) => element('article', { class: 'card metric panel home-three-kpi' }, [
            element('div', { class: 'home-three-kpi-top' }, [
                element('span', { class: 'home-three-kpi-label', text: card.label }),
                card.badge ? element('span', { class: `pill ${card.badge[1]}`, text: card.badge[0] }) : null,
            ]),
            element('p', { class: 'home-three-kpi-value num' }, card.value),
            element('p', { class: 'home-three-kpi-note', text: card.note }),
        ])));
    }

    function renderProfile(summary) {
        profile.replaceChildren(
            element('h3', { text: 'Profil Rata-rata Tiga Dimensi (INS, ECT, OPS)' }),
            element('p', { class: 'home-three-sub', text: 'Hasil agregat Final per UMKM. Skala 0–100.' }),
            ...dimensionCodes.map((code) => dimensionRow(code, summary.dimension_final_averages[code])),
        );
    }

    function renderFinalBody(item) {
        const final = item.latest_final;
        const nodes = [];
        if (!final.is_complete) {
            nodes.push(element('div', { class: 'home-three-notice', role: 'status' }, [
                element('div', {}, [
                    element('strong', { text: 'Data hasil belum lengkap' }),
                    element('p', { text: 'Sebagian skor atau kategori belum tersedia. Nilai yang kosong ditampilkan sebagai “—”.' }),
                ]),
                element('button', { type: 'button', class: 'button button-outline', text: 'Coba lagi', onclick: () => loadPortfolio() }),
            ]));
        }
        nodes.push(element('div', { class: 'home-three-metrics' }, [
            element('div', { class: 'home-three-metric' }, [
                element('span', { text: 'Skor Keseluruhan' }),
                element('strong', { class: 'home-three-major num' }, scoreNodes(final.overall_score)),
            ]),
            element('div', { class: 'home-three-metric' }, [
                element('span', { text: 'Kategori Resmi' }),
                element('strong', { text: final.category ?? '—' }),
            ]),
            element('div', { class: 'home-three-metric' }, [
                element('span', { text: 'Kelengkapan' }),
                element('strong', { text: `${formatNumber(final.answered_count)}/${formatNumber(final.question_count || QUESTION_TOTAL_FALLBACK)} soal (${percentText(final.answered_count, final.question_count)})` }),
            ]),
            element('div', { class: 'home-three-metric' }, [
                element('span', { text: 'Tanggal Hasil Final' }),
                element('strong', { text: dateText(final.finalized_at) }),
            ]),
        ]));
        nodes.push(element('div', { class: 'home-three-dims' }, dimensionCodes.map((code) => dimensionRow(code, final.dimensions[code]))));
        if (item.has_active_draft && item.draft) {
            nodes.push(element('p', { class: 'home-three-sub' }, [
                `Draft berjalan: ${formatNumber(item.draft.answered_count)} dari ${formatNumber(item.draft.question_count)} soal terjawab. Skor Final tetap berlaku.`,
            ]));
        }

        return nodes;
    }

    function renderDraftBody(item) {
        const answered = item.draft.answered_count;
        const count = item.draft.question_count;

        return [
            element('p', { class: 'home-three-sub', text: 'Penilaian belum difinalisasi. Hasil sementara tidak dianggap skor resmi.' }),
            element('div', { class: 'home-three-metrics' }, [
                element('div', { class: 'home-three-metric' }, [
                    element('span', { text: 'Progres jawaban' }),
                    element('strong', { text: `${formatNumber(answered)}/${formatNumber(count)} soal` }),
                ]),
            ]),
            element('div', { class: 'home-three-track home-three-progress', 'aria-hidden': 'true' }, [
                element('div', { class: 'home-three-fill', style: `width:${completionPercent(answered, count) ?? 0}%` }),
            ]),
        ];
    }

    function renderNoneBody() {
        return [element('div', { class: 'home-three-empty' }, [
            element('h3', { text: 'Belum ada penilaian Tiga Dimensi' }),
            element('p', { text: 'Belum tersedia hasil INS, ECT, dan OPS untuk UMKM ini.' }),
        ])];
    }

    function renderSelected() {
        if (!selected) {
            selectedCard.replaceChildren(element('p', { class: 'home-three-empty home-three-sub', text: 'Pilih UMKM dari daftar untuk melihat hasil Tiga Dimensi.' }));

            return;
        }
        const item = selected;
        const badges = [element('span', { class: `pill ${statePill[item.state]}`, text: stateLabel[item.state] })];
        if (item.has_active_draft) badges.push(element('span', { class: 'pill pill-amber', text: 'Ada Draft berjalan' }));
        const body = item.state === 'final' ? renderFinalBody(item) : item.state === 'draft' ? renderDraftBody(item) : renderNoneBody();
        const questionnaireLabel = item.state === 'none' ? 'Isi kuesioner' : item.state === 'draft' ? 'Lanjutkan kuesioner' : 'Buka kuesioner';

        selectedCard.replaceChildren(
            element('div', { class: 'home-three-selected-head' }, [
                element('div', {}, [
                    element('div', { class: 'home-three-title-row' }, [
                        element('h3', { text: item.name }),
                        element('span', { class: 'home-three-badge home-three-badge-blue', text: 'Terpilih Ditinjau' }),
                    ]),
                    element('p', { class: 'home-three-sub', text: 'Kesehatan UMKM — Tiga Dimensi' }),
                ]),
                element('div', { class: 'home-three-title-row' }, badges),
            ]),
            ...body,
            element('div', { class: 'home-three-foot' }, [
                element('a', { class: 'text-link', href: `/form-data?umkm=${item.umkm_id}`, text: questionnaireLabel }),
                element('span', { class: 'home-three-id', text: `ID: ${idLabel(item.umkm_id)}` }),
            ]),
        );
    }

    function renderRow(item) {
        const isSelected = selected !== null && item.umkm_id === selected.umkm_id;
        const side = [element('span', { class: `pill ${statePill[item.state]}`, text: stateLabel[item.state] })];
        if (item.has_active_draft) side.push(element('span', { class: 'pill pill-amber', text: 'Draft berjalan' }));
        side.push(element('span', { class: 'home-three-chevron' }, icon('arrow')));

        return element('button', {
            type: 'button',
            class: 'home-three-row',
            'aria-current': isSelected ? 'true' : null,
            onclick: () => select(item),
        }, [
            element('span', { class: 'home-three-row-main' }, [
                element('span', { class: 'home-three-row-name' }, [
                    element('strong', { text: item.name }),
                    isSelected ? element('span', { class: 'home-three-active', text: 'Aktif' }) : null,
                ]),
                element('span', { class: item.state === 'none' ? 'muted small home-three-italic' : 'muted small', text: stateLine(item) }),
            ]),
            element('span', { class: 'home-three-row-side' }, side),
        ]);
    }

    function renderList() {
        if (!rows.length) {
            const filtered = statusValue !== 'all' || searchValue !== '';
            list.replaceChildren(element('div', { class: 'home-three-empty' }, [
                element('h3', { text: filtered ? 'Tidak ada UMKM yang cocok' : 'Belum ada UMKM' }),
                element('p', { text: filtered ? 'Ubah kata kunci pencarian atau filter status.' : 'Tambahkan data UMKM untuk melihat kesehatan Tiga Dimensi.' }),
            ]));

            return;
        }
        list.replaceChildren(element('div', { class: 'home-three-rows' }, rows.map(renderRow)));
    }

    function pageNumbers(current, last) {
        const start = Math.max(1, Math.min(current - 2, last - 4));
        const end = Math.min(last, start + 4);
        const numbers = [];
        for (let number = start; number <= end; number += 1) numbers.push(number);

        return numbers;
    }

    function renderPager() {
        if (!meta || meta.last_page <= 1) {
            pager.hidden = true;
            pager.replaceChildren();

            return;
        }
        const goTo = (number) => { page = number; loadPortfolio(); };
        const pageButton = (number, { current = false, disabled = false, icon: iconName, label } = {}) => element('button', {
            type: 'button',
            class: `page-button${current ? ' is-current' : ''}${iconName ? ' umkm-icon-button' : ''}`,
            text: iconName ? null : String(number),
            disabled,
            'aria-current': current ? 'page' : null,
            'aria-label': label ?? (current ? `Halaman ${number}, saat ini` : `Ke halaman ${number}`),
            onclick: () => goTo(number),
        }, iconName ? icon(iconName) : []);
        pager.hidden = false;
        pager.replaceChildren(
            element('span', { text: `Menampilkan ${formatNumber(meta.from)}–${formatNumber(meta.to)} dari ${formatNumber(meta.total)} UMKM` }),
            element('div', { class: 'pagination' }, [
                pageButton(meta.current_page - 1, { icon: 'arrow-left', label: 'Halaman sebelumnya', disabled: meta.current_page <= 1 }),
                ...pageNumbers(meta.current_page, meta.last_page).map((number) => pageButton(number, { current: number === meta.current_page })),
                pageButton(meta.current_page + 1, { icon: 'arrow', label: 'Halaman berikutnya', disabled: meta.current_page >= meta.last_page }),
            ]),
        );
    }

    function select(item) {
        selected = item;
        renderList();
        renderSelected();
    }

    // Ringkasan (KPI dan profil) bersifat global: hanya dimuat saat awal dan setelah evaluasi tersimpan.
    // Filter, pencarian, dan pagination hanya memuat ulang daftar, sehingga bagian atas tidak ikut berkedip.
    async function loadPortfolio({ refreshSummary = !loaded } = {}) {
        const current = ++listSequence;
        listController?.abort();
        listController = new AbortController();
        if (refreshSummary) {
            kpis.setAttribute('aria-busy', 'true');
            profile.setAttribute('aria-busy', 'true');
            kpis.replaceChildren(skeleton('h-20', 'h-20', 'h-20', 'h-20'));
            profile.replaceChildren(skeleton('h-3 w-1/3', 'h-3', 'h-3', 'h-3'));
        }
        list.setAttribute('aria-busy', 'true');
        list.replaceChildren(skeleton('h-10', 'h-10', 'h-10'));
        try {
            const params = new URLSearchParams({ status: statusValue, page: String(page), per_page: String(PER_PAGE) });
            if (searchValue !== '') params.set('search', searchValue);
            const response = await api(`/dashboard/three-dimension-health?${params}`, { signal: listController.signal });
            if (current !== listSequence) return;
            loaded = true;
            rows = response.data;
            meta = response.meta;
            total.hidden = false;
            total.textContent = `Total: ${formatNumber(response.summary.total_umkms)} UMKM`;
            if (refreshSummary) {
                renderKpis(response.summary);
                renderProfile(response.summary);
            }
            renderList();
            renderPager();
            if (selected) {
                const fresh = rows.find((item) => item.umkm_id === selected.umkm_id);
                if (fresh) selected = fresh;
            } else if (rows.length) {
                selected = rows[0];
            }
            renderSelected();
        } catch (error) {
            if (current === listSequence && !signal.aborted && error.name !== 'AbortError') {
                if (refreshSummary) {
                    showError(kpis, error, () => loadPortfolio({ refreshSummary: true }));
                    showError(profile, error, () => loadPortfolio({ refreshSummary: true }));
                }
                showError(list, error, () => loadPortfolio({ refreshSummary }));
                pager.hidden = true;
            }
        } finally {
            if (current === listSequence && !signal.aborted) {
                if (refreshSummary) {
                    kpis.setAttribute('aria-busy', 'false');
                    profile.setAttribute('aria-busy', 'false');
                }
                list.setAttribute('aria-busy', 'false');
            }
        }
    }

    window.addEventListener('joyumkm:evaluation-saved', () => loadPortfolio({ refreshSummary: true }), { signal });

    loadPortfolio({ refreshSummary: true });
}
