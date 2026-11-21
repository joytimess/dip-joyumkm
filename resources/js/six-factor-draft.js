export const sixFactors = {
    sumber_daya: 'Sumber Daya',
    kepemimpinan: 'Kepemimpinan',
    lingkungan_kerja: 'Lingkungan Kerja',
    nilai_organisasi: 'Nilai Organisasi',
    stabilitas: 'Stabilitas',
    kinerja: 'Kinerja',
};

const keys = Object.keys(sixFactors);
const valid = (score) => Number.isInteger(score) && score >= 1 && score <= 5;
const uncertain = (error) => error && (!error.status || error.status >= 500);
const changed = 'Draft telah berubah. Tinjau kembali data terbaru sebelum melanjutkan.';

export function createSixFactorDraft({ request, reloadResults, render, active = () => true, debounce = 700 }) {
    const pending = new Map();
    let draft = null;
    let loaded = false;
    let phase = null;
    let timer;
    let dueAt;
    let revision = 0;
    let inFlight;
    let saveError;
    let readError;
    let actionError;
    let holdPending = false;
    let refreshAfterSave = false;
    let notice = '';
    let stopped = false;

    const alive = () => !stopped && active();

    function snapshot() {
        const scores = Object.fromEntries(keys.map((key) => [key, draft?.scores[key] ?? null]));
        for (const [key, edit] of pending) scores[key] = edit.value;
        const ready = alive() && loaded && !phase && !readError;
        let status = draft ? 'Tersimpan' : 'Belum ada Draft aktif.';
        if (pending.size) status = 'Menunggu penyimpanan';
        if (inFlight) status = 'Menyimpan…';
        if (saveError) status = 'Gagal menyimpan';
        return {
            draft, scores, status, ready, phase, notice, readError, saveError, actionError,
            dirty: alive() && pending.size > 0,
            holdPending: holdPending && pending.size > 0,
            canCancel: ready && !!draft && !uncertain(saveError),
            canFinalize: ready && !!draft && !pending.size && !inFlight && !saveError && keys.every((key) => valid(scores[key])),
        };
    }

    const update = () => render(snapshot());

    function stop() {
        stopped = true;
        clearTimeout(timer);
        update();
    }

    function schedule() {
        clearTimeout(timer);
        if (alive() && loaded && pending.size && !phase && !inFlight && !readError && !saveError && !holdPending) {
            timer = setTimeout(save, Math.max(0, dueAt - Date.now()));
        }
    }

    function adopt(current) {
        const replaced = draft?.id !== current?.id;
        if (replaced && draft) {
            if (pending.size) notice = 'Draft telah berubah. Perubahan lokal yang belum tersimpan tidak diterapkan.';
            else if (loaded) notice = changed;
            pending.clear();
            saveError = undefined;
            holdPending = false;
        } else if (draft && keys.some((key) => current.scores[key] !== draft.scores[key])) {
            notice = changed;
        }
        draft = current;
    }

    async function readDraft() {
        try {
            const current = await request('/draft');
            if (!alive()) return false;
            adopt(current);
            loaded = true;
            readError = undefined;
            return true;
        } catch (error) {
            readError = error;
            return false;
        }
    }

    async function refresh(background = false) {
        if (!alive() || phase) return;
        if (inFlight) {
            refreshAfterSave ||= background;
            return;
        }
        clearTimeout(timer);
        phase = background && loaded && !readError ? 'refreshing' : 'loading';
        update();
        if (background) void reloadResults(true);
        await readDraft();
        phase = null;
        update();
        schedule();
    }

    function save() {
        if (!alive() || phase || inFlight || !pending.size || readError) return;
        saveError = undefined;
        holdPending = false;
        const sent = new Map(pending);
        const scores = Object.fromEntries(Array.from(sent, ([key, edit]) => [key, edit.value]));
        inFlight = request('/draft', { method: 'PATCH', body: { scores } }).then((current) => {
            if (!alive()) return;
            for (const [key, edit] of sent) {
                if (pending.get(key)?.revision === edit.revision) pending.delete(key);
            }
            if (draft && draft.id !== current.id) adopt(current);
            else draft = current;
        }).catch((error) => {
            saveError = error;
        }).finally(() => {
            inFlight = undefined;
            const refreshRequested = refreshAfterSave;
            refreshAfterSave = false;
            update();
            if (saveError?.status === 409 && !phase && alive()) {
                notice = changed;
                void Promise.all([refresh(), reloadResults()]);
            } else if (refreshRequested && !phase) {
                void refresh(true);
            }
            schedule();
        });
        update();
        return inFlight;
    }

    function edit(key, value) {
        if (!snapshot().ready || !Object.hasOwn(sixFactors, key) || (value !== null && !valid(value))) return;
        if (snapshot().scores[key] === value) return;
        pending.set(key, { value, revision: ++revision });
        holdPending = false;
        actionError = undefined;
        notice = '';
        dueAt = Date.now() + debounce;
        update();
        schedule();
    }

    function retrySave() {
        clearTimeout(timer);
        return save();
    }

    async function transition(action, target) {
        const state = snapshot();
        const finalizing = action === 'finalize';
        if (!target || (finalizing ? !state.canFinalize : !state.canCancel)) return;
        phase = action;
        clearTimeout(timer);
        holdPending = pending.size > 0;
        notice = '';
        actionError = undefined;
        update();

        try {
            await inFlight;
            if (!alive()) return;
            if (uncertain(saveError)) {
                notice = 'Pastikan perubahan tersimpan sebelum membatalkan Draft.';
                return;
            }
            if (!await readDraft()) return;
            if (draft?.id !== target.id || (finalizing && keys.some((key) => draft.scores[key] !== target.scores[key]))) {
                notice ||= changed;
                await reloadResults();
                return;
            }

            let error;
            try {
                await request('/' + target.id + '/' + action, { method: 'POST', body: {} });
                draft = null;
                pending.clear();
                saveError = undefined;
                holdPending = false;
            } catch (failure) {
                error = failure;
            }
            if (!alive()) return;

            const [draftRead, history] = await Promise.all([readDraft(), reloadResults()]);
            if (!error || (uncertain(error) && history?.some((item) => item.id === target.id) && finalizing)) {
                notice = finalizing ? 'Penilaian berhasil difinalkan.' : 'Draft berhasil dibatalkan.';
            } else if (uncertain(error)) {
                notice = draftRead && draft?.id === target.id
                    ? 'Hasil aksi belum dapat dipastikan. Draft masih aktif. Tinjau sebelum mencoba lagi.'
                    : 'Hasil aksi belum dapat dipastikan. Periksa Draft dan histori sebelum melanjutkan.';
            } else {
                actionError = error;
                if (error.status === 409) notice = changed;
            }
        } finally {
            phase = null;
            update();
        }
    }

    return { snapshot, refresh, edit, retrySave, transition, stop };
}
