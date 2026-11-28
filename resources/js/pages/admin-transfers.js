import { pageApi } from '../api';
import { formatWib, isSuperadmin } from '../role';
import { element, formatNumber, showError } from '../ui';

export function init(signal) {
    const root = document.getElementById('transfer-state');
    if (!isSuperadmin()) {
        root.replaceChildren(element('div', { class: 'error-state', role: 'alert' }, [element('p', { text: 'Anda tidak memiliki akses ke bagian ini.' })]));
        root.setAttribute('aria-busy', 'false');
        return;
    }
    const api = pageApi(signal);
    const pager = document.getElementById('transfer-pager');
    const umkmId = new URLSearchParams(location.search).get('umkm_id');
    let page = 1;

    async function load() {
        root.setAttribute('aria-busy', 'true');
        const params = new URLSearchParams({ page, per_page: 10 });
        if (umkmId) params.set('umkm_id', umkmId);
        try {
            const { data, meta } = await api(`/admin/ownership-transfers?${params}`);
            root.replaceChildren(data.length
                ? element('div', { class: 'data-table-wrap table-scroll', role: 'region', 'aria-label': 'Tabel riwayat transfer', tabindex: '0' }, [
                    element('table', { class: 'data-table' }, [
                        element('thead', {}, [element('tr', {}, ['WAKTU', 'UMKM', 'DARI', 'KE', 'OLEH', 'ALASAN'].map((text) => element('th', { scope: 'col', text })))]),
                        element('tbody', {}, data.map((row) => element('tr', {}, [
                            element('td', { text: formatWib(row.transferred_at) }),
                            element('td', {}, [element('strong', { text: row.umkm.name }), element('div', { class: 'small muted', text: `ID ${row.umkm.id}` })]),
                            element('td', { text: row.from.name }),
                            element('td', { text: row.to.name }),
                            element('td', { text: row.performed_by.name }),
                            element('td', { text: row.reason ?? '—' }),
                        ]))),
                    ]),
                ])
                : element('div', { class: 'empty-state' }, [element('h2', { text: 'Belum ada transfer' }), element('p', { class: 'muted', text: 'Transfer kepemilikan yang dilakukan Superadmin akan tercatat di sini.' })]));
            document.getElementById('transfer-count').textContent = meta.total ? `Menampilkan ${formatNumber(meta.from)}–${formatNumber(meta.to)} dari ${formatNumber(meta.total)} transfer` : '0 transfer';
            pager.hidden = meta.last_page <= 1;
            document.getElementById('transfer-prev').disabled = meta.current_page <= 1;
            document.getElementById('transfer-next').disabled = meta.current_page >= meta.last_page;
        } catch (error) {
            if (error.name !== 'AbortError') showError(root, error, load);
        } finally {
            root.setAttribute('aria-busy', 'false');
        }
    }

    document.getElementById('transfer-prev').addEventListener('click', () => { page -= 1; load(); }, { signal });
    document.getElementById('transfer-next').addEventListener('click', () => { page += 1; load(); }, { signal });
    load();
}
