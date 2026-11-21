import { element } from './ui';

let selectSequence = 0;

export function createSelectControl(select, signal) {
    const field = select.parentElement;
    const label = select.id ? document.querySelector(`label[for="${select.id}"]`) : null;
    const name = label?.textContent.trim() || select.getAttribute('aria-label') || 'Pilihan';
    if (!select.id) select.id = `custom-select-${++selectSequence}`;
    const value = element('span', { id: `${select.id}-value` });
    const trigger = element('button', {
        id: `${select.id}-trigger`, type: 'button', class: 'button button-outline province-filter-trigger',
        'aria-expanded': 'false', 'aria-controls': `${select.id}-panel`,
        'aria-labelledby': `${select.id}-label ${select.id}-value`,
    }, value);
    if (label) { label.id = `${select.id}-label`; label.htmlFor = trigger.id; }
    else { trigger.removeAttribute('aria-labelledby'); trigger.setAttribute('aria-label', name); }
    const search = element('input', { type: 'search', placeholder: `Cari ${name.toLowerCase()}…`, 'aria-label': `Cari ${name.toLowerCase()}`, autocomplete: 'off' });
    const list = element('ul', { class: 'province-filter-options', 'aria-label': `Pilihan ${name}` });
    const empty = element('p', { class: 'muted province-filter-empty', role: 'status', hidden: true, text: 'Pilihan tidak ditemukan.' });
    const panel = element('div', { id: `${select.id}-panel`, class: 'province-filter-panel', hidden: true }, [search, list, empty]);
    const dialog = select.closest('dialog');
    if (dialog) panel.setAttribute('popover', 'manual');
    select.classList.add('custom-select-native');
    select.tabIndex = -1;
    select.addEventListener('invalid', () => trigger.focus());
    select.after(element('div', { class: 'province-filter-control' }, [trigger, panel]));
    const close = () => {
        if (dialog && panel.matches(':popover-open')) panel.hidePopover();
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    };
    const positionPanel = () => {
        if (!dialog || panel.hidden) return;
        const bounds = trigger.getBoundingClientRect();
        panel.style.left = `${bounds.left}px`;
        panel.style.top = `${bounds.bottom + 8}px`;
        panel.style.width = `${bounds.width}px`;
        panel.style.maxHeight = `${Math.max(0, window.innerHeight - bounds.bottom - 16)}px`;
    };
    dialog?.addEventListener('scroll', positionPanel, { signal });
    dialog?.addEventListener('close', close, { signal });
    window.addEventListener('resize', positionPanel, { signal });
    const sync = () => {
        value.textContent = (select.selectedOptions[0] || select.options[0])?.label || name;
        trigger.disabled = select.disabled;
        if (select.hasAttribute('aria-invalid')) trigger.setAttribute('aria-invalid', select.getAttribute('aria-invalid'));
        else trigger.removeAttribute('aria-invalid');
        if (select.hasAttribute('aria-describedby')) trigger.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
        else trigger.removeAttribute('aria-describedby');
        close();
    };
    const render = () => {
        const term = search.value.trim().toLocaleLowerCase('id-ID');
        const matches = [...select.options].filter((option) => option.label.toLocaleLowerCase('id-ID').includes(term));
        list.replaceChildren(...matches.map((option) => element('li', {}, element('button', {
            type: 'button', text: option.label, 'aria-pressed': option.selected ? 'true' : 'false',
            onclick: () => { select.value = option.value; sync(); select.dispatchEvent(new Event('change', { bubbles: true })); trigger.focus(); },
        }))));
        list.scrollTop = 0;
        empty.hidden = matches.length > 0;
    };
    trigger.addEventListener('click', () => {
        if (!panel.hidden) { close(); return; }
        search.value = '';
        search.hidden = select.options.length <= 4;
        render();
        panel.hidden = false;
        if (dialog) { positionPanel(); panel.showPopover(); }
        trigger.setAttribute('aria-expanded', 'true');
        (search.hidden ? list.querySelector('button') : search)?.focus();
    });
    search.addEventListener('input', render);
    field.addEventListener('focusout', (event) => { if (event.relatedTarget && !field.contains(event.relatedTarget)) close(); });
    document.addEventListener('pointerdown', (event) => { if (!panel.hidden && !field.contains(event.target)) close(); }, { signal });
    field.addEventListener('keydown', (event) => {
        if (panel.hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); trigger.focus(); }
        else if (event.target === search && event.key === 'Enter') { event.preventDefault(); list.querySelector('button')?.click(); }
        else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const buttons = [...list.querySelectorAll('button')];
            const index = buttons.indexOf(event.target);
            const next = event.key === 'ArrowDown' ? Math.min(index + 1, buttons.length - 1) : index - 1;
            (buttons[next] || (search.hidden ? trigger : search)).focus();
        }
    });
    select.addEventListener('change', sync);
    const observer = new MutationObserver(sync);
    observer.observe(select, { childList: true, subtree: true, attributes: true });
    signal.addEventListener('abort', () => observer.disconnect(), { once: true });
    sync();
    return { sync, close };
}
