import ApexCharts from 'apexcharts';
import { pageApi, getToken } from '../api';
import { chartHost, chartOptions } from '../charts';
import { createSixFactorDraft, sixFactors } from '../six-factor-draft';
import { bindDialog, confirmAction, element, formatNumber, openDialog, showError, skeleton } from '../ui';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
const emptyFinal = () => element('p', { class: 'muted', text: 'Belum ada penilaian Final' });
const factors = Object.entries(sixFactors);
const shortLabels = ['SD', 'KP', 'LK', 'NO', 'ST', 'KI'];
const scale = [1, 2, 3, 4, 5];
const rangeKeys = ['Home', 'End', 'ArrowLeft', 'ArrowRight', 'ArrowDown', 'ArrowUp'];
const byId = (id) => document.getElementById(id);

function finalDate(result) {
    const time = result.finalized_at && new Date(result.finalized_at);
    if (!time || !Number.isFinite(time.getTime())) return element('span', { text: 'Waktu Final tidak tersedia' });
    return element('time', { datetime: result.finalized_at, text: dateFormat.format(time) });
}

function publicError(error, fallback) {
    if (error.status === 404) return 'Data tidak ditemukan.';
    if (!error.status) return 'Koneksi terganggu. Silakan coba lagi.';
    if (error.status >= 500) return 'Terjadi kesalahan pada server. Silakan coba lagi.';
    return fallback;
}

