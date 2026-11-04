import { api } from '../api';
import { calculateScores } from '../scoring';
import { createUmkmPicker } from '../umkm-picker';
import { element, formatScore, bindDialog, openDialog, showError, toast } from '../ui';

export async function init() {
    const form = document.getElementById('evaluation-form');
    const layout = document.getElementById('evaluation-layout');
    const factorContainer = document.getElementById('questionnaire-factors');
    const factorTabs = document.getElementById('factor-tabs');
    const factorNav = document.getElementById('factor-navigation');
    const prevBtn = document.getElementById('previous-factor');
    const nextBtn = document.getElementById('next-factor');
    const state = document.getElementById('questionnaire-state');
    const message = document.getElementById('evaluation-message');
    const empty = document.getElementById('evaluation-empty');
    const saveButton = document.getElementById('save-evaluation');
    const previewButton = document.getElementById('preview-draft');
    const resetButton = document.getElementById('reset-evaluation');
    const draftButton = document.getElementById('save-draft');
    const preview = document.getElementById('draft-preview-modal');
    const serverResult = document.getElementById('evaluation-server-result');
    let questionnaire;
    let selectedUmkm = null;
    let answers = new Map();
    let baseline = '';
    let latest = null;
    let prefillController;
    let prefillSequence = 0;
    let ready = false;
    let saving = false;
    let leaving = false;
    let currentFactorIndex = 0;
    bindDialog(preview);
    preview.addEventListener('cancel', (event) => { if (saving) event.preventDefault(); });

    const activeQuestions = () => questionnaire.factors.flatMap((factor) => factor.questions);
    const serializeAnswers = () => activeQuestions().filter((question) => answers.has(question.id)).map((question) => ({ question_id: question.id, value: answers.get(question.id) }));
    const fingerprint = () => JSON.stringify(serializeAnswers());
    const isDirty = () => Boolean(questionnaire && selectedUmkm && fingerprint() !== baseline);
    const confirmDiscard = () => !isDirty() || window.confirm('Perubahan belum disimpan. Buang perubahan dan lanjutkan?');

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
                role: 'tab',
                'aria-selected': isCurrent ? 'true' : 'false',
                onclick: () => switchFactor(index),
            }, [
                element('span', { text: factor.code }),
                element('span', { class: 'factor-complete', text: ` (${answeredInFactor}/${totalInFactor})` }),
            ]);
        }));
        prevBtn.disabled = currentFactorIndex <= 0;
        nextBtn.disabled = currentFactorIndex >= questionnaire.factors.length - 1;
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
        document.getElementById('umkm-picker').disabled = saving;
        preview.querySelectorAll('[data-close-dialog]').forEach((button) => { button.disabled = saving; });
        saveButton.textContent = saving ? 'Menyimpan…' : 'Simpan sebagai Final';
        draftButton.textContent = saving ? 'Menyimpan…' : 'Simpan sebagai Draft';
    }

    function clearValidation() {
        message.hidden = true;
        message.textContent = '';
        form.querySelectorAll('[data-question-error]').forEach((node) => { node.textContent = ''; });
        form.querySelectorAll('[aria-invalid]').forEach((node) => node.removeAttribute('aria-invalid'));
    }

    function renderLive(result = null) {
        const scores = result || calculateScores(questionnaire, serializeAnswers());
        document.getElementById('evaluation-completeness').textContent = `${scores.answered_count} dari ${scores.total_questions} soal terjawab`;
        const progress = document.getElementById('factor-progress');
        progress.replaceChildren(...questionnaire.factors.map((factor) => {
            const score = scores.factor_scores[factor.code];
            const row = element('div', { class: 'factor-progress-row' }, [
                element('div', { class: 'factor-progress-label between hstack', style: 'margin-bottom:4px;' }, [
                    element('span', { class: 'small strong', text: factor.code }),
                    element('strong', { class: 'num small', text: formatScore(score) }),
                ]),
                element('div', { class: 'progress', style: 'height:6px;' }, [
                    element('span', { style: `width:${Math.max(0, Math.min(100, Number(score) || 0))}%;background:${factor.color}` }),
                ]),
            ]);
            return row;
        }));
        document.getElementById('live-average').textContent = formatScore(scores.average_score);
        const category = document.getElementById('live-category');
        category.textContent = scores.category;
        category.style.color = [...questionnaire.scoring.categories, questionnaire.scoring.unrated].find((item) => item.label === scores.category)?.color || '';
        document.getElementById('evaluation-status').textContent = !selectedUmkm ? 'Pilih UMKM untuk mulai mengisi.' : (latest?.status === 'final' && !isDirty() ? 'Evaluasi terakhir: Final.' : 'Sementara — simpan final setelah seluruh soal terjawab.');
        renderTabs();
        setControls();
        return scores;
    }

    function makeRadio(question, option, pill = false) {
        const radio = element('input', { type: 'radio', name: `question_${question.id}`, value: option.value, 'data-question-id': question.id, 'aria-label': `${question.text} — ${option.value}: ${option.label}`, 'aria-describedby': `question-error-${question.id}` });
        radio.checked = answers.get(question.id) === option.value;
        return element('label', { class: pill ? 'radio-pill' : 'rating-option' }, [
            radio,
            element('span', { text: pill ? `${option.value} = ${option.label}` : option.value }),
        ]);
    }

    function renderQuestions() {
        factorContainer.replaceChildren();
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

            if (factor.questions.every((question) => question.input_type === 'likert')) {
                const qGroup = element('div', { class: 'question-group' });
                factor.questions.forEach((question, qIdx) => {
                    qGroup.append(element('div', { class: 'question-block', role: 'radiogroup', 'aria-labelledby': `question-title-${question.id}` }, [
                        element('div', { class: 'question-title' }, [
                            element('span', { class: 'question-index', text: `${qIdx + 1}` }),
                            element('div', {}, [
                                element('strong', { id: `question-title-${question.id}`, text: question.text }),
                                element('p', { id: `question-error-${question.id}`, class: 'field-error', 'data-question-error': question.id }),
                            ]),
                        ]),
                        element('div', { class: 'rating-options' }, question.options.map((option) => makeRadio(question, option, false))),
                    ]));
                });
                card.append(qGroup);
            } else {
                const qGroup = element('div', { class: 'question-group' });
                factor.questions.forEach((question, qIdx) => {
                    qGroup.append(element('div', { class: 'question-block radio-question', role: 'radiogroup', 'aria-labelledby': `question-title-${question.id}` }, [
                        element('div', { class: 'question-title' }, [
                            element('span', { class: 'question-index', text: `${qIdx + 1}` }),
                            element('div', {}, [
                                element('strong', { id: `question-title-${question.id}`, text: question.text }),
                                element('p', { id: `question-error-${question.id}`, class: 'field-error', 'data-question-error': question.id }),
                            ]),
                        ]),
                        element('div', { class: 'radio-options', style: 'display:flex;gap:8px;flex-wrap:wrap;' }, question.options.map((option) => makeRadio(question, option, true))),
                    ]));
                });
                card.append(qGroup);
            }
            factorContainer.append(card);
        });
        renderTabs();
        switchFactor(currentFactorIndex);
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
        leaving = false;
        ready = false;
        answers = new Map();
        latest = null;
        baseline = fingerprint();
        clearValidation();
        serverResult.hidden = true;
        empty.hidden = Boolean(umkm);
        factorContainer.replaceChildren();
        const query = new URL(window.location.href);
        if (umkm) query.searchParams.set('umkm', umkm.id); else query.searchParams.delete('umkm');
        window.history.replaceState({}, '', query);
        renderLive();
        if (!umkm) return;
        factorContainer.append(element('div', { class: 'panel' }, [element('div', { class: 'skeleton h-32' }), element('p', { class: 'muted mt-3', text: 'Memuat evaluasi terbaru…' })]));
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
                form.querySelectorAll(`[data-question-id="${id}"]`).forEach((radio) => radio.setAttribute('aria-invalid', 'true'));
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
        } catch (error) {
            if (error.status === 0) {
                let latestNotice = '';
                try {
                    const { data } = await api(`/umkms/${umkmId}/evaluations/latest`);
                    latestNotice = data ? ` Evaluasi terbaru di server: #${data.id}, skor ${formatScore(data.average_score)} (${data.status === 'draft' ? 'Sementara' : 'Final'}).` : ' Belum ada evaluasi tersimpan di server.';
                } catch { latestNotice = ' Evaluasi terbaru belum dapat diperiksa.'; }
                message.textContent = `${error.message} Hasil simpan perlu diperiksa sebelum Anda mencoba menyimpan kembali.${latestNotice} Jawaban lokal Anda tetap dipertahankan.`;
                message.hidden = false;
                preview.close();
                message.focus();
            } else {
                preview.close();
                showEvaluationErrors(error, submittedAnswers);
            }
        } finally { saving = false; setControls(); }
    }

    form.addEventListener('change', (event) => {
        if (!event.target.matches('[data-question-id]')) return;
        leaving = false;
        answers.set(Number(event.target.dataset.questionId), Number(event.target.value));
        clearValidation();
        serverResult.hidden = true;
        renderLive();
    });
    form.addEventListener('submit', (event) => { event.preventDefault(); saveEvaluation('final'); });
    prevBtn?.addEventListener('click', () => switchFactor(currentFactorIndex - 1));
    nextBtn?.addEventListener('click', () => switchFactor(currentFactorIndex + 1));
    previewButton.addEventListener('click', renderPreview);
    draftButton.addEventListener('click', () => saveEvaluation('draft'));
    resetButton.addEventListener('click', () => {
        if (answers.size && !window.confirm('Kosongkan seluruh jawaban pada form ini? Data tersimpan tidak akan dihapus.')) return;
        answers = new Map();
        leaving = false;
        clearValidation();
        serverResult.hidden = true;
        renderQuestions();
        renderLive();
    });
    window.addEventListener('beforeunload', (event) => { if (isDirty() && !leaving) { event.preventDefault(); event.returnValue = ''; } });
    window.addEventListener('joyumkm:logout-failed', () => { leaving = false; });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href], [data-logout]');
        if (!link || link.target === '_blank' || link.getAttribute('href')?.startsWith('#') || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (isDirty()) {
            if (!confirmDiscard()) { event.preventDefault(); event.stopImmediatePropagation(); }
            else leaving = true;
        }
    }, true);

    async function loadQuestionnaire() {
        try {
            ({ data: questionnaire } = await api('/questionnaire'));
            state.hidden = true;
            layout.hidden = false;
            baseline = fingerprint();
            renderLive();
            await createUmkmPicker(document.getElementById('umkm-picker'), { initialId: new URLSearchParams(window.location.search).get('umkm'), beforeChange: () => !saving && confirmDiscard(), onChange: loadSelected });
        } catch (error) { showError(state, error, loadQuestionnaire); }
    }
    await loadQuestionnaire();
}
