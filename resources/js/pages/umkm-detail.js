import { pageApi } from '../api';
import { createSelectControl } from '../select-control';
import { createInlineEditor, discardConfirmation } from '../umkm-detail-editor';
import { withListContext } from '../umkm-navigation';
import { bindWhatsappInput, whatsappPayload } from '../whatsapp-number';
import { clearErrors, element, formatNumber, formatRupiahInput, formatScore, parseRupiah, setBusy, showError, toast } from '../ui';

const missing = 'Belum diisi';
const omzetLabels = { monthly: 'Omzet Bulanan', yearly: 'Omzet Tahunan' };
const unknownPeriodNote = 'Periode belum diketahui';
const assessmentLabels = { draft: 'Draft tersimpan', final: 'Final', none: 'Belum ada kuesioner tersimpan' };
const whatsappLabels = {
    registered: 'Terdaftar di WhatsApp.',
    unregistered: 'Tidak terdaftar di WhatsApp. Periksa kembali nomor ini; data tetap dapat disimpan.',
    unchecked: 'Belum diperiksa. Pemeriksaan gagal atau tidak tersedia; data tetap dapat disimpan.',
};
const changedWhatsappLabel = 'Nomor berubah. Belum diperiksa sampai data disimpan.';
const omzetRangeError = 'Masukkan omzet dalam bilangan bulat positif atau nol, maksimal Rp9.007.199.254.740.991.';
const businessCapitalRangeError = 'Masukkan modal usaha dalam bilangan bulat positif atau nol, maksimal Rp9.007.199.254.740.991.';

const textOrMissing = (value) => (value === null || value === undefined || value === '' ? missing : String(value));
const nullableText = (value) => {
    const trimmed = value.trim();
    return trimmed === '' ? null : trimmed;
};
const nullableInteger = (value) => (value.trim() === '' ? null : Number(value));

function setFields(scope, values) {
    for (const [key, value] of Object.entries(values)) {
        scope.querySelector(`[data-field="${key}"]`).textContent = value;
    }
}

export async function init(signal) {
    const api = pageApi(signal);
    const root = document.getElementById('umkm-detail');
    const state = document.getElementById('umkm-detail-state');
    const content = document.getElementById('umkm-detail-content');
    const path = '/umkms/' + root.dataset.umkm;
    state.setAttribute('aria-busy', 'true');
    let data;
    try {
        ({ data } = await api(path));
    } catch (error) {
        if (!signal.aborted && error.name !== 'AbortError' && error.status !== 401) {
            showError(state, {
                status: error.status,
                message: error.status === 404 ? 'Data tidak ditemukan.' : 'Detail UMKM belum dapat dimuat.',
            }, error.status === 404 ? undefined : () => init(signal));
        }
        return;
    }

    const parent = root.querySelector('[data-context-umkm]');
    if (parent) parent.textContent = data.name;
    if (!content) {
        const title = document.getElementById('detail-title');
        if (title) title.textContent = data.name;
        state.hidden = true;
        state.setAttribute('aria-busy', 'false');
        const { initEvaluation } = await import('./six-factor-evaluation');
        if (!signal.aborted) initEvaluation(path + '/six-factor-evaluations', signal);
        return;
    }

    let options;
    try {
        ({ data: options } = await api('/umkms/options'));
    } catch (error) {
        if (!signal.aborted && error.name !== 'AbortError') {
            showError(state, { message: 'Pilihan sektor dan provinsi belum dapat dimuat.' }, () => init(signal));
        }
        return;
    }
    if (signal.aborted) return;

    state.hidden = true;
    state.setAttribute('aria-busy', 'false');
    content.hidden = false;
    content.querySelectorAll('[data-carry-list]').forEach((link) => { link.href = withListContext(link.href); });
    createDetailPage({ root, content, api, signal, path, umkm: data, options }).start();
}

