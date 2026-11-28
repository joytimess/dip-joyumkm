import { pageApi } from '../api';
import { isSuperadmin } from '../role';
import { bindDialog, clearErrors, confirmAction, element, formatNumber, openDialog, renderErrors, setBusy, showError, toast } from '../ui';

function accessDenied(root) {
    root.replaceChildren(element('div', { class: 'error-state', role: 'alert' }, [element('p', { text: 'Anda tidak memiliki akses ke bagian ini.' })]));
    root.setAttribute('aria-busy', 'false');
}

function field(label, name, attributes = {}) {
    return element('div', { class: 'field form-field full' }, [
        element('label', { for: `analyst-${name}`, text: label }),
        element('input', { id: `analyst-${name}`, name, class: 'control', required: true, ...attributes }),
        element('p', { class: 'field-error', 'data-error': name, id: `analyst-error-${name}` }),
    ]);
}

export function init(signal) {
    const root = document.getElementById('analyst-state');
    if (!isSuperadmin()) { accessDenied(root); return; }
    const api = pageApi(signal);
    const addButton = document.getElementById('analyst-add');
    const search = document.getElementById('analyst-search');
    const status = document.getElementById('analyst-status');
    const pager = document.getElementById('analyst-pager');
    const count = document.getElementById('analyst-count');
    let page = 1;
    let timer;
    let editing = null;
    addButton.hidden = false;

    const form = element('form', { novalidate: true, autocomplete: 'off' });
    const message = element('div', { class: 'form-message', role: 'alert', tabindex: '-1', hidden: true });
    const submit = element('button', { type: 'button', class: 'btn btn-primary button button-primary', text: 'Simpan' });
    const title = element('h2', { class: 'section-title', id: 'analyst-dialog-title' });
    const dialog = element('dialog', { class: 'modal dialog', 'aria-labelledby': 'analyst-dialog-title' }, [
        element('div', { class: 'dialog-head modal-heading' }, [title, element('button', { type: 'button', class: 'icon-button', 'data-close-dialog': true, 'aria-label': 'Tutup formulir', text: '×' })]),
        element('div', { class: 'dialog-body' }, [message, form]),
        element('div', { class: 'dialog-footer modal-actions' }, [
            element('button', { type: 'button', class: 'btn btn-light button button-outline', 'data-close-dialog': true, text: 'Batal' }), submit,
        ]),
    ]);
    document.getElementById('main-content').append(dialog);
    bindDialog(dialog);
    // Kata sandi tidak boleh bertahan: formulir dikosongkan setiap dialog ditutup.
    dialog.addEventListener('close', () => { form.reset(); form.replaceChildren(); });

    function openForm(analyst, opener) {
        editing = analyst;
        title.textContent = analyst ? 'Ubah Analyst' : 'Tambah Analyst';
        form.replaceChildren(
            field('Nama', 'name', { maxlength: 120, value: analyst?.name ?? '' }),
            field('Email', 'email', { type: 'email', maxlength: 255, value: analyst?.email ?? '' }),
            ...(analyst ? [] : [
                field('Kata sandi', 'password', { type: 'password', minlength: 8, autocomplete: 'new-password' }),
                field('Konfirmasi kata sandi', 'password_confirmation', { type: 'password', autocomplete: 'new-password' }),
                element('p', { class: 'muted tiny', text: 'Akun dibuat sebagai Analyst. Pengiriman onboarding berjalan terpisah dan tidak dijamin oleh pembuatan akun.' }),
            ]),
        );
        message.hidden = true;
        openDialog(dialog, opener);
    }

    submit.addEventListener('click', async () => {
        if (submit.disabled) return;
        clearErrors(form);
        message.hidden = true;
        const body = Object.fromEntries(new FormData(form));
        setBusy(submit, true, 'Menyimpan…');
        try {
            await api(editing ? `/admin/analysts/${editing.id}` : '/admin/analysts', { method: editing ? 'PATCH' : 'POST', body });
            toast(editing ? 'Data Analyst diperbarui.' : 'Akun Analyst dibuat. Pengiriman onboarding diproses terpisah.');
            dialog.close();
            await load();
        } catch (error) {
            if (error.name === 'AbortError') return;
            renderErrors(form, error);
            message.textContent = error.message;
            message.hidden = false;
        } finally {
            setBusy(submit, false);
        }
    });

    async function toggle(analyst, active) {
        const confirmed = await confirmAction(active
            ? { title: 'Aktifkan kembali Analyst?', message: `${analyst.name} dapat masuk lagi, tetapi harus login ulang. Sesi lama tidak dipulihkan.`, confirmLabel: 'Aktifkan' }
            : { title: 'Nonaktifkan Analyst?', message: `${analyst.name} tidak akan bisa masuk dan sesi aktifnya dicabut. UMKM, laporan, dan riwayatnya tetap tersimpan serta tampil di statistik global.`, confirmLabel: 'Nonaktifkan', tone: 'danger' });
        if (!confirmed) return;
        try {
            await api(`/admin/analysts/${analyst.id}/status`, { method: 'PATCH', body: { is_active: active } });
            toast(active ? 'Analyst diaktifkan.' : 'Analyst dinonaktifkan.');
            await load();
        } catch (error) {
            if (error.name !== 'AbortError') toast(error.message, 'error');
        }
    }

    function render({ data, meta }) {
        if (!data.length) {
            root.replaceChildren(element('div', { class: 'empty-state' }, [element('h2', { text: 'Belum ada Analyst' }), element('p', { class: 'muted', text: 'Tambahkan Analyst atau ubah filter pencarian.' })]));
        } else {
            root.replaceChildren(element('div', { class: 'data-table-wrap table-scroll', role: 'region', 'aria-label': 'Tabel Analyst', tabindex: '0' }, [
                element('table', { class: 'data-table' }, [
                    element('thead', {}, [element('tr', {}, ['NAMA', 'EMAIL', 'STATUS', 'UMKM', 'KOHORT / NON-KOHORT', 'AKSI'].map((text) => element('th', { scope: 'col', text })))]),
                    element('tbody', {}, data.map((analyst) => element('tr', {}, [
                        element('td', {}, element('strong', { text: analyst.name })),
                        element('td', { class: 'muted', text: analyst.email }),
                        element('td', {}, element('span', { class: `pill ${analyst.is_active ? 'pill-green' : 'pill-muted'}`, text: analyst.is_active ? 'Aktif' : 'Nonaktif' })),
                        element('td', { class: 'num', text: formatNumber(analyst.umkms_count) }),
                        element('td', { class: 'num muted', text: `${formatNumber(analyst.cohort_umkms_count)} / ${formatNumber(analyst.non_cohort_umkms_count)}` }),
                        element('td', {}, element('div', { class: 'hstack wrap' }, [
                            element('button', { type: 'button', class: 'button button-outline btn-sm', text: 'Ubah', 'aria-label': `Ubah ${analyst.name}`, onclick: (event) => openForm(analyst, event.currentTarget) }),
                            element('button', { type: 'button', class: `button btn-sm ${analyst.is_active ? 'button-danger' : 'button-outline'}`, text: analyst.is_active ? 'Nonaktifkan' : 'Aktifkan', 'aria-label': `${analyst.is_active ? 'Nonaktifkan' : 'Aktifkan'} ${analyst.name}`, onclick: () => toggle(analyst, !analyst.is_active) }),
                        ])),
                    ]))),
                ]),
            ]));
        }
        count.textContent = meta.total ? `Menampilkan ${formatNumber(meta.from)}–${formatNumber(meta.to)} dari ${formatNumber(meta.total)} Analyst` : '0 Analyst';
        pager.hidden = meta.last_page <= 1;
        document.getElementById('analyst-prev').disabled = meta.current_page <= 1;
        document.getElementById('analyst-next').disabled = meta.current_page >= meta.last_page;
    }

    async function load() {
        root.setAttribute('aria-busy', 'true');
        const params = new URLSearchParams({ page, per_page: 10 });
        if (search.value.trim()) params.set('search', search.value.trim());
        if (status.value) params.set('status', status.value);
        try {
            render(await api(`/admin/analysts?${params}`));
        } catch (error) {
            if (error.name !== 'AbortError') showError(root, error, load);
        } finally {
            root.setAttribute('aria-busy', 'false');
        }
    }

    addButton.addEventListener('click', (event) => openForm(null, event.currentTarget), { signal });
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; load(); }, 300); }, { signal });
    status.addEventListener('change', () => { page = 1; load(); }, { signal });
    document.getElementById('analyst-prev').addEventListener('click', () => { page -= 1; load(); }, { signal });
    document.getElementById('analyst-next').addEventListener('click', () => { page += 1; load(); }, { signal });
    signal.addEventListener('abort', () => { clearTimeout(timer); dialog.close(); dialog.remove(); });
    load();
}
