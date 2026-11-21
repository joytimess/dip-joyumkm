import { pageApi } from '../api';
import { calculateScores } from '../scoring';
import { createUmkmPicker } from '../umkm-picker';
import { syncUmkmBreadcrumb, withListContext } from '../umkm-navigation';
import { confirmAction, element, formatScore, bindDialog, openDialog, showError, skeleton, toast } from '../ui';

const sliderKeys = ['Home', 'End', 'ArrowLeft', 'ArrowRight', 'ArrowDown', 'ArrowUp'];

export async function init(signal) {
    const api = pageApi(signal);
    const form = document.getElementById('evaluation-form');
    const layout = document.getElementById('evaluation-layout');
    const factorContainer = document.getElementById('questionnaire-factors');
    const factorTabs = document.getElementById('factor-tabs');
    const factorNav = document.getElementById('factor-navigation');
    const prevBtn = document.getElementById('previous-factor');
    const nextBtn = document.getElementById('next-factor');
    const state = document.getElementById('questionnaire-state');
    const picker = document.getElementById('umkm-picker');
    const message = document.getElementById('evaluation-message');
    const empty = document.getElementById('evaluation-empty');
    const sidebar = layout.querySelector('.evaluation-sidebar');
    const saveButton = document.getElementById('save-evaluation');
    const previewButton = document.getElementById('preview-draft');
    const resetButton = document.getElementById('reset-evaluation');
    const draftButton = document.getElementById('save-draft');
    const preview = document.getElementById('draft-preview-modal');
    const serverResult = document.getElementById('evaluation-server-result');
    let questionnaire;
    let selectedUmkm = null;
    let pendingUmkm = null;
    let answers = new Map();
    let baseline = '';
    let latest = null;
    let prefillController;
    let prefillSequence = 0;
    let ready = false;
    let saving = false;
    let leaving = false;
    let currentFactorIndex = 0;
    const sliders = new Map();
    bindDialog(preview);
    preview.addEventListener('cancel', (event) => { if (saving) event.preventDefault(); });

    const activeQuestions = () => questionnaire.factors.flatMap((factor) => factor.questions);
    const serializeAnswers = () => activeQuestions().filter((question) => answers.has(question.id)).map((question) => ({ question_id: question.id, value: answers.get(question.id) }));
    const fingerprint = () => JSON.stringify(serializeAnswers());
    const isDirty = () => Boolean(questionnaire && selectedUmkm && fingerprint() !== baseline);
    const discardConfirmation = { title: 'Buang perubahan?', message: 'Perubahan belum disimpan. Buang perubahan dan lanjutkan?', confirmLabel: 'Buang perubahan', tone: 'danger' };
    const confirmDiscard = async () => !isDirty() || confirmAction(discardConfirmation);
    const finalConfirmation = { title: 'Simpan sebagai Final?', message: 'Evaluasi final akan menjadi hasil terbaru untuk UMKM ini. Pastikan seluruh jawaban sudah sesuai.', confirmLabel: 'Simpan Final' };

    function renderTabs() {
        if (!questionnaire || !selectedUmkm) {
            factorTabs.hidden = true;
            factorNav.hidden = true;
            return;
        }
        factorTabs.hidden = false;
        factorNav.hidden = false;
        factorTabs.replaceChildren(...questionnaire.factors.map((factor, index) => {
            const answeredInFactor = factor.questions.filter((q) => answers.has(q.id)).length;
            const totalInFactor = factor.questions.length;
            const isCurrent = index === currentFactorIndex;
            return element('button', {
                type: 'button',
                class: `factor-tab ${isCurrent ? 'active' : ''}`,
                'aria-pressed': String(isCurrent),
                onclick: () => {
                    switchFactor(index);
                    factorTabs.children[index].focus();
                },
            }, [
                element('span', { text: factor.code }),
                element('span', { class: 'factor-complete', text: ` (${answeredInFactor}/${totalInFactor})` }),
            ]);
        }));
        syncFactorNavigation();
    }

    function syncFactorNavigation() {
        if (!questionnaire) return;
        const onLastFactor = currentFactorIndex >= questionnaire.factors.length - 1;
        prevBtn.disabled = currentFactorIndex <= 0;
        nextBtn.type = onLastFactor ? 'submit' : 'button';
        nextBtn.textContent = onLastFactor ? (saving ? 'Menyimpan…' : 'Simpan sebagai Final') : 'Dimensi berikutnya';
        nextBtn.disabled = onLastFactor && !(selectedUmkm && ready && !saving);
    }

    function switchFactor(index) {
        if (!questionnaire || index < 0 || index >= questionnaire.factors.length) return;
        currentFactorIndex = index;
        renderTabs();
        const factorCards = factorContainer.querySelectorAll('.factor-card');
        factorCards.forEach((card, i) => {
            card.hidden = i !== currentFactorIndex;
        });
    }

    function setControls() {
        for (const button of [saveButton, previewButton, resetButton, draftButton]) button.disabled = !selectedUmkm || !ready || saving;
        form.querySelectorAll('input').forEach((input) => { input.disabled = saving || !ready; });
        for (const { input, clear } of sliders.values()) clear.disabled = saving || !ready || input.hasAttribute('data-unselected');
        picker.disabled = saving;
        preview.querySelectorAll('[data-close-dialog]').forEach((button) => { button.disabled = saving; });
        saveButton.querySelector('[data-button-label]').textContent = saving ? 'Menyimpan…' : 'Simpan sebagai Final';
        draftButton.textContent = saving ? 'Menyimpan…' : 'Simpan sebagai Draft';
        syncFactorNavigation();
    }

    function clearValidation() {
        message.hidden = true;
        message.textContent = '';
        form.querySelectorAll('[data-question-error]').forEach((node) => { node.textContent = ''; });
        form.querySelectorAll('[aria-invalid]').forEach((node) => node.removeAttribute('aria-invalid'));
    }

    function categoryTone(category) {
        if (/^(SANGAT BAIK|BAIK)$/i.test(category)) return 'good';
        if (/^CUKUP$/i.test(category)) return 'warn';
        if (/^(KURANG|KRITIS)$/i.test(category)) return 'danger';
        return 'muted';
    }

    function renderBadge() {
        const badge = document.getElementById('evaluation-badge');
        const state = !selectedUmkm ? null
            : isDirty() ? { label: 'Belum disimpan', tone: 'warn' }
                : { final: { label: 'Final', tone: 'good' }, draft: { label: 'Draft', tone: 'draft' } }[latest?.status] || null;
        badge.hidden = !state;
        badge.textContent = state?.label || '';
        badge.dataset.tone = state?.tone || '';
    }

    function renderLive(result = null) {
        const scores = result || calculateScores(questionnaire, serializeAnswers());
        const total = scores.total_questions || 0;
        const answered = scores.answered_count;
        const completeness = document.getElementById('evaluation-progress');
        completeness.max = total || 1;
        completeness.value = answered;
        completeness.classList.toggle('is-complete', total > 0 && answered === total);
        document.getElementById('evaluation-completeness').textContent = `${answered} dari ${total} soal terjawab`;
        document.getElementById('evaluation-percent').textContent = `${total ? Math.round((answered / total) * 100) : 0}%`;
        const progress = document.getElementById('factor-progress');
        progress.replaceChildren(...questionnaire.factors.map((factor) => {
            const score = scores.factor_scores[factor.code];
            return element('div', { class: 'live-factor-row' }, [
                element('div', { class: 'live-factor-head' }, [
                    element('span', { class: 'live-factor-code', text: factor.code }),
                    element('strong', { class: 'num', text: formatScore(score) }),
                ]),
                element('div', { class: 'live-bar', 'aria-hidden': 'true' }, [
                    element('span', { style: `width:${Math.max(0, Math.min(100, Number(score) || 0))}%` }),
                ]),
            ]);
        }));
        document.getElementById('live-average').textContent = formatScore(scores.average_score);
        document.getElementById('live-score-unit').hidden = scores.average_score === null;
        const category = document.getElementById('live-category');
        category.textContent = scores.category;
        category.dataset.tone = categoryTone(scores.category);
        renderBadge();
        document.getElementById('evaluation-status').textContent = isDirty()
            ? 'Ada perubahan yang belum disimpan.'
            : { final: 'Evaluasi terakhir: Final.', draft: 'Evaluasi terakhir: Draft tersimpan.' }[latest?.status] || 'Belum ada evaluasi tersimpan.';
        syncSliders();
        renderTabs();
        setControls();
        return scores;
    }

    function setAnswer(questionId, value) {
        if (value === null) answers.delete(questionId); else answers.set(questionId, value);
        leaving = false;
        clearValidation();
        serverResult.hidden = true;
        renderLive();
    }

    function syncSlider({ question, input, selected, ticks }) {
        const answer = answers.get(question.id);
        const index = question.options.findIndex((option) => option.value === answer);
        const unselected = index === -1;
        const current = unselected ? 0 : index;
        const label = unselected ? 'Belum dipilih' : `${question.options[current].value} · ${question.options[current].label}`;
        if (input.value !== String(current)) input.value = String(current);
        input.toggleAttribute('data-unselected', unselected);
        input.setAttribute('aria-valuetext', label);
        selected.textContent = label;
        selected.classList.toggle('pill-muted', unselected);
        ticks.forEach((tick, tickIndex) => tick.classList.toggle('is-selected', !unselected && tickIndex === index));
    }

    function makeSlider(question, number) {
        const { options } = question;
        const tickLabelled = question.input_type === 'labeled_radio';
        const input = element('input', {
            type: 'range', min: 0, max: options.length - 1, step: 1, value: 0,
            id: `question-slider-${question.id}`, name: `question_${question.id}`, class: 'six-factor-range',
            'data-question-id': question.id, 'aria-labelledby': `question-title-${question.id}`, 'aria-describedby': `question-error-${question.id}`,
        });
        const selected = element('span', { class: 'pill six-factor-value num', 'aria-hidden': 'true' });
        const clear = element('button', {
            type: 'button', class: 'btn button button-ghost six-factor-clear', text: 'Kosongkan',
            'aria-label': `Kosongkan jawaban: ${question.text}`,
            onclick: () => { input.focus(); setAnswer(question.id, null); },
        });
        const chooseFromSlider = () => setAnswer(question.id, options[Number(input.value)].value);
        let activePointer;
        const releasePointer = (event) => {
            if (event.pointerId === activePointer) activePointer = undefined;
        };
        input.addEventListener('input', chooseFromSlider);
        // Only a pointer gesture on the slider selects its resting value, so the thumb is not chosen by accident.
        input.addEventListener('pointerdown', (event) => {
            if (event.isPrimary && event.button === 0) activePointer = event.pointerId;
        });
        input.addEventListener('pointerup', (event) => {
            if (event.pointerId !== activePointer) return;
            releasePointer(event);
            if (input.hasAttribute('data-unselected')) chooseFromSlider();
        });
        input.addEventListener('pointercancel', releasePointer);
        input.addEventListener('pointerleave', releasePointer);
        input.addEventListener('keyup', (event) => {
            if (sliderKeys.includes(event.key) && input.hasAttribute('data-unselected')) chooseFromSlider();
        });
        const ticks = options.map((option) => element('span', { text: tickLabelled ? option.label : option.value }));
        const control = { question, input, selected, clear, ticks };
        sliders.set(question.id, control);
        syncSlider(control);

        return element('div', { class: 'question-block question-slider' }, [
            element('div', { class: 'between wrap six-factor-field-heading' }, [
                element('div', { class: 'question-title' }, [
                    element('span', { class: 'question-index', text: number }),
                    element('div', {}, [
                        element('strong', { id: `question-title-${question.id}`, text: question.text }),
                        element('p', { id: `question-error-${question.id}`, class: 'field-error', 'data-question-error': question.id }),
                    ]),
                ]),
                element('div', { class: 'hstack six-factor-field-tools' }, [selected, clear]),
            ]),
            element('div', { class: 'six-factor-range-track' }, input),
            element('div', { class: 'six-factor-range-scale', 'aria-hidden': 'true' }, ticks),
        ]);
    }

    function renderQuestions() {
        factorContainer.replaceChildren();
        sliders.clear();
        questionnaire.factors.forEach((factor, fIdx) => {
            const card = element('section', { class: 'card card-pad panel factor-card', 'aria-labelledby': `factor-title-${factor.id}` }, [
                element('div', { class: 'section-top', style: 'margin-bottom:18px;' }, [
                    element('div', {}, [
                        element('h2', { id: `factor-title-${factor.id}`, class: 'section-title', text: factor.name }),
                        element('p', { class: 'section-desc muted', text: factor.description }),
                    ]),
                ]),
            ]);
            card.style.setProperty('--factor-color', factor.color);
            card.hidden = fIdx !== currentFactorIndex;

            const qGroup = element('div', { class: 'question-group' });
            factor.questions.forEach((question, qIdx) => qGroup.append(makeSlider(question, qIdx + 1)));
            card.append(qGroup);
            factorContainer.append(card);
        });
        renderTabs();
        switchFactor(currentFactorIndex);
    }

    function syncSliders() {
        for (const control of sliders.values()) syncSlider(control);
    }

    function acceptEvaluation(evaluation) {
        const activeIds = new Set(activeQuestions().map((question) => question.id));
        answers = new Map((evaluation?.answers || []).filter((answer) => activeIds.has(answer.question_id)).map((answer) => [answer.question_id, answer.value]));
        latest = evaluation;
        baseline = fingerprint();
    }

    async function loadSelected(umkm) {
        const sequence = ++prefillSequence;
        prefillController?.abort();
        prefillController = new AbortController();
        selectedUmkm = umkm;
        syncUmkmBreadcrumb(umkm);
        leaving = false;
        ready = false;
        answers = new Map();
        latest = null;
        baseline = fingerprint();
        clearValidation();
        serverResult.hidden = true;
        empty.hidden = Boolean(umkm);
        sidebar.hidden = !umkm;
        factorContainer.replaceChildren();
        sliders.clear();
        const query = new URL(window.location.href);
        if (umkm) query.searchParams.set('umkm', umkm.id); else query.searchParams.delete('umkm');
        window.history.replaceState(history.state, '', query);
        window.dispatchEvent(new Event('joyumkm:query-updated'));
        renderLive();
        if (!umkm) {
            factorContainer.setAttribute('aria-busy', 'false');
            return;
        }
        factorContainer.setAttribute('aria-busy', 'true');
        factorContainer.append(element('div', { class: 'panel card-pad' }, [skeleton('h-4 w-1/3', 'h-10', 'h-4 w-1/4', 'h-10')]));
        try {
            const { data } = await api(`/umkms/${umkm.id}/evaluations/latest`, { signal: prefillController.signal });
            if (sequence !== prefillSequence) return;
            acceptEvaluation(data);
            ready = true;
            renderQuestions();
            renderLive();
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== prefillSequence) return;
            showError(factorContainer, error, () => loadSelected(umkm));
        } finally {
            if (sequence === prefillSequence) factorContainer.setAttribute('aria-busy', 'false');
        }
    }

    function renderPreview() {
        const scores = renderLive();
        const content = document.getElementById('draft-preview-content');
        content.replaceChildren(element('div', { class: 'preview-summary' }, [
            element('strong', { text: selectedUmkm.name }),
            element('p', { text: `${scores.answered_count}/${scores.total_questions} terjawab · Skor ${formatScore(scores.average_score)} · ${scores.category}` }),
            element('span', { class: 'draft-badge', text: 'Sementara' }),
        ]));
        for (const factor of questionnaire.factors) {
            content.append(element('h3', { class: 'preview-factor-title', text: factor.name }));
            const list = element('dl', { class: 'preview-answers' });
            factor.questions.forEach((question) => {
                const selected = question.options.find((option) => option.value === answers.get(question.id));
                list.append(element('dt', { text: question.text }), element('dd', { text: selected ? `${selected.value} — ${selected.label}` : 'Belum dijawab' }));
            });
            content.append(list);
        }
        openDialog(preview, previewButton);
    }

    function showEvaluationErrors(error, submittedAnswers) {
        clearValidation();
        message.textContent = error.message;
        const extraMessages = new Set();
        for (const [path, messages] of Object.entries(error.errors || {})) {
            if (path === 'missing_question_ids') continue;
            const match = /^answers\.(\d+)\.(?:value|question_id)$/.exec(path);
            const id = match ? submittedAnswers[Number(match[1])]?.question_id : null;
            const output = id ? form.querySelector(`[data-question-error="${id}"]`) : null;
            if (output) {
                output.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
                form.querySelectorAll(`[data-question-id="${id}"]`).forEach((slider) => slider.setAttribute('aria-invalid', 'true'));
            } else if (Array.isArray(messages)) messages.forEach((text) => extraMessages.add(String(text)));
        }
        if (extraMessages.size) message.append(' ', [...extraMessages].join(' '));
        if (error.status === 422) {
            activeQuestions().filter((question) => !answers.has(question.id)).forEach((question) => {
                const output = form.querySelector(`[data-question-error="${question.id}"]`);
                if (output) output.textContent = 'Pertanyaan ini belum dijawab.';
            });
        }
        message.hidden = false;
        const firstError = form.querySelector('[aria-invalid="true"]') || activeQuestions().filter((question) => !answers.has(question.id)).map((question) => form.querySelector(`[data-question-id="${question.id}"]`))[0];
        if (firstError) {
            const qIdAttr = firstError.dataset?.questionId || firstError.closest('[data-question-id]')?.dataset?.questionId;
            if (qIdAttr) {
                const questionId = Number(qIdAttr);
                const fIdx = questionnaire.factors.findIndex((f) => f.questions.some((q) => q.id === questionId));
                if (fIdx !== -1 && fIdx !== currentFactorIndex) {
                    switchFactor(fIdx);
                }
            }
        }
        queueMicrotask(() => (firstError || message).focus());
    }

    async function saveEvaluation(status) {
        if (!selectedUmkm || !ready || saving) return;
        clearValidation();
        const submittedAnswers = serializeAnswers();
        const umkmId = selectedUmkm.id;
        saving = true;
        setControls();
        let saved = false;
        try {
            const { data } = await api(`/umkms/${umkmId}/evaluations`, { method: 'POST', body: { status, answers: submittedAnswers } });
            acceptEvaluation(data);
            renderQuestions();
            renderLive(data);
            serverResult.textContent = `${status === 'draft' ? 'Draft sementara' : 'Evaluasi final'} tersimpan. Skor server: ${formatScore(data.average_score)} · ${data.category}.`;
            serverResult.hidden = false;
            preview.close();
            toast(status === 'draft' ? 'Draft evaluasi berhasil disimpan.' : 'Evaluasi final berhasil disimpan.');
            window.dispatchEvent(new Event('joyumkm:evaluation-saved'));
            saved = true;
        } catch (error) {
            if (signal.aborted || error.name === 'AbortError') return;
            if (error.status === 0) {
                let latestNotice = '';
                try {
                    const { data } = await api(`/umkms/${umkmId}/evaluations/latest`);
                    latestNotice = data ? ` Evaluasi terbaru di server: #${data.id}, skor ${formatScore(data.average_score)} (${data.status === 'draft' ? 'Sementara' : 'Final'}).` : ' Belum ada evaluasi tersimpan di server.';
                } catch { latestNotice = ' Evaluasi terbaru belum dapat diperiksa.'; }
                if (signal.aborted) return;
                message.textContent = `${error.message} Hasil simpan perlu diperiksa sebelum Anda mencoba menyimpan kembali.${latestNotice} Jawaban lokal Anda tetap dipertahankan.`;
                message.hidden = false;
                preview.close();
                message.focus();
            } else {
                preview.close();
                showEvaluationErrors(error, submittedAnswers);
            }
        } finally { if (!signal.aborted) { saving = false; setControls(); } }
        if (saved && status === 'final' && !signal.aborted) openSixFactorEvaluation(umkmId);
    }

    // Dipanggil setelah saving dilepas, agar guard "belum disimpan" tidak menahan perpindahan halaman.
    function openSixFactorEvaluation(umkmId) {
        const link = element('a', { href: withListContext(`/umkms/${umkmId}/six-factor-evaluations`), hidden: true });
        document.body.append(link);
        link.click();
        link.remove();
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!selectedUmkm || !ready || saving) return;
        if (!(await confirmAction(finalConfirmation))) return;
        saveEvaluation('final');
    });
    prevBtn?.addEventListener('click', () => switchFactor(currentFactorIndex - 1));
    nextBtn?.addEventListener('click', () => switchFactor(currentFactorIndex + 1));
    previewButton.addEventListener('click', renderPreview);
    draftButton.addEventListener('click', () => saveEvaluation('draft'));
    resetButton.addEventListener('click', async () => {
        if (answers.size && !(await confirmAction({ title: 'Kosongkan jawaban?', message: 'Kosongkan seluruh jawaban pada form ini? Data tersimpan tidak akan dihapus.', confirmLabel: 'Kosongkan', tone: 'danger' }))) return;
        answers = new Map();
        leaving = false;
        clearValidation();
        serverResult.hidden = true;
        renderQuestions();
        renderLive();
    });
    window.addEventListener('beforeunload', (event) => { if (isDirty() && !leaving) { event.preventDefault(); event.returnValue = ''; } }, { signal });
    window.addEventListener('joyumkm:logout-failed', () => { leaving = false; }, { signal });
    let logoutConfirmed = false;
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-logout]');
        if (logoutConfirmed) { logoutConfirmed = false; return; }
        if (!link || link.target === '_blank' || link.getAttribute('href')?.startsWith('#') || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (!isDirty()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void confirmAction(discardConfirmation).then((accepted) => {
            if (!accepted || signal.aborted) return;
            leaving = true;
            logoutConfirmed = true;
            link.click();
        });
    }, { capture: true, signal });
    document.addEventListener('joyumkm:before-route-change', (event) => {
        if (saving) { event.preventDefault(); return; }
        if (!isDirty()) return;
        event.preventDefault();
        event.detail.confirmation = discardConfirmation;
    }, { signal });

    async function loadQuestionnaire() {
        state.setAttribute('aria-busy', 'true');
        state.replaceChildren(skeleton('h-4 w-1/4', 'h-10', 'h-4 w-1/3', 'h-10', 'h-10'));
        try {
            ({ data: questionnaire } = await api('/questionnaire'));
            state.hidden = true;
            state.setAttribute('aria-busy', 'false');
            layout.hidden = false;
            baseline = fingerprint();
            renderLive();
            if (pendingUmkm) await loadSelected(pendingUmkm);
        } catch (error) { if (!signal.aborted && error.name !== 'AbortError') showError(state, error, loadQuestionnaire); }
    }
    await Promise.all([
        loadQuestionnaire(),
        createUmkmPicker(picker, {
            signal,
            searchable: false,
            initialId: new URLSearchParams(window.location.search).get('umkm'),
            beforeChange: async () => !saving && (await confirmDiscard()),
            onChange: (umkm) => {
                pendingUmkm = umkm;
                if (questionnaire) return loadSelected(umkm);
            },
        }),
    ]);
}
