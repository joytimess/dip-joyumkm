import { createSelectControl } from '../select-control';
import { pageApi } from '../api';
import { createSummary } from '../summary';
import { bindUmkmForm } from '../umkm-form';
import { withListContext } from '../umkm-navigation';
import { mountOwnerFilter, openTransferDialog } from '../admin-portfolio';
import { isSuperadmin } from '../role';
import { element, icon, formatNumber, formatScore, bindDialog, openDialog, setBusy, showError, toast } from '../ui';

export async function init(signal) {
    const api = pageApi(signal);
    const superadmin = isSuperadmin();
    if (superadmin) {
        const bar = element('div', { class: 'section-block' });
        document.getElementById('umkm-summary').before(bar);
        mountOwnerFilter(bar, api);
    }
    const summary = createSummary(document.getElementById('umkm-summary'), signal);
    window.addEventListener('joyumkm:umkm-mutated', summary.reload, { signal });
    const listState = document.getElementById('umkm-list-state');
    const tableSkeleton = listState.firstElementChild.cloneNode(true);
    const tableArea = document.getElementById('umkm-table-area');
    const rows = document.getElementById('umkm-rows');
    const cards = document.getElementById('umkm-cards');
    const sortSelect = document.getElementById('umkm-sort');
    const perPage = document.getElementById('umkm-per-page');
    const tableIcons = document.getElementById('umkm-table-icons').content;
    const range = document.getElementById('umkm-range');
    const filterForm = document.getElementById('umkm-filters');
    const filterFields = filterForm.querySelector('fieldset');
    const optionsState = document.getElementById('options-state');
    const filterSkeleton = optionsState.firstElementChild.cloneNode(true);
    const compact = matchMedia('(max-width: 1050px)');
    const deleteModal = document.getElementById('delete-umkm-modal');
    const deleteButton = document.getElementById('confirm-delete-umkm');
    const addButton = document.getElementById('add-umkm');
    const filterNames = ['sector_id', 'province', 'category', 'status'];
    const filterSelects = [...filterFields.querySelectorAll('select[name]')].map((select) => createSelectControl(select, signal));
    const otherSelects = [sortSelect, perPage].map((select) => createSelectControl(select, signal));
    const allowedParams = ['search', ...filterNames, 'sort', 'direction', 'page', 'per_page'];
    const choosingSixFactor = new URLSearchParams(location.search).get('mode') === 'six-factor';
    let params = readParams();
    let options;
    let latestList;
    let displayedQuery;
    let listController;
    let listSequence = 0;
    let deletingId = null;
    bindDialog(deleteModal);
    const { openEdit } = bindUmkmForm({
        api, signal, addButton,
        onSaved: async () => {
            await loadList();
            if (!signal.aborted) window.dispatchEvent(new Event('joyumkm:umkm-mutated'));
        },
    });

    function readParams() {
        const query = new URLSearchParams(window.location.search);
        return new URLSearchParams([...query].filter(([key, value]) => allowedParams.includes(key) && value !== ''));
    }

    function syncControls() {
        document.getElementById('global-search').value = params.get('search') || '';
        syncFilterFields();
        const size = params.get('per_page') || '10';
        if (/^\d+$/.test(size) && Number(size) >= 1 && Number(size) <= 100
            && ![...perPage.options].some((option) => option.value === size)) {
            perPage.append(element('option', { value: size, text: `${Number(size)} per halaman` }));
        }
        perPage.value = size;
        const sort = params.get('sort') || 'score';
        const direction = params.get('direction') || 'desc';
        sortSelect.value = `${sort}:${direction}`;
        otherSelects.forEach(({ sync }) => sync());
        document.querySelectorAll('[data-sort]').forEach((header) => {
            const active = header.dataset.sort === sort;
            header.setAttribute('aria-sort', active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
            header.querySelector('[data-sort-direction]').textContent = active ? (direction === 'asc' ? '↑' : '↓') : '';
        });
    }

    function syncFilterFields() {
        for (const name of filterNames) filterForm.elements[name].value = params.get(name) || '';
        filterSelects.forEach(({ sync }) => sync());
    }

    function listQuery() {
        const query = new URLSearchParams(params);
        if (choosingSixFactor) query.set('mode', 'six-factor');
        return query.toString();
    }

    function updateParams(changes, { resetPage = true, replace = false } = {}) {
        const previous = params.toString();
        for (const [key, value] of Object.entries(changes)) {
            if (value === '' || value === null) params.delete(key);
            else params.set(key, String(value));
        }
        if (resetPage) params.delete('page');
        if (params.toString() !== previous) {
            const query = listQuery();
            const url = `/umkms${query ? `?${query}` : ''}`;
            if (replace) history.replaceState(history.state, '', url);
            else history.pushState({ workspaceIndex: history.state.workspaceIndex + 1 }, '', url);
            window.dispatchEvent(new Event('joyumkm:query-updated'));
            void loadList();
        }
        syncControls();
    }

    async function loadOptions() {
        optionsState.hidden = false;
        optionsState.setAttribute('aria-busy', 'true');
        optionsState.replaceChildren(filterSkeleton.cloneNode(true));
        try {
            ({ data: options } = await api('/umkms/options'));
            const appendOptions = (select, values, getValue, getLabel) => {
                select.replaceChildren(select.options[0], ...values.map((value) => element('option', { value: getValue(value), text: getLabel(value) })));
            };
            for (const id of ['umkm-sector', 'filter-sector']) appendOptions(document.getElementById(id), options.sectors, (sector) => sector.id, (sector) => sector.name);
            for (const id of ['umkm-province', 'filter-province']) appendOptions(document.getElementById(id), options.provinces, (province) => province, (province) => province);
            appendOptions(document.getElementById('filter-category'), options.categories, (category) => category.label, (category) => category.label);
            addButton.disabled = false;
            filterFields.disabled = false;
            filterFields.hidden = false;
            optionsState.hidden = true;
            optionsState.setAttribute('aria-busy', 'false');
            syncFilterFields();
        } catch (error) {
            if (!signal.aborted && error.name !== 'AbortError') showError(optionsState, error, loadOptions);
        }
    }

    async function loadList() {
        const sequence = ++listSequence;
        listController?.abort();
        listController = new AbortController();
        if (displayedQuery !== params.toString() || !latestList?.data.length) {
            listState.hidden = false;
            listState.replaceChildren(tableSkeleton.cloneNode(true));
            tableArea.hidden = true;
            range.textContent = 'Memuat data UMKM…';
        }
        listState.setAttribute('aria-busy', 'true');
        tableArea.setAttribute('aria-busy', 'true');
        try {
            const result = await api(`/umkms?${params}`, { signal: listController.signal });
            if (sequence !== listSequence) return;
            if (!result.data.length && result.meta.current_page > result.meta.last_page) {
                updateParams({ page: result.meta.last_page }, { resetPage: false, replace: true });
                return;
            }
            renderList(result);
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== listSequence) return;
            if (error.status === 403) {
                tableArea.hidden = true;
                rows.replaceChildren();
                cards.replaceChildren();
                latestList = undefined;
            }
            listState.hidden = false;
            range.textContent = 'Data UMKM belum dapat dimuat.';
            showError(listState, error, loadList);
        } finally {
            if (sequence === listSequence) tableArea.setAttribute('aria-busy', 'false');
        }
    }

    function tableIcon(name) {
        return tableIcons.querySelector(`[data-icon="${name}"]`).cloneNode(true);
    }

    function renderActions(umkm, presentation, query, detailPath) {
        const menuId = `umkm-actions-${presentation}-${umkm.id}`;
        const trigger = element('button', {
            type: 'button', class: 'page-button umkm-icon-button', popovertarget: menuId,
            'aria-label': `Aksi untuk ${umkm.name}`,
        }, tableIcon('actions'));
        const menu = element('div', {
            id: menuId, popover: 'auto', class: 'umkm-action-menu', role: 'group',
            'aria-label': `Aksi untuk ${umkm.name}`,
        }, [
            ...(presentation === 'table' ? [
                choosingSixFactor ? element('a', {
                    href: withListContext(`${detailPath}/six-factor-evaluations`, query), class: 'row-action', autofocus: true,
                }, [icon('form'), 'Penilaian Enam Faktor']) : null,
                element('a', {
                    href: withListContext(detailPath, query), class: 'row-action', autofocus: !choosingSixFactor,
                }, [icon('eye'), 'Detail UMKM']),
                element('a', {
                    href: withListContext(`/factor-profile?umkm_id=${umkm.id}`, query), class: 'row-action',
                }, [icon('chart'), 'Profil Faktor']),
            ] : []),
            element('a', { href: withListContext(`/form-data?umkm=${umkm.id}`, query), class: 'row-action', autofocus: presentation === 'card' }, [icon('form'), 'Isi Kuesioner']),
            element('button', {
                type: 'button', class: 'row-action',
                onclick: () => {
                    menu.hidePopover();
                    openEdit(umkm.id, trigger);
                },
            }, [icon('edit'), 'Ubah']),
            superadmin && umkm.owner?.role === 'analyst' ? element('button', {
                type: 'button', class: 'row-action',
                onclick: () => {
                    menu.hidePopover();
                    openTransferDialog(umkm, api, trigger, async () => {
                        await loadList();
                        window.dispatchEvent(new Event('joyumkm:umkm-mutated'));
                    });
                },
            }, [icon('business'), 'Pindahkan kepemilikan']) : null,
            element('hr'),
            element('button', {
                type: 'button', class: 'row-action danger',
                onclick: () => {
                    menu.hidePopover();
                    openDelete(umkm, trigger);
                },
            }, [icon('trash'), 'Hapus']),
        ]);
        return [trigger, menu];
    }

    function ownerLine(umkm) {
        if (!umkm.owner) return null;
        const roles = { analyst: 'Analyst', user: 'Pengguna', superadmin: 'Superadmin' };
        return element('div', { class: 'small muted', style: 'margin-top:3px', text: `Pemilik: ${umkm.owner.name} (${roles[umkm.owner.role] ?? umkm.owner.role}${umkm.owner.is_active ? '' : ', nonaktif'})${umkm.is_cohort_member ? ' · dataset sintetis' : ''}` });
    }

    function statusBadge(umkm) {
        const status = {
            draft: ['pill-amber', 'Sementara'],
            final: ['pill-green', 'Final'],
        }[umkm.evaluation_status] || ['pill-muted', 'Belum dinilai'];
        return element('span', { class: `pill ${status[0]}`, text: status[1] });
    }

    function renderList(result) {
        latestList = result;
        displayedQuery = params.toString();
        const { data, meta } = result;
        rows.replaceChildren();
        cards.replaceChildren();
        range.textContent = meta.total === 0 ? '0 UMKM' : `Menampilkan ${formatNumber(meta.from)}–${formatNumber(meta.to)} dari ${formatNumber(meta.total)} UMKM`;
        if (data.length === 0) {
            const filtered = ['search', 'sector_id', 'province', 'category', 'status'].some((key) => params.has(key));
            listState.replaceChildren(element('div', { class: 'empty-state' }, [
                element('h2', { text: filtered ? 'Tidak ada hasil sesuai filter' : 'Belum ada UMKM' }),
                element('p', { class: 'muted', text: filtered ? 'Coba ubah pencarian atau reset filter Anda.' : 'Klik Tambah Data untuk memulai.' }),
            ]));
        } else {
            listState.hidden = true;
        }
        listState.setAttribute('aria-busy', 'false');
        tableArea.hidden = false;
        const query = listQuery();
        data.forEach((umkm, index) => {
            const recommendation = element('span', { class: 'category-label', text: umkm.recommendation });
            const detailPath = rows.dataset.detailUrl.replace('__id__', umkm.id);
            const nameLink = element('a', {
                href: withListContext(choosingSixFactor ? detailPath + '/six-factor-evaluations' : detailPath, query),
                class: 'text-link', text: umkm.name,
            });
            const score = umkm.latest_score === null ? null : `${formatScore(umkm.latest_score)}/100`;
            const profile = element('a', {
                href: withListContext(`/factor-profile?umkm_id=${umkm.id}`, query),
                class: 'table-link row-action', text: 'Lihat profil faktor',
            });
            if (choosingSixFactor) {
                const evaluationUrl = withListContext(`${detailPath}/six-factor-evaluations`, query);
                const facts = element('div', { class: 'six-factor-picker-facts', 'aria-live': 'polite' }, [
                    element('span', { class: 'muted', text: 'Memuat penilaian…' }),
                ]);
                const badges = element('div', { class: 'hstack wrap' });
                const action = element('a', { href: evaluationUrl, class: 'btn button button-primary', text: 'Buka Penilaian' });
                cards.append(element('li', { class: 'card six-factor-picker-card' }, [
                    element('div', {}, [element('h3', { text: umkm.name }), element('p', { class: 'muted', text: `${umkm.sector.name} · ${umkm.province}` })]),
                    badges, facts,
                    element('div', { class: 'six-factor-picker-actions' }, action),
                ]));
                Promise.all([
                    api(`/umkms/${umkm.id}/six-factor-evaluations/draft`, { signal: listController.signal }),
                    api(`/umkms/${umkm.id}/six-factor-evaluations/latest`, { signal: listController.signal }),
                ]).then(([draftResponse, finalResponse]) => {
                    if (signal.aborted || !facts.isConnected) return;
                    const draft = draftResponse.data;
                    const final = finalResponse.data;
                    const filled = draft ? Object.values(draft.scores).filter((value) => value !== null).length : 0;
                    if (draft) badges.append(element('span', { class: 'pill pill-amber', text: 'Draft aktif' }));
                    if (final) badges.append(element('span', { class: 'pill pill-green', text: 'Final tersedia' }));
                    if (!draft && !final) badges.append(element('span', { class: 'pill pill-muted', text: 'Belum dinilai' }));
                    facts.replaceChildren(
                        element('div', {}, [element('p', { class: 'muted', text: 'Draft aktif' }), element('strong', { text: draft ? `${filled}/6 faktor` : 'Tidak ada draft' })]),
                        element('div', {}, [element('p', { class: 'muted', text: 'Hasil Final terbaru' }), element('strong', { text: final ? `${final.total_score}/30` : 'Belum tersedia' })]),
                    );
                    action.textContent = draft ? 'Lanjutkan Draft' : final ? 'Buka Penilaian' : 'Mulai Penilaian';
                }).catch((error) => {
                    if (error.name !== 'AbortError' && facts.isConnected) facts.textContent = 'Penilaian belum dapat dimuat.';
                });
                return;
            }
            rows.append(element('tr', {}, [
                element('td', { class: 'num', text: meta.from + index }),
                element('td', { class: 'umkm-name' }, [
                    element('strong', { text: umkm.name }),
                    ownerLine(umkm),
                ]),
                element('td', { class: 'table-location muted', text: umkm.province }),
                element('td', {}, [element('span', { class: 'pill pill-muted sector-badge', text: umkm.sector.name })]),
                element('td', { class: 'num' }, [
                    element('span', { text: `Rp ${formatNumber(umkm.omzet)}` }),
                ]),
                element('td', { class: 'num' }, [
                    element('strong', { text: score ?? '—' }),
                ]),
                element('td', {}, statusBadge(umkm)),
                element('td', {}, [recommendation]),
                element('td', {}, renderActions(umkm, 'table', query, detailPath)),
            ]));
            cards.append(element('li', { class: 'umkm-card' }, [
                element('div', { class: 'umkm-card-heading' }, [
                    element('div', {}, [
                        element('h3', {}, nameLink),
                        element('p', { class: 'muted', text: `${umkm.sector.name} · ${umkm.province}` }),
                        ownerLine(umkm),
                    ]),
                    element('div', {}, renderActions(umkm, 'card', query)),
                ]),
                element('dl', { class: 'umkm-card-facts' }, [
                    element('div', {}, [
                        element('dt', { text: 'Skor kesehatan' }),
                        element('dd', {}, [score && element('strong', { class: 'num', text: score }), statusBadge(umkm)]),
                    ]),
                    element('div', {}, [
                        element('dt', { text: 'Omzet' }),
                        element('dd', { class: 'num', text: `Rp ${formatNumber(umkm.omzet)}` }),
                    ]),
                ]),
                element('div', { class: 'umkm-card-links' }, [
                    profile,
                    score && element('span', { class: 'category-label', text: umkm.recommendation }),
                ]),
            ]));
        });
        document.getElementById('umkm-page-info').textContent = `Halaman ${meta.current_page} dari ${meta.last_page} (${formatNumber(meta.total)} data)`;
        const pagination = document.getElementById('umkm-pagination');
        const pageButton = (page, { current = false, disabled = false, icon, label } = {}) => element('button', {
            type: 'button', class: `page-button${current ? ' is-current' : ''}${icon ? ' umkm-icon-button' : ''}`,
            text: icon ? null : String(page), disabled,
            'aria-current': current ? 'page' : null,
            'aria-label': label ?? (current ? `Halaman ${page}, saat ini` : `Ke halaman ${page}`),
            onclick: () => updateParams({ page }, { resetPage: false }),
        }, icon ? tableIcon(icon) : []);
        pagination.replaceChildren(pageButton(meta.current_page - 1, {
            icon: 'previous', label: 'Halaman sebelumnya', disabled: meta.current_page <= 1,
        }));
        const firstPage = Math.max(1, Math.min(meta.current_page - 1, meta.last_page - 2));
        for (let page = firstPage; page <= Math.min(meta.last_page, firstPage + 2); page++) {
            pagination.append(pageButton(page, { current: page === meta.current_page }));
        }
        pagination.append(pageButton(meta.current_page + 1, {
            icon: 'next', label: 'Halaman berikutnya', disabled: meta.current_page >= meta.last_page,
        }));
    }

    function openDelete(umkm, opener) {
        deletingId = umkm.id;
        document.getElementById('delete-umkm-name').textContent = umkm.name;
        document.getElementById('delete-umkm-error').hidden = true;
        openDialog(deleteModal, opener);
    }

    deleteButton.addEventListener('click', async () => {
        if (deleteButton.disabled || deletingId === null) return;
        setBusy(deleteButton, true, 'Menghapus…');
        try {
            await api(`/umkms/${deletingId}`, { method: 'DELETE' });
            deleteModal.close();
            toast('UMKM beserta evaluasinya berhasil dihapus.');
            await loadList();
            if (signal.aborted) return;
            window.dispatchEvent(new Event('joyumkm:umkm-mutated'));
        } catch (error) {
            if (signal.aborted || error.name === 'AbortError') return;
            const output = document.getElementById('delete-umkm-error');
            output.textContent = error.message;
            output.hidden = false;
        } finally { setBusy(deleteButton, false); }
    });
    compact.addEventListener('change', () => {
        filterSelects.forEach(({ close }) => close());
        document.querySelector('.umkm-action-menu:popover-open')?.hidePopover();
    }, { signal });
    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        updateParams(Object.fromEntries(new FormData(filterForm)));
    });
    document.getElementById('reset-filters').addEventListener('click', () => {
        updateParams(Object.fromEntries(filterNames.map((name) => [name, ''])));
    });
    sortSelect.addEventListener('change', () => {
        const [sort, direction] = sortSelect.value.split(':');
        updateParams({ sort, direction });
    });
    document.addEventListener('joyumkm:search', (event) => {
        event.preventDefault();
        if (event.detail !== (params.get('search') || '')) updateParams({ search: event.detail });
    }, { signal });
    document.querySelectorAll('[data-sort] button').forEach((button) => button.addEventListener('click', () => {
        const sort = button.closest('[data-sort]').dataset.sort;
        const direction = (params.get('sort') || 'score') === sort ? ((params.get('direction') || 'desc') === 'desc' ? 'asc' : 'desc') : (sort === 'score' ? 'desc' : 'asc');
        updateParams({ sort, direction });
    }));
    perPage.addEventListener('change', () => updateParams({ per_page: perPage.value }));
    document.addEventListener('joyumkm:before-route-change', (event) => {
        if (deleteModal.open && deleteButton.disabled) event.preventDefault();
    }, { signal });
    syncControls();
    await Promise.all([loadOptions(), loadList()]);
}
