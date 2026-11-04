import { api, ApiError } from '../api';
import { createSummary } from '../summary';
import { element, formatNumber, formatScore, bindDialog, openDialog, clearErrors, renderErrors, setBusy, showError, toast } from '../ui';

export async function init() {
    const summary = createSummary(document.getElementById('umkm-summary'));
    window.addEventListener('joyumkm:umkm-mutated', summary.reload);
    const listState = document.getElementById('umkm-list-state');
    const tableArea = document.getElementById('umkm-table-area');
    const rows = document.getElementById('umkm-rows');
    const range = document.getElementById('umkm-range');
    const filterForm = document.getElementById('umkm-filters');
    const modal = document.getElementById('umkm-modal');
    const form = document.getElementById('umkm-form');
    const saveButton = document.getElementById('save-umkm');
    const deleteModal = document.getElementById('delete-umkm-modal');
    const deleteButton = document.getElementById('confirm-delete-umkm');
    const addButton = document.getElementById('add-umkm');
    const omzetInput = form.elements.omzet;
    const allowedParams = ['search', 'sector_id', 'province', 'category', 'status', 'sort', 'direction', 'page', 'per_page'];
    let params = readParams();
    let options;
    let latestList;
    let listController;
    let listSequence = 0;
    let editController;
    let editId = null;
    let deletingId = null;
    let omzetValue = null;
    let saving = false;
    bindDialog(modal);
    bindDialog(deleteModal);

    function readParams() {
        const query = new URLSearchParams(window.location.search);
        return new URLSearchParams([...query].filter(([key, value]) => allowedParams.includes(key) && value !== ''));
    }

    function syncControls() {
        document.getElementById('global-search').value = params.get('search') || '';
        for (const name of ['sector_id', 'province', 'category', 'status']) filterForm.elements[name].value = params.get(name) || '';
        document.getElementById('umkm-per-page').value = params.get('per_page') || '10';
        const sort = params.get('sort') || 'score';
        const direction = params.get('direction') || 'desc';
        document.querySelectorAll('[data-sort]').forEach((header) => {
            const active = header.dataset.sort === sort;
            header.setAttribute('aria-sort', active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
            header.querySelector('[data-sort-direction]').textContent = active ? (direction === 'asc' ? '↑' : '↓') : '';
        });
    }

    function updateParams(changes, resetPage = true) {
        for (const [key, value] of Object.entries(changes)) {
            if (value === '' || value === null) params.delete(key);
            else params.set(key, String(value));
        }
        if (resetPage) params.delete('page');
        const query = params.toString();
        window.history.pushState({}, '', `/umkms${query ? `?${query}` : ''}`);
        syncControls();
        loadList();
    }

    async function loadOptions() {
        const state = document.getElementById('options-state');
        state.replaceChildren();
        try {
            ({ data: options } = await api('/umkms/options'));
            const appendOptions = (select, values, getValue, getLabel) => {
                select.replaceChildren(select.options[0], ...values.map((value) => element('option', { value: getValue(value), text: getLabel(value) })));
            };
            for (const id of ['umkm-sector', 'filter-sector']) appendOptions(document.getElementById(id), options.sectors, (sector) => sector.id, (sector) => sector.name);
            for (const id of ['umkm-province', 'filter-province']) appendOptions(document.getElementById(id), options.provinces, (province) => province, (province) => province);
            appendOptions(document.getElementById('filter-category'), options.categories, (category) => category.label, (category) => category.label);
            addButton.disabled = false;
            syncControls();
            if (latestList) renderList(latestList);
        } catch (error) {
            showError(state, error, loadOptions);
        }
    }

    async function loadList() {
        const sequence = ++listSequence;
        listController?.abort();
        listController = new AbortController();
        listState.hidden = false;
        listState.setAttribute('aria-busy', 'true');
        listState.replaceChildren(element('p', { class: 'table-loading', text: 'Memuat data UMKM…' }));
        try {
            const result = await api(`/umkms?${params}`, { signal: listController.signal });
            if (sequence !== listSequence) return;
            if (!result.data.length && result.meta.current_page > result.meta.last_page) {
                updateParams({ page: result.meta.last_page }, false);
                return;
            }
            renderList(result);
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== listSequence) return;
            tableArea.hidden = true;
            range.textContent = 'Data UMKM belum dapat dimuat.';
            showError(listState, error, loadList);
        }
    }

    function renderList(result) {
        latestList = result;
        const { data, meta } = result;
        rows.replaceChildren();
        range.textContent = meta.total === 0 ? 'Menampilkan 0 dari 0 responden' : `Menampilkan ${formatNumber(meta.from)}–${formatNumber(meta.to)} dari ${formatNumber(meta.total)} responden`;
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
        data.forEach((umkm, index) => {
            const category = options?.categories.find((item) => item.label === umkm.category);
            const recommendation = element('span', { class: 'category-label', text: umkm.recommendation });
            if (category) recommendation.style.color = category.color;
            const editButton = element('button', { type: 'button', class: 'row-action', text: 'Ubah', onclick: () => openEdit(umkm.id, editButton) });
            const removeButton = element('button', { type: 'button', class: 'row-action text-danger', text: 'Hapus', onclick: () => openDelete(umkm, removeButton) });
            rows.append(element('tr', {}, [
                element('td', { class: 'num', text: meta.from + index }),
                element('td', { class: 'umkm-name', text: umkm.name }),
                element('td', { class: 'table-location', text: umkm.province }),
                element('td', {}, [element('span', { class: 'sector-badge', text: umkm.sector.name })]),
                element('td', { class: 'num', text: formatNumber(umkm.omzet) }),
                element('td', { class: 'num' }, [element('strong', { text: formatScore(umkm.latest_score) }), umkm.evaluation_status === 'draft' ? element('span', { class: 'draft-badge', text: 'Sementara' }) : null]),
                element('td', {}, [element('a', { href: `/factor-profile?umkm_id=${umkm.id}`, class: 'row-action', text: 'Lihat profil faktor' })]),
                element('td', {}, [recommendation]),
                element('td', {}, [element('div', { class: 'row-actions' }, [element('a', { href: `/form-data?umkm=${umkm.id}`, class: 'row-action', text: 'Isi Kuesioner' }), editButton, removeButton])]),
            ]));
        });
        document.getElementById('umkm-page-info').textContent = `Halaman ${meta.current_page} dari ${meta.last_page} (${formatNumber(meta.total)} data)`;
        const pagination = document.getElementById('umkm-pagination');
        const pageButton = (label, page, disabled = false, current = false) => element('button', {
            type: 'button', class: `page-button${current ? ' is-current' : ''}`, text: label, disabled,
            'aria-current': current ? 'page' : null, 'aria-label': current ? `Halaman ${page}, saat ini` : `Ke halaman ${label}`,
            onclick: () => updateParams({ page }, false),
        });
        pagination.replaceChildren(pageButton('Sebelumnya', meta.current_page - 1, meta.current_page <= 1));
        const firstPage = Math.max(1, Math.min(meta.current_page - 1, meta.last_page - 2));
        for (let page = firstPage; page <= Math.min(meta.last_page, firstPage + 2); page++) pagination.append(pageButton(String(page), page, false, page === meta.current_page));
        pagination.append(pageButton('Berikutnya', meta.current_page + 1, meta.current_page >= meta.last_page));
    }

    function openAdd() {
        editController?.abort();
        editId = null;
        form.reset();
        omzetValue = null;
        clearErrors(form);
        document.getElementById('umkm-modal-title').textContent = 'Tambah Data UMKM';
        openDialog(modal, addButton);
        form.elements.name.focus();
    }

    async function openEdit(id, opener) {
        if (!options) { toast('Pilihan sektor dan provinsi belum dimuat. Coba lagi.', 'error'); return; }
        editController?.abort();
        editController = new AbortController();
        const controller = editController;
        setBusy(opener, true, 'Memuat…');
        try {
            const { data: umkm } = await api(`/umkms/${id}`, { signal: controller.signal });
            if (controller !== editController) return;
            form.reset();
            clearErrors(form);
            editId = umkm.id;
            document.getElementById('umkm-modal-title').textContent = 'Ubah Data UMKM';
            form.elements.name.value = umkm.name;
            form.elements.province.value = umkm.province;
            form.elements.sector_id.value = String(umkm.sector.id);
            omzetValue = umkm.omzet;
            omzetInput.value = formatNumber(omzetValue);
            openDialog(modal, opener);
            form.elements.name.focus();
        } catch (error) {
            if (error.name !== 'AbortError') toast(error.message, 'error');
        } finally { setBusy(opener, false); }
    }

    function openDelete(umkm, opener) {
        deletingId = umkm.id;
        document.getElementById('delete-umkm-name').textContent = umkm.name;
        document.getElementById('delete-umkm-error').hidden = true;
        openDialog(deleteModal, opener);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving) return;
        clearErrors(form);
        if (omzetValue === null || !Number.isSafeInteger(omzetValue) || omzetValue < 0) {
            renderErrors(form, new ApiError('Periksa kembali data Anda.', 422, { omzet: ['Isi omzet sebagai rupiah nonnegatif, maksimal 9.007.199.254.740.991.'] }));
            return;
        }
        saving = true;
        const currentId = editId;
        setBusy(saveButton, true);
        try {
            await api(currentId === null ? '/umkms' : `/umkms/${currentId}`, { method: currentId === null ? 'POST' : 'PATCH', body: { name: form.elements.name.value, province: form.elements.province.value, sector_id: form.elements.sector_id.value, omzet: omzetValue } });
            modal.close();
            toast(currentId === null ? 'Data UMKM berhasil ditambahkan.' : 'Data UMKM berhasil diperbarui.');
            await loadList();
            window.dispatchEvent(new Event('joyumkm:umkm-mutated'));
        } catch (error) { renderErrors(form, error); }
        finally { saving = false; setBusy(saveButton, false); }
    });
    omzetInput.addEventListener('input', () => {
        const digits = omzetInput.value.replace(/\D/g, '');
        omzetValue = digits === '' ? null : Number(digits);
        if (digits === '') omzetInput.value = '';
        else if (Number.isSafeInteger(omzetValue)) omzetInput.value = formatNumber(omzetValue);
    });
    deleteButton.addEventListener('click', async () => {
        if (deleteButton.disabled || deletingId === null) return;
        setBusy(deleteButton, true, 'Menghapus…');
        try {
            await api(`/umkms/${deletingId}`, { method: 'DELETE' });
            deleteModal.close();
            toast('UMKM beserta evaluasinya berhasil dihapus.');
            await loadList();
            window.dispatchEvent(new Event('joyumkm:umkm-mutated'));
        } catch (error) {
            const output = document.getElementById('delete-umkm-error');
            output.textContent = error.message;
            output.hidden = false;
        } finally { setBusy(deleteButton, false); }
    });
    document.getElementById('filter-toggle').addEventListener('click', (event) => {
        filterForm.hidden = !filterForm.hidden;
        event.currentTarget.setAttribute('aria-expanded', String(!filterForm.hidden));
    });
    filterForm.addEventListener('submit', (event) => { event.preventDefault(); updateParams(Object.fromEntries(new FormData(filterForm))); });
    document.getElementById('reset-filters').addEventListener('click', () => updateParams({ sector_id: '', province: '', category: '', status: '', search: '' }));
    window.addEventListener('joyumkm:search', (event) => updateParams({ search: event.detail }));
    window.addEventListener('popstate', () => { params = readParams(); syncControls(); loadList(); });
    document.querySelectorAll('[data-sort] button').forEach((button) => button.addEventListener('click', () => {
        const sort = button.closest('[data-sort]').dataset.sort;
        const direction = (params.get('sort') || 'score') === sort ? ((params.get('direction') || 'desc') === 'desc' ? 'asc' : 'desc') : (sort === 'score' ? 'desc' : 'asc');
        updateParams({ sort, direction });
    }));
    document.getElementById('umkm-per-page').addEventListener('change', (event) => updateParams({ per_page: event.target.value }));
    addButton.addEventListener('click', openAdd);
    syncControls();
    await Promise.all([loadOptions(), loadList()]);
}
