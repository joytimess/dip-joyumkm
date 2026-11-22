import { ApiError } from './api';
import { fieldError } from './umkm-form';
import { clearErrors, confirmAction, renderErrors, setBusy } from './ui';

export const discardConfirmation = {
    title: 'Buang perubahan?',
    message: 'Perubahan belum disimpan. Buang perubahan dan lanjutkan?',
    confirmLabel: 'Buang perubahan',
    tone: 'danger',
};

/**
 * Editor inline untuk satu tab Detail UMKM. Hanya satu draft aktif per editor, dan submit
 * hanya mengirim field milik tab tersebut.
 */
export function createInlineEditor({
    form, view, editButton, signal, prefill, validate = () => ({}), toPayload, submit, onStart = () => {}, onEnd = () => {}, onSaved,
}) {
    const fieldset = form.querySelector('fieldset');
    const saveButton = form.querySelector('[data-save]');
    const cancelButton = form.querySelector('[data-cancel]');
    const fields = [...form.querySelectorAll('[name]')];
    let active = false;
    let saving = false;
    let confirming = false;
    let snapshot = '';

    const formValues = () => JSON.stringify(fields.map((field) => field.value));
    const isDirty = () => active && snapshot !== formValues();

    function setActive(on) {
        active = on;
        view.hidden = on;
        form.hidden = !on;
        editButton.hidden = on;
    }

    function setSaving(busy) {
        saving = busy;
        fieldset.disabled = busy;
        cancelButton.disabled = busy;
        setBusy(saveButton, busy);
    }

    function exit() {
        clearErrors(form);
        setActive(false);
        onEnd();
    }

    function enter() {
        if (active || saving) return;
        clearErrors(form);
        prefill(form);
        snapshot = formValues();
        setActive(true);
        onStart();
        form.querySelector('input:not([readonly]), textarea')?.focus();
    }

    /** Mengembalikan true bila editor sudah tertutup dan tab boleh berpindah. */
    async function leave() {
        if (!active) return true;
        if (saving) return false;
        if (isDirty()) {
            if (confirming) return false;
            confirming = true;
            const accepted = await confirmAction(discardConfirmation).finally(() => { confirming = false; });
            if (!accepted || signal.aborted) return false;
        }
        exit();
        return true;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving || !active) return;
        clearErrors(form);
        const errors = { ...validate(form) };
        for (const field of fields) {
            if (errors[field.name]) continue;
            const message = fieldError(field, field.value.trim());
            if (message) errors[field.name] = [message];
        }
        if (Object.keys(errors).length) {
            renderErrors(form, new ApiError('Periksa kembali data Anda.', 422, errors));
            return;
        }

        setSaving(true);
        let saved;
        try {
            saved = await submit(toPayload(form));
        } catch (error) {
            if (signal.aborted || error.name === 'AbortError') return;
            renderErrors(form, error);
            return;
        } finally {
            setSaving(false);
        }
        exit();
        onSaved(saved);
    });

    editButton.addEventListener('click', enter, { signal });
    cancelButton.addEventListener('click', () => { if (!saving) exit(); }, { signal });

    return {
        enter,
        leave,
        isDirty,
        isActive: () => active,
        isSaving: () => saving,
    };
}
