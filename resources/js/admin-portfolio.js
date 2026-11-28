import { getAnalystFilter, setAnalystFilter } from './role';
import { bindDialog, element, formatNumber, openDialog, setBusy, showError, toast } from './ui';

/** Filter Analyst (Superadmin): menyimpan pilihan lalu memuat ulang halaman agar daftar, hitungan, dan grafik konsisten. */
export function mountOwnerFilter(container, api, { compact = false } = {}) {
    const select = element('select', { id: `owner-filter-${compact ? 'bar' : 'panel'}`, class: 'control', 'aria-label': 'Filter Analyst' }, [
        element('option', { value: '', text: 'Semua pemilik (global)' }),
    ]);
    const clear = element('button', { type: 'button', class: 'button button-outline btn-sm', text: 'Hapus filter', hidden: getAnalystFilter() === null });
    const apply = (value) => {
        setAnalystFilter(value ? Number(value) : null);
        location.reload();
    };
    select.addEventListener('change', () => apply(select.value));
    clear.addEventListener('click', () => apply(''));
    container.append(element('div', { class: 'hstack wrap', style: 'gap:12px;align-items:center' }, [
        element('label', { for: select.id, class: compact ? 'sr-only' : 'muted small', text: 'Tampilkan portofolio' }), select, clear,
    ]));
    api('/admin/analysts?per_page=100').then(({ data }) => {
        for (const analyst of data) {
            select.append(element('option', { value: analyst.id, text: `${analyst.name}${analyst.is_active ? '' : ' (nonaktif)'} · ${formatNumber(analyst.umkms_count)} UMKM` }));
        }
        select.value = String(getAnalystFilter() ?? '');
    }).catch((error) => { if (error.name !== 'AbortError') select.disabled = true; });
}

/** Panel portofolio global di Beranda Superadmin. Semua angka berasal dari /umkms/summary (portfolio). */
export function mountGlobalPanel(anchor, api, signal) {
    const body = element('div', { 'aria-live': 'polite', 'aria-busy': 'true' });
    const filterHost = element('div');
    const panel = element('section', { class: 'section-block', 'aria-labelledby': 'global-portfolio-title' }, [
        element('div', { class: 'section-top summary-heading' }, [
            element('div', {}, [
                element('h2', { id: 'global-portfolio-title', class: 'section-title', text: 'Portofolio global' }),
                element('p', { class: 'section-desc muted', text: getAnalystFilter() ? 'Menampilkan satu Analyst. Hapus filter untuk kembali ke seluruh pemilik.' : 'Seluruh UMKM dari Analyst dan pengguna lain, termasuk dataset sintetis.' }),
            ]),
            filterHost,
        ]),
        body,
    ]);
    anchor.after(panel);
    mountOwnerFilter(filterHost, api);

    const metric = (label, value, foot) => element('div', { class: 'card metric panel' }, [
        element('div', { class: 'metric-label', text: label }),
        element('div', { class: 'metric-value' }, element('span', { text: value })),
        element('div', { class: 'metric-foot', text: foot }),
    ]);

    async function load() {
        body.setAttribute('aria-busy', 'true');
        try {
            const { data } = await api('/umkms/summary');
            const portfolio = data.portfolio;
            const roles = portfolio.umkms_by_owner_role;
            body.replaceChildren(
                element('div', { class: 'grid-4 summary-cards' }, [
                    metric('Total UMKM', formatNumber(data.total_umkms), getAnalystFilter() ? 'Sesuai filter Analyst' : 'Seluruh pemilik'),
                    metric('Analyst', `${formatNumber(portfolio.active_analysts)} / ${formatNumber(portfolio.total_analysts)}`, 'Aktif dari seluruh akun Analyst'),
                    metric('Evaluasi Tiga Dimensi', formatNumber(portfolio.three_dimension_evaluations), 'Seluruh riwayat evaluasi (bukan hanya terbaru)'),
                    metric('Evaluasi Enam Faktor', formatNumber(portfolio.six_factor_evaluations), `${formatNumber(portfolio.six_factor_final_evaluations)} berstatus Final`),
                ]),
                element('p', { class: 'muted small', style: 'margin:12px 0 8px', text: `Pemilik UMKM (seluruh sistem): Analyst ${formatNumber(roles.analyst ?? 0)} · Pengguna ${formatNumber(roles.user ?? 0)} · Superadmin ${formatNumber(roles.superadmin ?? 0)}` }),
                portfolio.per_analyst.length ? element('div', { class: 'data-table-wrap table-scroll', role: 'region', 'aria-label': 'UMKM per Analyst', tabindex: '0' }, [
                    element('table', { class: 'data-table' }, [
                        element('thead', {}, [element('tr', {}, ['ANALYST', 'STATUS', 'UMKM', 'KOHORT', 'NON-KOHORT'].map((text) => element('th', { scope: 'col', text })))]),
                        element('tbody', {}, portfolio.per_analyst.map((analyst) => element('tr', {}, [
                            element('td', {}, element('strong', { text: analyst.name })),
                            element('td', {}, element('span', { class: `pill ${analyst.is_active ? 'pill-green' : 'pill-muted'}`, text: analyst.is_active ? 'Aktif' : 'Nonaktif' })),
                            element('td', { class: 'num', text: formatNumber(analyst.umkms_count) }),
                            element('td', { class: 'num muted', text: formatNumber(analyst.cohort_umkms_count) }),
                            element('td', { class: 'num muted', text: formatNumber(analyst.non_cohort_umkms_count) }),
                        ]))),
                    ]),
                ]) : null,
            );
        } catch (error) {
            if (!signal.aborted && error.name !== 'AbortError') showError(body, error, load);
        } finally {
            body.setAttribute('aria-busy', 'false');
        }
    }
    load();
}

