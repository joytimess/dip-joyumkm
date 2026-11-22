export function element(tag, attributes = {}, children = []) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attributes)) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2).toLowerCase(), value);
        else node.setAttribute(key, value === true ? '' : String(value));
    }
    for (const child of Array.isArray(children) ? children : [children]) {
        if (child !== null && child !== undefined) node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
}

export function icon(name) {
    const namespace = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(namespace, 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS(namespace, 'use');
    use.setAttribute('href', '#joy-i-' + name);
    svg.append(use);
    return svg;
}

export const formatNumber = (value) => new Intl.NumberFormat('id-ID').format(value);
export const formatScore = (value) => value === null || value === undefined ? '—' : new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
export const escapeHTML = (value) => String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);

export function toast(message, type = 'success') {
    const region = document.getElementById('toast-region');
    if (!region) return;
    const notice = element('div', { class: `toast toast-${type}`, role: type === 'error' ? 'alert' : 'status' }, [
        element('span', { text: message }),
        element('button', { type: 'button', class: 'icon-button', 'aria-label': 'Tutup notifikasi', onclick: () => notice.remove() }, icon('close')),
    ]);
    region.append(notice);
    window.setTimeout(() => notice.remove(), type === 'error' ? 10000 : 6000);
}

export function setBusy(button, busy, label = 'Menyimpan…') {
    if (busy) {
        button.dataset.originalLabel = button.textContent;
        button.textContent = label;
    } else if (button.dataset.originalLabel) {
        button.textContent = button.dataset.originalLabel;
        delete button.dataset.originalLabel;
    }
    button.disabled = busy;
    button.setAttribute('aria-busy', String(busy));
}

export function clearErrors(form) {
    form.querySelectorAll('[data-error]').forEach((node) => { node.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach((node) => node.removeAttribute('aria-invalid'));
    const message = form.querySelector('.form-message');
    if (message) { message.hidden = true; message.textContent = ''; }
}

export function renderErrors(form, error) {
    clearErrors(form);
    let firstField;
    for (const [name, messages] of Object.entries(error.errors || {})) {
        const output = form.querySelector(`[data-error="${CSS.escape(name)}"]`);
        const field = form.elements.namedItem(name);
        if (output) output.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
        if (field instanceof HTMLElement) {
            field.setAttribute('aria-invalid', 'true');
            if (output?.id) {
                const descriptions = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
                descriptions.add(output.id);
                field.setAttribute('aria-describedby', [...descriptions].join(' '));
            }
            if (!field.closest('[hidden]')) firstField ??= document.getElementById(`${field.id}-trigger`) ?? field;
        }
    }
    const message = form.querySelector('.form-message');
    if (message) { message.textContent = error.message; message.hidden = false; }
    firstField?.focus();
}

export function showError(container, error, retry) {
    container.replaceChildren(element('div', { class: 'error-state', role: 'alert' }, [
        element('p', { text: error.message || 'Data belum dapat dimuat.' }),
        retry ? element('button', { type: 'button', class: 'button button-outline', text: 'Coba lagi', onclick: retry }) : null,
        error.status === 404 ? element('a', { href: '/umkms', class: 'text-link', text: 'Kembali ke daftar UMKM' }) : null,
    ]));
    container.setAttribute('aria-busy', 'false');
}

export function skeleton(...blocks) {
    return element('div', { class: 'grid gap-3', 'aria-hidden': 'true' }, blocks.map((classes) =>
        element('div', { class: `skeleton ${classes}` })));
}

export function openDialog(dialog, opener = document.activeElement) {
    dialog._opener = opener;
    if (!dialog.open) dialog.showModal();
    dialog.querySelector('[autofocus], input, select, button')?.focus();
}

export function bindDialog(dialog, requestClose = () => dialog.close()) {
    dialog.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => requestClose()));
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) requestClose();
    });
    dialog.addEventListener('close', () => dialog._opener?.focus());
}

let confirmation;
let pendingAnswer = null;

function ensureConfirmation() {
    if (confirmation) return confirmation;
    confirmation = element('dialog', { class: 'modal dialog confirm-dialog', 'aria-labelledby': 'confirm-dialog-title', 'aria-describedby': 'confirm-dialog-description' }, [
        element('div', { class: 'dialog-head' }, [element('h2', { id: 'confirm-dialog-title', class: 'section-title' })]),
        element('div', { class: 'dialog-body' }, [element('p', { id: 'confirm-dialog-description', class: 'section-desc' })]),
        element('div', { class: 'dialog-footer wrap' }, [
            element('button', { type: 'button', class: 'btn btn-light button button-outline', text: 'Batal', 'data-close-dialog': true, autofocus: true }),
            element('button', { type: 'button', class: 'btn btn-primary button button-primary', 'data-confirm-accept': true }),
        ]),
    ]);
    confirmation.querySelector('[data-confirm-accept]').addEventListener('click', () => {
        confirmation.returnValue = 'confirm';
        confirmation.close();
    });
    confirmation.addEventListener('close', () => {
        const resolve = pendingAnswer;
        pendingAnswer = null;
        resolve?.(confirmation.returnValue === 'confirm');
    });
    bindDialog(confirmation);
    document.body.append(confirmation);
    return confirmation;
}

/**
 * Menampilkan konfirmasi bergaya dialog aplikasi (pengganti window.confirm).
 * @param {{ title: string, message: string, confirmLabel?: string, tone?: 'primary'|'danger' }} options
 * @returns {Promise<boolean>} true jika pengguna memilih aksi lanjut
 */
export function confirmAction({ title, message, confirmLabel = 'Lanjutkan', tone = 'primary' }) {
    const dialog = ensureConfirmation();
    pendingAnswer?.(false);
    dialog.querySelector('#confirm-dialog-title').textContent = title;
    dialog.querySelector('#confirm-dialog-description').textContent = message;
    const accept = dialog.querySelector('[data-confirm-accept]');
    accept.textContent = confirmLabel;
    accept.className = tone === 'danger' ? 'btn button button-danger' : 'btn btn-primary button button-primary';
    dialog.returnValue = '';
    return new Promise((resolve) => {
        pendingAnswer = resolve;
        openDialog(dialog, document.activeElement);
    });
}
