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

export const formatNumber = (value) => new Intl.NumberFormat('id-ID').format(value);
export const formatScore = (value) => value === null || value === undefined ? '—' : new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
export const escapeHTML = (value) => String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);

export function toast(message, type = 'success') {
    const region = document.getElementById('toast-region');
    if (!region) return;
    const notice = element('div', { class: `toast toast-${type}`, role: type === 'error' ? 'alert' : 'status' }, [
        element('span', { text: message }),
        element('button', { type: 'button', 'aria-label': 'Tutup notifikasi', text: '×', onclick: () => notice.remove() }),
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
            if (output?.id) field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), output.id].filter(Boolean).join(' '));
            firstField ??= field;
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

export function openDialog(dialog, opener = document.activeElement) {
    dialog._opener = opener;
    if (!dialog.open) dialog.showModal();
    dialog.querySelector('[autofocus], input, select, button')?.focus();
}

export function bindDialog(dialog) {
    dialog.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => dialog._opener?.focus());
}