/** Dialog transfer kepemilikan satu UMKM. Tidak ada klaim sukses sebelum server membalas. */
export function openTransferDialog(umkm, api, opener, onDone) {
    const hints = {
        stale_owner: 'Data sudah berubah. Tutup dialog dan muat ulang daftar UMKM.',
        name_collision: 'Analyst tujuan sudah memiliki UMKM bernama sama. Ubah nama salah satu UMKM lebih dulu; sistem tidak mengganti nama otomatis.',
        target_inactive: 'Pilih Analyst tujuan yang aktif.',
        consent_active: 'Minta pemilik saat ini mencabut persetujuan notifikasi lewat menu Ubah UMKM, lalu coba lagi.',
        six_factor_draft: 'Finalkan atau batalkan draf Enam Faktor lebih dulu.',
        three_dimension_draft: 'Selesaikan penilaian Tiga Dimensi yang masih draf lebih dulu.',
        report_in_flight: 'Tunggu laporan intelligence selesai diproses, lalu coba lagi.',
        delivery_in_flight: 'Tunggu artefak dan pengiriman laporan selesai, lalu coba lagi.',
    };
    const destination = element('select', { id: 'transfer-to', class: 'control', required: true }, [element('option', { value: '', text: 'Memuat Analyst…' })]);
    const reason = element('textarea', { id: 'transfer-reason', class: 'control', rows: 3, maxlength: 255, required: true, placeholder: 'Contoh: Penugasan ulang wilayah binaan' });
    const error = element('div', { class: 'form-message', role: 'alert', tabindex: '-1', hidden: true });
    const submit = element('button', { type: 'button', class: 'btn btn-primary button button-primary', text: 'Pindahkan UMKM', disabled: true });
    const dialog = element('dialog', { class: 'modal dialog', 'aria-labelledby': 'transfer-dialog-title' }, [
        element('div', { class: 'dialog-head modal-heading' }, [
            element('h2', { id: 'transfer-dialog-title', class: 'section-title', text: 'Pindahkan kepemilikan UMKM' }),
            element('button', { type: 'button', class: 'icon-button', 'data-close-dialog': true, 'aria-label': 'Tutup dialog', text: '×' }),
        ]),
        element('div', { class: 'dialog-body' }, [
            error,
            element('p', {}, [element('strong', { text: umkm.name }), ` · pemilik saat ini: ${umkm.owner.name}`]),
            element('div', { class: 'field form-field full' }, [element('label', { for: 'transfer-to', text: 'Analyst tujuan (aktif)' }), destination]),
            element('div', { class: 'field form-field full' }, [element('label', { for: 'transfer-reason', text: 'Alasan pemindahan' }), reason]),
            element('p', { class: 'muted tiny', style: 'margin-top:8px', text: 'ID UMKM, riwayat evaluasi, file laporan, dan business_email tetap. Pemindahan tidak mengirim pesan atau laporan apa pun, dan tercatat permanen di Riwayat Transfer.' }),
        ]),
        element('div', { class: 'dialog-footer modal-actions' }, [
            element('button', { type: 'button', class: 'btn btn-light button button-outline', 'data-close-dialog': true, text: 'Batal' }), submit,
        ]),
    ]);
    document.body.append(dialog);
    bindDialog(dialog);
    dialog.addEventListener('close', () => dialog.remove());
    openDialog(dialog, opener);

    api('/admin/analysts?status=active&per_page=100').then(({ data }) => {
        const options = data.filter((analyst) => analyst.id !== umkm.owner.id);
        destination.replaceChildren(
            element('option', { value: '', text: options.length ? 'Pilih Analyst tujuan' : 'Tidak ada Analyst aktif lain' }),
            ...options.map((analyst) => element('option', { value: analyst.id, text: analyst.name })),
        );
        submit.disabled = false;
    }).catch((failure) => {
        destination.replaceChildren(element('option', { value: '', text: 'Gagal memuat Analyst' }));
        error.textContent = failure.message;
        error.hidden = false;
    });

    submit.addEventListener('click', async () => {
        error.hidden = true;
        if (!destination.value || reason.value.trim().length < 5) {
            error.textContent = 'Pilih Analyst tujuan dan isi alasan minimal 5 karakter.';
            error.hidden = false;
            return;
        }
        setBusy(submit, true, 'Memindahkan…');
        try {
            await api(`/admin/umkms/${umkm.id}/transfer`, {
                method: 'POST',
                body: { expected_owner_id: umkm.owner.id, to_user_id: Number(destination.value), reason: reason.value.trim() },
            });
            dialog.close();
            toast('Kepemilikan UMKM dipindahkan dan tercatat di riwayat.');
            await onDone();
        } catch (failure) {
            if (failure.name === 'AbortError') return;
            error.textContent = [failure.message, hints[failure.code]].filter(Boolean).join(' ');
            error.hidden = false;
            error.focus();
            setBusy(submit, false);
        }
    });
}