function createDetailPage({ root, content, api, signal, path, umkm, options }) {
    let current = umkm;
    let activeTab = 'profil';
    let assessmentLoaded = false;
    let destroyPreview = null;
    let locationPicker = null;
    let locationLoading = null;

    const byId = (id) => document.getElementById(id);
    const tabs = [...content.querySelectorAll('[role="tab"]')];
    const panels = Object.fromEntries(tabs.map((tab) => [tab.dataset.tab, byId('panel-' + tab.dataset.tab)]));
    const forms = { profil: byId('form-profil'), lokasi: byId('form-lokasi'), kontak: byId('form-kontak') };
    const views = { profil: byId('view-profil'), lokasi: byId('view-lokasi'), kontak: byId('view-kontak') };
    const editButton = (tab) => content.querySelector(`[data-edit="${tab}"]`);
    const mapCanvas = byId('detail-location-map');
    const recheckButton = byId('detail-whatsapp-recheck');
    const viewWhatsappStatus = byId('detail-whatsapp-status');
    const formWhatsappStatus = forms.kontak.querySelector('#umkm-whatsapp-status');

    // Pilihan sektor dan provinsi diisi sebelum select kustom dibuat, seperti pada wizard.
    const appendOptions = (select, values, getValue, getLabel) => {
        select.replaceChildren(select.options[0], ...values.map((value) => element('option', { value: getValue(value), text: getLabel(value) })));
    };
    appendOptions(forms.profil.elements.sector_id, options.sectors, (sector) => sector.id, (sector) => sector.name);
    appendOptions(forms.lokasi.elements.province, options.provinces, (province) => province, (province) => province);
    const controls = {
        sector: createSelectControl(forms.profil.elements.sector_id, signal),
        period: createSelectControl(forms.profil.elements.omzet_period, signal),
        province: createSelectControl(forms.lokasi.elements.province, signal),
    };

    const patchUmkm = async (body) => {
        const { data } = await api(path, { method: 'PATCH', body, expectedStatus: 200 });
        return data;
    };
    const recheckWhatsapp = async (number) => {
        const { data } = await api(path + '/whatsapp-check', { method: 'POST', body: { whatsapp_number: number }, expectedStatus: 200 });
        return data;
    };

    function applyUmkm(next) {
        current = next;
        renderAll();
    }

    async function reloadDetail() {
        try {
            const { data } = await api(path);
            applyUmkm(data);
        } catch (error) {
            if (!signal.aborted && error.name !== 'AbortError') toast(error.message, 'error');
        }
    }

    async function saveContact(body) {
        const previousNumber = current.whatsapp_number;
        let saved = await patchUmkm(body);
        if (saved.whatsapp_number !== previousNumber) {
            try {
                saved = await recheckWhatsapp(saved.whatsapp_number);
            } catch (error) {
                if (signal.aborted || error.name === 'AbortError') throw error;
                toast('Pemeriksaan WhatsApp belum berhasil. Data kontak tetap tersimpan.', 'warning');
            }
        }
        return saved;
    }

    const editors = {
        profil: createInlineEditor({
            form: forms.profil,
            view: views.profil,
            editButton: editButton('profil'),
            signal,
            prefill: (form) => {
                form.elements.name.value = current.name;
                form.elements.omzet.value = formatNumber(current.omzet);
                form.elements.business_capital.value = current.business_capital === null ? '' : formatNumber(current.business_capital);
                form.elements.omzet_period.value = current.omzet_period ?? '';
                form.elements.year_founded.value = current.year_founded ?? '';
                form.elements.employee_count.value = current.employee_count ?? '';
                form.elements.nib.value = current.nib ?? '';
                form.elements.description.value = current.description ?? '';
                form.elements.sector_id.value = String(current.sector.id);
                controls.sector.sync();
                controls.period.sync();
            },
            validate: (form) => {
                const errors = {};
                const omzet = parseRupiah(form.elements.omzet.value);
                if (omzet === null) errors.omzet = ['Masukkan omzet UMKM.'];
                else if (!Number.isSafeInteger(omzet)) errors.omzet = [omzetRangeError];
                // Modal usaha boleh dikosongkan untuk data lama; kosong berarti belum diisi, bukan 0.
                const businessCapital = parseRupiah(form.elements.business_capital.value);
                if (businessCapital !== null && !Number.isSafeInteger(businessCapital)) {
                    errors.business_capital = [businessCapitalRangeError];
                }
                return errors;
            },
            toPayload: (form) => ({
                name: form.elements.name.value.trim(),
                sector_id: Number(form.elements.sector_id.value),
                omzet: parseRupiah(form.elements.omzet.value),
                business_capital: parseRupiah(form.elements.business_capital.value),
                omzet_period: form.elements.omzet_period.value,
                year_founded: nullableInteger(form.elements.year_founded.value),
                employee_count: nullableInteger(form.elements.employee_count.value),
                nib: nullableText(form.elements.nib.value),
                description: nullableText(form.elements.description.value),
            }),
            submit: patchUmkm,
            onSaved: (saved) => {
                applyUmkm(saved);
                toast('Profil usaha berhasil diperbarui.');
            },
        }),
        lokasi: createInlineEditor({
            form: forms.lokasi,
            view: views.lokasi,
            editButton: editButton('lokasi'),
            signal,
            prefill: (form) => {
                form.elements.province.value = current.province ?? '';
                form.elements.city.value = current.city ?? '';
                form.elements.address.value = current.address ?? '';
                form.elements.district.value = current.district ?? '';
                form.elements.subdistrict.value = current.subdistrict ?? '';
                form.elements.postal_code.value = current.postal_code ?? '';
                form.elements.latitude.value = current.latitude ?? '';
                form.elements.longitude.value = current.longitude ?? '';
                controls.province.sync();
            },
            validate: (form) => {
                const { latitude, longitude } = form.elements;
                if (!latitude.value || !longitude.value) {
                    return { latitude: ['Pilih titik lokasi usaha pada peta terlebih dahulu.'] };
                }
                if (Math.abs(Number(latitude.value)) > 90 || Math.abs(Number(longitude.value)) > 180) {
                    return { latitude: ['Koordinat berada di luar rentang yang valid.'] };
                }
                return {};
            },
            toPayload: (form) => ({
                province: form.elements.province.value,
                city: form.elements.city.value.trim(),
                address: form.elements.address.value.trim(),
                district: nullableText(form.elements.district.value),
                subdistrict: nullableText(form.elements.subdistrict.value),
                postal_code: nullableText(form.elements.postal_code.value),
                latitude: Number(form.elements.latitude.value),
                longitude: Number(form.elements.longitude.value),
            }),
            submit: patchUmkm,
            onStart: () => { void ensureLocationPicker().then((picker) => picker?.show()); },
            onEnd: () => {
                locationPicker?.reset();
                void refreshLocationPreview();
            },
            onSaved: (saved) => {
                applyUmkm(saved);
                toast('Lokasi usaha berhasil diperbarui.');
            },
        }),
        kontak: createInlineEditor({
            form: forms.kontak,
            view: views.kontak,
            editButton: editButton('kontak'),
            signal,
            prefill: (form) => {
                form.elements.owner_name.value = current.owner_name ?? '';
                form.elements.business_email.value = current.business_email ?? '';
                form.elements.whatsapp_number.value = (current.whatsapp_number ?? '').replace(/^62/, '');
                form.elements.pic_name.value = current.pic_name ?? '';
                form.elements.website_url.value = current.website_url ?? '';
                form.elements.social_media_url.value = current.social_media_url ?? '';
                renderWhatsappFormStatus();
            },
            toPayload: (form) => ({
                owner_name: form.elements.owner_name.value.trim(),
                business_email: form.elements.business_email.value.trim(),
                whatsapp_number: whatsappPayload(form.elements.whatsapp_number.value),
                pic_name: nullableText(form.elements.pic_name.value),
                website_url: nullableText(form.elements.website_url.value),
                social_media_url: nullableText(form.elements.social_media_url.value),
            }),
            submit: saveContact,
            onEnd: () => renderWhatsappFormStatus(),
            onSaved: (saved) => {
                applyUmkm(saved);
                toast('Data kontak berhasil diperbarui.');
            },
        }),
    };

    function ensureLocationPicker() {
        locationLoading ??= import('../umkm-location').then(({ bindUmkmLocation }) => {
            if (signal.aborted) return null;
            locationPicker = bindUmkmLocation({
                form: forms.lokasi,
                api,
                signal,
                syncProvince: controls.province.sync,
                onPick: () => clearErrors(forms.lokasi),
                preserveAddress: true,
            });
            return locationPicker;
        }).catch(() => {
            forms.lokasi.querySelector('[data-map-status]').textContent = 'Peta belum dapat disiapkan. Muat ulang halaman untuk mencoba lagi.';
            return null;
        });
        return locationLoading;
    }

    async function refreshLocationPreview() {
        destroyPreview?.();
        destroyPreview = null;
        const hasPoint = current.latitude !== null && current.longitude !== null;
        if (activeTab !== 'lokasi' || editors.lokasi.isActive() || !hasPoint) return;
        try {
            const { renderLocationPreview } = await import('../umkm-location');
            if (signal.aborted || activeTab !== 'lokasi' || editors.lokasi.isActive()) return;
            destroyPreview?.();
            destroyPreview = renderLocationPreview(mapCanvas, {
                latitude: Number(current.latitude),
                longitude: Number(current.longitude),
                tileUrl: mapCanvas.dataset.tileUrl,
            });
        } catch {
            mapCanvas.setAttribute('aria-label', 'Peta belum dapat dimuat. Koordinat tetap tersedia pada daftar di bawah.');
        }
    }

    function renderHero() {
        byId('detail-title').textContent = current.name;
        byId('detail-hero-meta').textContent = [current.sector.name, [current.city, current.province].filter(Boolean).join(', ')]
            .filter(Boolean).join(' · ');
        const parent = root.querySelector('[data-context-umkm]');
        if (parent) parent.textContent = current.name;
    }

    function renderProfile() {
        const periodKnown = Object.hasOwn(omzetLabels, current.omzet_period);
        views.profil.querySelector('[data-omzet-label]').textContent = periodKnown ? omzetLabels[current.omzet_period] : 'Omzet';
        setFields(views.profil, {
            name: textOrMissing(current.name),
            sector: textOrMissing(current.sector.name),
            nib: textOrMissing(current.nib),
            description: textOrMissing(current.description),
            business_capital: current.business_capital === null ? missing : `Rp ${formatNumber(current.business_capital)}`,
            omzet: `Rp ${formatNumber(current.omzet)}`,
            year_founded: textOrMissing(current.year_founded),
            employee_count: current.employee_count === null ? missing : `${formatNumber(current.employee_count)} orang`,
        });
        if (!periodKnown) {
            views.profil.querySelector('[data-field="omzet"]').append(element('span', { class: 'muted', text: ` · ${unknownPeriodNote}` }));
        }
    }

    function renderLocation() {
        const hasPoint = current.latitude !== null && current.longitude !== null;
        setFields(views.lokasi, {
            province: textOrMissing(current.province),
            city: textOrMissing(current.city),
            district: textOrMissing(current.district),
            subdistrict: textOrMissing(current.subdistrict),
            postal_code: textOrMissing(current.postal_code),
            address: textOrMissing(current.address),
            coordinates: hasPoint ? `${current.latitude}, ${current.longitude}` : missing,
        });
        byId('detail-location-frame').hidden = !hasPoint;
        byId('detail-location-empty').hidden = hasPoint;
    }

    function renderWhatsappViewStatus() {
        const status = current.whatsapp_registration_status ?? 'unchecked';
        viewWhatsappStatus.dataset.status = status;
        viewWhatsappStatus.textContent = current.whatsapp_number ? whatsappLabels[status] : '';
        recheckButton.hidden = !(current.whatsapp_number && status === 'unchecked');
    }

    function renderWhatsappFormStatus() {
        const status = current.whatsapp_registration_status ?? 'unchecked';
        formWhatsappStatus.dataset.status = status;
        formWhatsappStatus.textContent = current.whatsapp_number ? whatsappLabels[status] : '';
    }

    function renderContact() {
        setFields(views.kontak, {
            owner_name: textOrMissing(current.owner_name),
            business_email: textOrMissing(current.business_email),
            whatsapp: current.whatsapp_number ? `+${current.whatsapp_number}` : missing,
            pic_name: textOrMissing(current.pic_name),
            website_url: textOrMissing(current.website_url),
            social_media_url: textOrMissing(current.social_media_url),
        });
        renderWhatsappViewStatus();
    }

    function renderAssessment3d() {
        byId('assessment-3d-status').textContent = assessmentLabels[current.evaluation_status] ?? assessmentLabels.none;
        byId('assessment-3d-score').textContent = current.latest_score === null
            ? 'Skor belum tersedia' : `${formatScore(current.latest_score)}/100 · ${current.category}`;
        byId('assessment-3d-cta').textContent = current.evaluation_status === 'draft' ? 'Lanjutkan Kuesioner' : 'Isi Kuesioner';
    }

    function renderSixFactor(draft, latest) {
        const status = byId('assessment-6f-status');
        const cta = byId('assessment-6f-cta');
        const primary = Boolean(draft) || !latest;
        if (draft) {
            status.textContent = latest
                ? 'Draft aktif tersimpan. Hasil Final terbaru tetap tersedia.'
                : 'Draft aktif tersimpan. Lanjutkan untuk menyelesaikan penilaian.';
        } else {
            status.textContent = latest ? 'Hasil Final terbaru tersedia.' : 'Belum ada penilaian final.';
        }
        byId('assessment-6f-result').textContent = latest
            ? `${formatNumber(latest.total_score ?? 0)}/30 · ${latest.category ?? ''}`.trim() : '';
        cta.textContent = draft ? 'Lanjutkan Draft' : (latest ? 'Lihat Hasil' : 'Mulai Penilaian');
        cta.classList.toggle('button-primary', primary);
        cta.classList.toggle('button-outline', !primary);
    }

    async function loadSixFactor() {
        const body = byId('assessment-6f-body');
        body.setAttribute('aria-busy', 'true');
        body.querySelector('[data-retry]')?.remove();
        try {
            const [draft, latest] = await Promise.all([
                api(path + '/six-factor-evaluations/draft'),
                api(path + '/six-factor-evaluations/latest'),
            ]);
            assessmentLoaded = true;
            renderSixFactor(draft.data, latest.data);
        } catch (error) {
            if (signal.aborted || error.name === 'AbortError') return;
            byId('assessment-6f-status').textContent = 'Penilaian enam faktor belum dapat dimuat.';
            byId('assessment-6f-result').textContent = '';
            body.append(element('button', {
                type: 'button', class: 'button button-outline', 'data-retry': true, text: 'Coba lagi', onclick: () => void loadSixFactor(),
            }));
        } finally {
            body.setAttribute('aria-busy', 'false');
        }
    }

    function renderAll() {
        renderHero();
        renderProfile();
        renderLocation();
        renderContact();
        renderAssessment3d();
        void refreshLocationPreview();
    }

    function showTab(name) {
        activeTab = name;
        for (const tab of tabs) {
            const selected = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            panels[tab.dataset.tab].hidden = !selected;
        }
        if (name === 'lokasi') void refreshLocationPreview();
        if (name === 'penilaian' && !assessmentLoaded) void loadSixFactor();
    }

    async function selectTab(name) {
        if (name === activeTab) return;
        const leaving = editors[activeTab] ? await editors[activeTab].leave() : true;
        if (!leaving || signal.aborted) return;
        showTab(name);
    }

    function bindTabs() {
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => void selectTab(tab.dataset.tab), { signal });
            tab.addEventListener('keydown', (event) => {
                const next = { ArrowLeft: index - 1, ArrowRight: index + 1, Home: 0, End: tabs.length - 1 }[event.key];
                if (next === undefined) return;
                event.preventDefault();
                const target = tabs[(next + tabs.length) % tabs.length];
                target.focus();
                void selectTab(target.dataset.tab);
            }, { signal });
        });
    }

    function bindGuards() {
        const hasUnsavedEdits = () => Object.values(editors).some((editor) => editor.isDirty());
        const isSaving = () => Object.values(editors).some((editor) => editor.isSaving());
        document.addEventListener('joyumkm:before-route-change', (event) => {
            if (isSaving()) { event.preventDefault(); return; }
            if (!hasUnsavedEdits()) return;
            event.preventDefault();
            event.detail.confirmation = discardConfirmation;
        }, { signal });
        window.addEventListener('beforeunload', (event) => {
            if (hasUnsavedEdits()) {
                event.preventDefault();
                event.returnValue = '';
            }
        }, { signal });
    }

    function bindWhatsapp() {
        const input = forms.kontak.elements.whatsapp_number;
        bindWhatsappInput(input, () => {
            if (whatsappPayload(input.value) === current.whatsapp_number) {
                renderWhatsappFormStatus();
                return;
            }
            formWhatsappStatus.dataset.status = 'unchecked';
            formWhatsappStatus.textContent = changedWhatsappLabel;
        });
        recheckButton.addEventListener('click', async () => {
            setBusy(recheckButton, true, 'Memeriksa…');
            try {
                applyUmkm(await recheckWhatsapp(current.whatsapp_number));
                toast('Status WhatsApp diperbarui.');
            } catch (error) {
                if (signal.aborted || error.name === 'AbortError') return;
                if (error.status === 409) await reloadDetail();
                toast(error.message, 'error');
            } finally {
                setBusy(recheckButton, false);
            }
        }, { signal });
    }

    function bindRupiahInputs() {
        for (const name of ['omzet', 'business_capital']) {
            forms.profil.elements[name].addEventListener('input', (event) => {
                event.target.value = formatRupiahInput(event.target.value);
            }, { signal });
        }
    }

    return {
        start() {
            bindTabs();
            bindGuards();
            bindWhatsapp();
            bindRupiahInputs();
            renderAll();
            showTab('profil');
        },
    };
}