export function initEvaluation(path, signal) {
    const api = pageApi(signal);
    const token = getToken();
    const form = byId('six-factor-form');
    const fields = byId('draft-scores');
    const progress = byId('draft-progress');
    const progressText = byId('draft-progress-text');
    progress.max = factors.length;
    const loadState = byId('draft-load');
    const status = byId('draft-status');
    const notice = byId('draft-notice');
    const formMessage = form.querySelector('.form-message');
    const finalize = byId('finalize-draft');
    const cancel = byId('cancel-draft');
    const retrySave = byId('retry-save');
    const predict = byId('predict-health');
    const predictionResult = byId('prediction-result');
    const predictionError = byId('prediction-error');
    const confirmation = byId('draft-confirmation');
    const confirm = byId('confirm-draft-action');
    const historical = byId('historical-result');
    const historicalContent = byId('historical-content');
    const charts = new Map();
    let action;
    let target;
    let previousReadState;
    let prediction;

    const active = () => !signal.aborted && !!token && getToken() === token;
    const request = async (suffix, options) => (await api(path + suffix, options)).data;
    const requestLeave = (event) => {
        if (!active() || !draft.snapshot().dirty) return;
        event.preventDefault();
        event.returnValue = true;
    };

    const controls = factors.map(([key, label], index) => {
        const input = element('input', {
            type: 'range', min: scale[0], max: scale.at(-1), step: 1, value: scale[0],
            id: 'score-' + key, name: key, class: 'six-factor-range', 'aria-describedby': 'error-' + key,
        });
        const selected = element('span', { id: 'score-value-' + key, class: 'pill six-factor-value num', 'aria-hidden': true });
        const clear = element('button', {
            type: 'button', class: 'btn button button-ghost six-factor-clear', text: 'Kosongkan',
            'aria-label': 'Kosongkan ' + label,
            onclick: () => { input.focus(); draft.edit(key, null); },
        });
        const error = element('p', { id: 'error-' + key, class: 'field-error' });
        const chooseScore = () => draft.edit(key, input.valueAsNumber);
        let activePointer;
        const releasePointer = (event) => {
            if (event.pointerId === activePointer) activePointer = undefined;
        };
        input.addEventListener('input', chooseScore);
        // The label forwards clicks, but only a pointer gesture on the slider selects its resting value.
        input.addEventListener('pointerdown', (event) => {
            if (event.isPrimary && event.button === 0) activePointer = event.pointerId;
        });
        input.addEventListener('pointerup', (event) => {
            if (event.pointerId !== activePointer) return;
            releasePointer(event);
            if (input.hasAttribute('data-unselected')) chooseScore();
        });
        input.addEventListener('pointercancel', releasePointer);
        input.addEventListener('pointerleave', releasePointer);
        input.addEventListener('keyup', (event) => {
            if (rangeKeys.includes(event.key) && input.hasAttribute('data-unselected')) chooseScore();
        });
        const ticks = scale.map((value) => element('span', { text: value }));
        fields.append(element('div', { class: 'card field form-field six-factor-field mb-0' }, [
            element('div', { class: 'between wrap six-factor-field-heading' }, [
                element('div', { class: 'hstack' }, [
                    element('span', { class: 'six-factor-number num', text: index + 1, 'aria-hidden': true }),
                    element('label', { for: input.id, text: label }),
                ]),
                element('div', { class: 'hstack six-factor-field-tools' }, [selected, clear]),
            ]),
            element('div', { class: 'six-factor-range-track' }, input),
            element('div', { class: 'six-factor-range-scale', 'aria-hidden': true }, ticks),
            error,
        ]));
        return { key, input, selected, clear, error, ticks };
    });

    function clearResult(root) {
        charts.get(root)?.destroy();
        charts.delete(root);
        root.replaceChildren();
    }

    function renderResult(root, result) {
        const radar = chartHost('Radar penilaian enam faktor, skala 1 sampai 5');
        radar.classList.add('six-factor-radar');
        root.append(
            element('p', { class: 'hstack wrap' }, [
                element('strong', { class: 'section-title', text: formatNumber(result.total_score) + '/30' }),
                element('span', { class: 'pill pill-muted', text: result.category }),
            ]),
            element('p', { class: 'section-desc' }, [finalDate(result), ' · Skala tiap faktor 1–5']),
            radar,
            element('dl', { class: 'factor-score-list six-factor-key' }, factors.map(([key, label], index) => element('div', { class: 'between' }, [
                element('dt', { text: `${shortLabels[index]} · ${label}` }),
                element('dd', { class: 'num', text: result.scores[key] ?? '—' }),
            ]))),
        );

        const options = chartOptions('radar', [{ name: 'Nilai faktor', data: factors.map(([key]) => result.scores[key]) }], shortLabels);
        options.chart.height = 260;
        options.yaxis = {
            min: 1, max: 5, tickAmount: 4, labels: { formatter: (value) => formatNumber(value) },
        };
        options.xaxis.labels = { style: { colors: shortLabels.map(() => 'var(--text-muted)'), fontSize: '12px' } };
        options.grid.padding = { top: 16, left: 12, right: 12 };
        options.tooltip = { enabled: false };
        options.markers = { size: 4 };
        const chart = new ApexCharts(radar, options);
        charts.set(root, chart);
        chart.render().catch(() => {
            if (charts.get(root) === chart) radar.replaceWith(element('p', { class: 'muted', text: 'Radar belum dapat ditampilkan.' }));
        });
    }

    function showHistorical(item, button) {
        byId('historical-title').textContent = 'Final #' + item.id;
        openDialog(historical, button);
        clearResult(historicalContent);
        renderResult(historicalContent, item);
    }

    function renderHistory(root, items) {
        root.append(items.length ? element('ul', { class: 'divide-y divide-[var(--border)]' }, items.map((item) => {
            const button = element('button', {
                id: 'history-result-' + item.id, type: 'button', class: 'btn btn-light button button-outline', text: 'Lihat hasil',
                'aria-label': 'Lihat hasil Final #' + item.id,
                onclick: () => showHistorical(item, button),
            });
            return element('li', { class: 'between wrap py-3' }, [
                element('div', {}, [
                    element('strong', { text: item.category + ' · ' + formatNumber(item.total_score) + '/30' }),
                    element('p', { class: 'section-desc' }, [finalDate(item), ' · #' + item.id]),
                ]),
                button,
            ]);
        })) : emptyFinal());
    }

    function createPanelLoader(root, suffix, placeholder, render) {
        let version = 0;
        let displayed;
        return async function load(quiet = false) {
            if (!active()) return null;
            const current = ++version;
            if (!quiet || displayed === undefined) {
                displayed = undefined;
                clearResult(root);
                root.setAttribute('aria-busy', 'true');
                root.append(placeholder());
            }
            try {
                const data = await request(suffix);
                if (current !== version || !active()) return null;
                const ids = Array.isArray(data) ? data.map((item) => item.id).join(',') : data?.id ?? null;
                if (ids !== displayed) {
                    clearResult(root);
                    render(root, data);
                    displayed = ids;
                }
                return data;
            } catch (error) {
                if (current === version && active()) {
                    displayed = undefined;
                    clearResult(root);
                    showError(root, { status: error.status, message: publicError(error, 'Data belum dapat dimuat.') }, load);
                }
                return null;
            } finally {
                if (current === version) root.setAttribute('aria-busy', 'false');
            }
        };
    }

    const loadLatest = createPanelLoader(byId('latest-result'), '/latest', () => skeleton('h-5 w-1/2', 'h-64', 'h-3 w-1/3'), (root, result) => {
        if (result) renderResult(root, result);
        else root.append(emptyFinal());
    });
    const loadHistory = createPanelLoader(byId('final-history'), '', () => skeleton('h-8 w-3/4', 'h-8 w-2/3', 'h-8 w-3/4'), renderHistory);

    async function reloadResults(quiet = false) {
        const [history] = await Promise.all([loadHistory(quiet), loadLatest(quiet)]);
        return history;
    }

    function clearPrediction() {
        prediction?.controller?.abort();
        prediction = undefined;
        predictionResult.replaceChildren();
        predictionError.hidden = true;
        predictionError.textContent = '';
    }

    function showPredictionError(message) {
        predictionResult.replaceChildren();
        predictionError.hidden = false;
        predictionError.textContent = message;
    }

    function render(state) {
        const { scores, ready, phase, readError, saveError, actionError } = state;
        const loading = phase === 'loading';
        const readState = readError || loading;
        if (readState !== previousReadState) {
            previousReadState = readState;
            loadState.replaceChildren();
            loadState.setAttribute('aria-busy', String(loading));
            if (loading) loadState.append(skeleton(...factors.flatMap(() => ['h-4 w-1/3', 'h-6'])));
            else if (readError && active()) {
                showError(loadState, { status: readError.status, message: publicError(readError, 'Draft belum dapat dimuat.') }, () => draft.refresh());
            }
        }
        form.hidden = (!ready && form.hidden) || (readError?.status === 404);
        fields.disabled = !ready;
        finalize.disabled = !state.canFinalize;
        cancel.disabled = !state.canCancel;
        if (predict) {
            if (prediction && (readError || phase === 'finalize' || phase === 'cancel'
                || factors.some(([key]) => scores[key] !== prediction.scores[key]))) {
                clearPrediction();
            }
            predict.disabled = !ready || Boolean(prediction?.controller);
        }
        retrySave.hidden = (!saveError && !state.holdPending) || !active();
        retrySave.textContent = saveError ? 'Coba lagi' : 'Simpan perubahan';
        retrySave.disabled = !ready;

        const error = actionError || saveError;
        const validation = error?.status === 422 ? error.errors || {} : {};
        const missing = actionError && validation.scores
            ? factors.filter(([key]) => validation.scores.join(' ').includes(key) || scores[key] === null)
            : [];
        const filled = Object.values(scores).filter((score) => score !== null).length;
        progress.value = filled;
        progressText.textContent = `${filled} dari ${factors.length} terisi`;
        for (const { key, input, selected, clear, error: output, ticks } of controls) {
            const score = scores[key];
            const thumb = score ?? scale[0];
            const unselected = score === null;
            if (input.valueAsNumber !== thumb) input.valueAsNumber = thumb;
            input.toggleAttribute('data-unselected', unselected);
            input.setAttribute('aria-valuetext', unselected ? 'Belum dipilih' : score + ' dari ' + scale.at(-1));
            selected.textContent = unselected ? 'Belum dipilih' : score + ' / ' + scale.at(-1);
            selected.classList.toggle('pill-muted', unselected);
            ticks.forEach((tick, index) => tick.classList.toggle('is-selected', score === scale[index]));
            clear.disabled = unselected;
            const message = validation['scores.' + key]?.join(' ') || (missing.some(([missingKey]) => missingKey === key) ? 'Pilih nilai untuk faktor ini.' : '');
            output.textContent = message;
            if (message) input.setAttribute('aria-invalid', 'true');
            else input.removeAttribute('aria-invalid');
        }
        const message = error && error.status !== 409 ? publicError(error, validation.body?.join(' ')
            || (missing.length ? 'Lengkapi nilai untuk ' + missing.map(([, label]) => label).join(', ') + '.'
                : error.status === 422 ? 'Periksa kembali nilai penilaian.'
                    : actionError ? 'Aksi belum dapat dilakukan.' : 'Perubahan belum tersimpan.')) : '';
        formMessage.hidden = !message;
        if (formMessage.textContent !== message) formMessage.textContent = message;
        notice.hidden = !state.notice;
        if (notice.textContent !== state.notice) notice.textContent = state.notice;
        const statusText = ({ finalize: 'Memfinalisasi…', cancel: 'Membatalkan Draft…' })[phase] || state.status;
        if (status.textContent !== statusText) status.textContent = statusText;
        if (state.dirty) window.addEventListener('beforeunload', requestLeave, { signal });
        else window.removeEventListener('beforeunload', requestLeave);
    }

    const draft = createSixFactorDraft({ request, reloadResults, render, active });

    predict?.addEventListener('click', async () => {
        const { scores, ready } = draft.snapshot();
        if (!ready || prediction?.controller) return;

        clearPrediction();
        const current = { scores };
        prediction = current;
        const missing = factors.filter(([key]) => !scale.includes(scores[key]));
        if (missing.length) {
            showPredictionError('Lengkapi nilai untuk ' + missing.map(([, label]) => label).join(', ') + '.');
            return;
        }

        current.controller = new AbortController();
        predict.disabled = true;
        predictionResult.append(element('p', { class: 'section-desc mt-2', text: 'Menghitung prediksi…' }));
        try {
            const result = await request('/predict', { method: 'POST', body: { scores }, signal: current.controller.signal });
            if (prediction !== current || !active()) return;
            predictionResult.replaceChildren(
                element('p', { class: 'hstack wrap mt-3' }, [
                    element('span', { class: 'sr-only', text: 'Total skor prediksi' }),
                    element('strong', { id: 'prediction-total-score', class: 'section-title num', text: formatNumber(result.total_score) + '/30' }),
                    element('span', { id: 'prediction-category', class: 'pill pill-muted', text: result.category }),
                ]),
                element('p', { class: 'section-desc mt-2 break-words' }, [
                    element('strong', { class: 'block', text: 'Rekomendasi' }),
                    element('span', { id: 'prediction-recommendation', text: result.recommendation }),
                ]),
            );
        } catch (error) {
            if (prediction !== current || !active()) return;
            const messages = {
                422: 'Periksa kembali keenam nilai. Setiap faktor memerlukan nilai 1–5.',
                503: 'Layanan prediksi sedang tidak tersedia. Silakan coba lagi.',
                504: 'Waktu tunggu prediksi habis. Silakan coba lagi.',
            };
            showPredictionError(messages[error.status] || publicError(error, 'Prediksi belum dapat dihitung. Silakan coba lagi.'));
        } finally {
            if (prediction === current && active()) {
                current.controller = undefined;
                predict.disabled = !draft.snapshot().ready;
            }
        }
    });

    function ask(nextAction, opener) {
        action = nextAction;
        target = draft.snapshot().draft;
        const finalizing = action === 'finalize';
        byId('draft-confirmation-title').textContent = finalizing ? 'Finalisasi penilaian?' : 'Batalkan Draft?';
        byId('draft-confirmation-description').textContent = finalizing
            ? 'Hasil Final tersimpan permanen dan tidak dapat diubah.'
            : 'Draft yang dibatalkan tidak dapat dilanjutkan. Perubahan yang belum dikirim akan diabaikan.';
        confirm.textContent = finalizing ? 'Finalisasi' : 'Batalkan Draft';
        openDialog(confirmation, opener);
    }

    form.addEventListener('submit', (event) => event.preventDefault());
    bindDialog(confirmation);
    bindDialog(historical);
    historical.addEventListener('close', () => {
        clearResult(historicalContent);
        const opener = historical._opener;
        if (opener && !opener.isConnected) (byId(opener.id) || byId('history-title')).focus();
    }, { signal });
    finalize.addEventListener('click', () => ask('finalize', finalize));
    cancel.addEventListener('click', () => ask('cancel', cancel));
    retrySave.addEventListener('click', () => draft.retrySave());
    confirm.addEventListener('click', () => {
        confirmation.close();
        void draft.transition(action, target);
        byId('draft-title').focus();
    });
    window.addEventListener('focus', () => { if (!confirmation.open && !historical.open) void draft.refresh(true); }, { signal });
    let logoutConfirmed = false;
    document.addEventListener('click', (event) => {
        const logout = event.target.closest('[data-logout]');
        if (!logout) return;
        if (logoutConfirmed) { logoutConfirmed = false; return; }
        if (!draft.snapshot().dirty) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void confirmAction({ title: 'Keluar dari akun?', message: 'Perubahan belum tersimpan. Keluar dari akun?', confirmLabel: 'Keluar', tone: 'danger' }).then((accepted) => {
            if (!accepted || signal.aborted) return;
            logoutConfirmed = true;
            logout.click();
        });
    }, { capture: true, signal });
    document.addEventListener('joyumkm:before-route-change', (event) => {
        if (!draft.snapshot().dirty) return;
        event.preventDefault();
        event.detail.confirmation = { title: 'Tinggalkan halaman?', message: 'Perubahan belum tersimpan. Tinggalkan halaman?', confirmLabel: 'Tinggalkan', tone: 'danger' };
    }, { signal });
    signal.addEventListener('abort', () => {
        draft.stop();
        for (const root of charts.keys()) clearResult(root);
    }, { once: true });

    byId('six-factor-evaluation').hidden = false;
    void draft.refresh();
    void reloadResults();
}
