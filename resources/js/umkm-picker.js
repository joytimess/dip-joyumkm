import { pageApi } from './api';
import { element, showError } from './ui';
import { createSelectControl } from './select-control';

export async function createUmkmPicker(root, { signal, initialId = null, autoSelect = false, beforeChange = () => true, onChange = () => {} }) {
    const api = pageApi(signal);
    let selected = null;
    let page = 1;
    let search = '';
    let controller;
    let sequence = 0;
    let searchTimer;
    let currentItems = [];
    const searchInput = element('input', { type: 'search', maxlength: 120, placeholder: 'Cari nama UMKM...', 'aria-label': 'Cari UMKM dalam pemilih', 'data-picker-search': true });
    const select = element('select', { 'aria-label': 'Pilih UMKM', 'data-picker-select': true, disabled: true });
    const status = element('div', { class: 'picker-status', 'aria-live': 'polite' });
    const range = element('span', { class: 'muted' });
    const previous = element('button', { type: 'button', class: 'page-button', text: 'Sebelumnya', onclick: () => { page--; loadChoices(); } });
    const next = element('button', { type: 'button', class: 'page-button', text: 'Berikutnya', onclick: () => { page++; loadChoices(); } });
    const pagination = element('div', { class: 'picker-pagination' }, [range, element('div', {}, [previous, next])]);
    const controls = element('div', { class: 'picker-controls' }, [searchInput, select]);
    const placeholder = element('div', { class: 'picker-controls', 'aria-hidden': 'true' }, [
        element('div', { class: 'skeleton h-10' }), element('div', { class: 'skeleton h-10' }),
    ]);
    controls.hidden = true;
    pagination.hidden = true;
    root.replaceChildren(placeholder, controls, status, pagination);
    const selectControl = createSelectControl(select, signal);
    signal.addEventListener('abort', () => window.clearTimeout(searchTimer), { once: true });

    function renderOptions(items) {
        currentItems = items;
        const allItems = selected && !items.some((item) => item.id === selected.id) ? [selected, ...items] : items;
        select.replaceChildren(element('option', { value: '', text: 'Pilih UMKM' }), ...allItems.map((item) => element('option', { value: item.id, text: item.name })));
        select.value = selected ? String(selected.id) : '';
        selectControl.sync();
    }

    async function loadChoices() {
        const requestSequence = ++sequence;
        controller?.abort();
        controller = new AbortController();
        status.textContent = 'Memuat pilihan UMKM…';
        root.setAttribute('aria-busy', 'true');
        select.disabled = true;
        selectControl.sync();
        previous.disabled = true;
        next.disabled = true;
        try {
            const result = await api(`/umkms?${new URLSearchParams({ search, page, per_page: 20, sort: 'name', direction: 'asc' })}`, { signal: controller.signal });
            if (requestSequence !== sequence) return;
            if (autoSelect && selected === null && result.data.length && search === '') {
                selected = result.data[0];
                autoSelect = false;
                void onChange(selected);
            }
            if (requestSequence !== sequence) return;
            renderOptions(result.data);
            status.textContent = result.data.length ? '' : (search ? 'Tidak ada UMKM sesuai pencarian.' : 'Belum ada UMKM. Tambahkan data terlebih dahulu.');
            if (!result.data.length && !search && !selected) status.append(' ', element('a', { href: '/umkms', class: 'text-link', text: 'Tambah UMKM' }));
            range.textContent = result.meta.total ? `${result.meta.from}–${result.meta.to} dari ${result.meta.total} UMKM` : '0 UMKM';
            previous.disabled = result.meta.current_page <= 1;
            next.disabled = result.meta.current_page >= result.meta.last_page;
            pagination.hidden = result.meta.last_page <= 1;
        } catch (error) {
            if (error.name !== 'AbortError' && requestSequence === sequence) showError(status, error, loadChoices);
        } finally {
            if (!signal.aborted && requestSequence === sequence) {
                root.setAttribute('aria-busy', 'false');
                placeholder.hidden = true;
                controls.hidden = false;
                select.disabled = !currentItems.length && !selected;
                selectControl.sync();
            }
        }
    }

    async function setSelected(id, notify = true) {
        if (id !== null && String(id) === String(selected?.id)) return true;
        if (!beforeChange()) { select.value = selected ? String(selected.id) : ''; selectControl.sync(); return false; }
        const candidate = id === null ? null : currentItems.find((item) => String(item.id) === String(id)) || (await api(`/umkms/${id}`)).data;
        selected = candidate;
        renderOptions(currentItems);
        if (notify) await onChange(selected);
        return true;
    }

    select.addEventListener('change', () => setSelected(select.value === '' ? null : Number(select.value)).catch((error) => {
        if (!signal.aborted && error.name !== 'AbortError') showError(status, error, loadChoices);
    }));
    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => { search = searchInput.value.trim(); page = 1; loadChoices(); }, 300);
    });
    async function loadInitial() {
        root.setAttribute('aria-busy', 'true');
        placeholder.hidden = false;
        try {
            selected = (await api(`/umkms/${initialId}`)).data;
            void onChange(selected);
        } catch (error) {
            if (signal.aborted || error.name === 'AbortError') return;
            root.setAttribute('aria-busy', 'false');
            placeholder.hidden = true;
            showError(status, error, error.status === 404 ? loadChoices : loadInitial);
            renderOptions([]);
            return;
        }
        await loadChoices();
    }
    if (initialId !== null) await loadInitial();
    else await loadChoices();
    return { select, setSelected, refresh: loadChoices, get selected() { return selected; } };
}
