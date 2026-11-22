import { ApiError } from './api';
import { createSelectControl } from './select-control';
import { bindDialog, clearErrors, confirmAction, formatNumber, openDialog, renderErrors, setBusy, toast } from './ui';
import { localWhatsappDigits, whatsappPayload } from './whatsapp-number';

const stepTitles = ['Informasi Usaha', 'Lokasi Usaha', 'Kontak Usaha'];
const whatsappError = 'Masukkan nomor HP yang valid, misalnya 81234567890.';
const whatsappMessages = {
    pending: 'Memeriksa nomor WhatsApp…',
    registered: 'Terdaftar di WhatsApp.',
    unregistered: 'Tidak terdaftar. Periksa nomor. Data tetap dapat disimpan.',
    unchecked: 'Belum diperiksa. Pemeriksaan gagal atau tidak tersedia. Data tetap dapat disimpan.',
};
const discardConfirmation = {
    title: 'Buang perubahan?',
    message: 'Perubahan belum disimpan. Buang perubahan dan lanjutkan?',
    confirmLabel: 'Buang perubahan',
    tone: 'danger',
};

function fieldError(field, value) {
    const label = field.closest('.field').querySelector('label').textContent;
    if (field.required && !value) return `${label} wajib diisi.`;
    if (field.name === 'whatsapp_number' && (field.validity.customError || !whatsappPayload(value))) return whatsappError;
    if (field.validity.badInput || field.validity.stepMismatch
        || (value && ['year_founded', 'employee_count'].includes(field.name) && !/^[0-9]+$/.test(value))) {
        return `${label} harus berupa ${field.step === 'any' ? 'angka' : 'bilangan bulat'}.`;
    }
    if (field.validity.rangeOverflow || field.validity.rangeUnderflow) {
        return `${label} harus bernilai antara ${field.min} dan ${field.max}.`;
    }
    if (field.type === 'email' && field.validity.typeMismatch) return 'Masukkan email usaha yang valid.';
    if (field.type === 'url' && value && (field.validity.typeMismatch || !/^https?:\/\//i.test(value))) {
        return 'Masukkan URL yang dimulai dengan http:// atau https://.';
    }
    if (field.validity.patternMismatch) {
        return field.name === 'nib' ? 'NIB harus terdiri dari 13 digit.' : 'Kode pos harus terdiri dari 5 digit.';
    }
    return null;
}

export function bindUmkmForm({ api, signal, addButton, onSaved }) {
    const modal = document.getElementById('umkm-modal');
    const form = document.getElementById('umkm-form');
    const title = document.getElementById('umkm-modal-title');
    const stepTitle = document.getElementById('umkm-step-title');
    const requiredNote = modal.querySelector('.umkm-required-note');
    const stepList = modal.querySelector('.umkm-steps');
    const stepFields = [...form.querySelectorAll('[data-step]')];
    const fieldset = form.querySelector('fieldset');
    const formFields = [...fieldset.querySelectorAll('[name]')];
    const saveButton = document.getElementById('save-umkm');
    const backButton = document.getElementById('umkm-step-back');
    const nextButton = document.getElementById('umkm-step-next');
    const closeButtons = [...modal.querySelectorAll('[data-close-dialog]')];
    const omzetInput = form.elements.omzet;
    const whatsappInput = form.elements.whatsapp_number;
    const whatsappStatus = document.getElementById('umkm-whatsapp-status');
    const selects = [form.elements.province, form.elements.sector_id, form.elements.omzet_period]
        .map((select) => createSelectControl(select, signal));
    let step = 0;
    let editId = null;
    let editController;
    let whatsappController;
    let checkedNumber;
    let omzetValue = null;
    let saving = false;
    let confirming = false;
    let initialValues;
    let location;
    let loadingLocation;
    const mapCanvas = form.querySelector('#umkm-map');

    const formValues = () => JSON.stringify(formFields.map((field) => field.value));
    const hasUnsavedEdits = () => modal.open && initialValues !== formValues();

    function showStep(index, focus = false) {
        step = index;
        const creating = editId === null;
        modal.toggleAttribute('data-wizard', creating);
        title.textContent = creating ? 'Tambah Data UMKM' : 'Ubah Data UMKM';
        stepList.hidden = stepTitle.hidden = requiredNote.hidden = !creating;
        stepTitle.textContent = stepTitles[step];
        [...stepList.children].forEach((item, position) => {
            if (creating && position === step) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
        });
        stepFields.forEach((field) => {
            field.hidden = creating ? Number(field.dataset.step) !== step : !field.hasAttribute('data-edit-field');
        });
        backButton.hidden = !creating || step === 0;
        nextButton.hidden = !creating || step === 2;
        saveButton.hidden = creating && step !== 2;
        saveButton.textContent = creating ? 'Simpan' : 'Simpan Data';
        selects.forEach(({ close }) => close());
        modal.querySelector('.dialog-body').scrollTop = 0;
        if (focus) stepTitle.focus();
        if (creating && step === 1) showLocation();
    }

    function showLocation() {
        if (location) {
            location.show();
            return;
        }
        loadingLocation ??= import('./umkm-location').then(({ bindUmkmLocation }) => {
            if (signal.aborted) return;
            location = bindUmkmLocation({
                form, api, signal, syncProvince: selects[0].sync,
                onPick: () => {
                    mapCanvas.removeAttribute('aria-invalid');
                    clearErrors(form);
                },
            });
            if (step === 1 && modal.open) location.show();
        }).catch(() => {
            form.querySelector('[data-map-status]').textContent = 'Peta belum dapat disiapkan. Muat ulang halaman untuk mencoba lagi.';
        });
    }

    function showWhatsappStatus(status) {
        whatsappStatus.dataset.status = status ?? '';
        whatsappStatus.textContent = whatsappMessages[status] ?? '';
    }

    function resetWhatsapp() {
        whatsappController?.abort();
        checkedNumber = null;
        showWhatsappStatus(null);
    }

    async function previewWhatsapp() {
        const number = whatsappPayload(whatsappInput.value);
        if (!number || whatsappInput.validity.customError || number === checkedNumber
            || !modal.open || editId !== null || step !== 2 || saving) return;

        checkedNumber = number;
        const controller = new AbortController();
        whatsappController = controller;
        showWhatsappStatus('pending');
        let status = 'unchecked';
        try {
            const { data } = await api('/umkms/whatsapp-check', {
                method: 'POST',
                body: { whatsapp_number: number },
                signal: AbortSignal.any([controller.signal, AbortSignal.timeout(4000)]),
            });
            if (data?.normalized_number === number && ['registered', 'unregistered'].includes(data.status)) status = data.status;
        } catch {
            // The optional preview remains unchecked when the lookup fails.
        }
        if (!controller.signal.aborted && !signal.aborted && modal.open && whatsappPayload(whatsappInput.value) === number) {
            showWhatsappStatus(status);
        }
    }

    function openForm(opener, umkm = null) {
        form.reset();
        clearErrors(form);
        resetWhatsapp();
        whatsappInput.setCustomValidity('');
        location?.reset();
        editId = umkm?.id ?? null;
        omzetValue = umkm?.omzet ?? null;
        if (umkm) {
            form.elements.name.value = umkm.name;
            form.elements.province.value = umkm.province;
            form.elements.sector_id.value = String(umkm.sector.id);
            omzetInput.value = formatNumber(omzetValue);
        }
        selects.forEach(({ sync }) => sync());
        initialValues = formValues();
        showStep(0);
        openDialog(modal, opener);
        form.elements.name.focus();
    }

    async function openEdit(id, opener) {
        if (addButton.disabled) { toast('Pilihan sektor dan provinsi belum dimuat. Coba lagi.', 'error'); return; }
        editController?.abort();
        const controller = new AbortController();
        editController = controller;
        opener.disabled = true;
        opener.setAttribute('aria-busy', 'true');
        try {
            const { data } = await api(`/umkms/${id}`, { signal: controller.signal });
            if (controller === editController) openForm(opener, data);
        } catch (error) {
            if (!signal.aborted && error.name !== 'AbortError') toast(error.message, 'error');
        } finally {
            opener.disabled = false;
            opener.removeAttribute('aria-busy');
            if (!modal.open && document.activeElement === document.body) opener.focus();
        }
    }

    async function requestClose() {
        if (saving || confirming) return;
        if (hasUnsavedEdits()) {
            confirming = true;
            const accepted = await confirmAction(discardConfirmation);
            confirming = false;
            if (!accepted || signal.aborted) return;
        }
        modal.close();
    }

    function showErrors(error) {
        if (editId === null && error.status === 422) {
            const steps = Object.keys(error.errors).flatMap((name) => {
                const field = form.elements.namedItem(name)?.closest('[data-step]');
                return field ? [Number(field.dataset.step)] : [];
            });
            if (steps.length && Math.min(...steps) !== step) showStep(Math.min(...steps));
        }
        renderErrors(form, error);
        if (step === 1 && (error.errors?.latitude || error.errors?.longitude)) {
            mapCanvas.setAttribute('aria-invalid', 'true');
            mapCanvas.focus();
        } else if (!form.querySelector('[aria-invalid]:not([hidden] *)')) form.querySelector('.form-message').focus();
    }

    function setSaving(busy) {
        saving = busy;
        fieldset.disabled = busy;
        backButton.disabled = busy;
        closeButtons.forEach((button) => { button.disabled = busy; });
        setBusy(saveButton, busy);
    }

    function clearEditedField({ target }) {
        if (!target.matches('[name][aria-invalid="true"]')) return;
        target.removeAttribute('aria-invalid');
        form.querySelector(`[data-error="${target.name}"]`).textContent = '';
        if (!form.querySelector('[name][aria-invalid="true"]')) clearErrors(form);
    }

    bindDialog(modal, requestClose);
    modal.addEventListener('cancel', (event) => {
        event.preventDefault();
        void requestClose();
    });
    modal.addEventListener('close', () => {
        if (!modal.open) {
            resetWhatsapp();
            location?.reset();
        }
    });
    addButton.addEventListener('click', () => {
        editController?.abort();
        openForm(addButton);
    });
    backButton.addEventListener('click', () => {
        clearErrors(form);
        showStep(step - 1, true);
    });
    whatsappInput.addEventListener('input', () => {
        const raw = whatsappInput.value;
        const normalized = localWhatsappDigits(raw);
        const value = normalized ?? localWhatsappDigits(raw.replace(/\D/g, ''));
        if (value !== raw) {
            const fromEnd = raw.slice(whatsappInput.selectionStart ?? raw.length).replace(/\D/g, '').length;
            const caret = Math.max(0, value.length - fromEnd);
            whatsappInput.value = value;
            whatsappInput.setSelectionRange(caret, caret);
        }
        whatsappInput.setCustomValidity(normalized === null ? whatsappError : '');
        resetWhatsapp();
    });
    whatsappInput.addEventListener('paste', (event) => {
        const pasted = event.clipboardData?.getData('text');
        if (!pasted) return;
        event.preventDefault();
        whatsappInput.setRangeText(localWhatsappDigits(pasted) ?? pasted, whatsappInput.selectionStart, whatsappInput.selectionEnd, 'end');
        whatsappInput.dispatchEvent(new Event('input', { bubbles: true }));
    });
    whatsappInput.addEventListener('blur', previewWhatsapp);
    form.addEventListener('input', clearEditedField);
    form.addEventListener('change', clearEditedField);
    omzetInput.addEventListener('input', () => {
        const raw = omzetInput.value.trim();
        omzetValue = raw === '' ? null : (/^[0-9]+(?:\.[0-9]+)*$/.test(raw) ? Number(raw.replaceAll('.', '')) : NaN);
        if (omzetValue === null) omzetInput.value = '';
        else if (Number.isSafeInteger(omzetValue)) omzetInput.value = formatNumber(omzetValue);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving) return;
        clearErrors(form);
        const creating = editId === null;
        const errors = {};
        for (const field of formFields) {
            if (field.closest('[data-step]').hidden) continue;
            const message = fieldError(field, field.value.trim());
            if (message) errors[field.name] = [message];
        }
        if (!omzetInput.closest('[data-step]').hidden && !Number.isSafeInteger(omzetValue)) {
            errors.omzet = [omzetValue === null
                ? 'Masukkan omzet UMKM.'
                : 'Masukkan omzet dalam bilangan bulat positif atau nol, maksimal Rp9.007.199.254.740.991.'];
        }
        if (creating && step === 1) {
            const { latitude, longitude } = form.elements;
            if (!location?.hasPoint() || !latitude.value || !longitude.value
                || Math.abs(Number(latitude.value)) > 90 || Math.abs(Number(longitude.value)) > 180) {
                errors.latitude = ['Pilih titik lokasi usaha pada peta terlebih dahulu.'];
            }
        }
        if (Object.keys(errors).length) {
            showErrors(new ApiError('Periksa kembali data Anda.', 422, errors));
            return;
        }
        if (creating && step < 2) {
            showStep(step + 1, true);
            return;
        }

        const body = Object.fromEntries(new FormData(form));
        if (!creating) {
            for (const field of formFields) {
                if (field.closest('[data-step]').hidden) delete body[field.name];
            }
        }
        body.omzet = omzetValue;
        if (creating) body.whatsapp_number = whatsappPayload(whatsappInput.value);
        setSaving(true);
        let failure;
        try {
            await api(creating ? '/umkms' : `/umkms/${editId}`, {
                method: creating ? 'POST' : 'PATCH', body, expectedStatus: creating ? 201 : 200,
            });
        } catch (error) {
            failure = error;
        } finally {
            setSaving(false);
        }
        if (signal.aborted || failure?.name === 'AbortError') return;
        if (failure) {
            showErrors(failure);
            return;
        }
        modal.close();
        toast(creating ? 'Data UMKM berhasil ditambahkan.' : 'Data UMKM berhasil diperbarui.');
        void onSaved();
    });

    document.addEventListener('joyumkm:before-route-change', (event) => {
        if (saving || confirming) { event.preventDefault(); return; }
        if (!hasUnsavedEdits()) return;
        event.preventDefault();
        event.detail.confirmation = discardConfirmation;
    }, { signal });
    document.addEventListener('click', (event) => {
        const logout = event.target.closest('[data-logout]');
        if (!logout || !modal.open || (!saving && !hasUnsavedEdits())) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void requestClose().then(() => { if (!modal.open && !signal.aborted) logout.click(); });
    }, { capture: true, signal });
    window.addEventListener('beforeunload', (event) => {
        if (hasUnsavedEdits()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }, { signal });

    return { openEdit };
}
