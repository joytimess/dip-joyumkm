import assert from 'node:assert/strict';
import test from 'node:test';
import { createSixFactorDraft, sixFactors } from '../../resources/js/six-factor-draft.js';

const pause = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));
const scores = (value) => Object.fromEntries(Object.keys(sixFactors).map((key) => [key, value]));
const draft = (id, value) => ({ id, scores: scores(value) });
const setup = (request, reloadResults = async () => []) => createSixFactorDraft({
    request, reloadResults, render: () => {}, debounce: 5,
});

test('queues newer revisions and null until the single active save resolves', async () => {
    const saves = [];
    let acknowledge;
    const state = setup(async (path, options) => {
        if (!options) return null;
        saves.push(options.body);
        if (saves.length === 1) return new Promise((resolve) => { acknowledge = resolve; });
        return { id: 1, scores: { ...scores(null), sumber_daya: 4 } };
    });
    await state.refresh();
    assert.equal(saves.length, 0);
    state.edit('sumber_daya', 2);
    state.edit('kepemimpinan', 3);
    await pause(15);
    state.edit('sumber_daya', 4);
    state.edit('kepemimpinan', null);
    await pause(15);
    assert.deepEqual(saves, [{ scores: { sumber_daya: 2, kepemimpinan: 3 } }]);
    assert.equal(state.snapshot().status, 'Menyimpan…');
    acknowledge({ id: 1, scores: { ...scores(null), sumber_daya: 2, kepemimpinan: 3 } });
    await pause(15);
    assert.deepEqual(saves[1], { scores: { sumber_daya: 4, kepemimpinan: null } });
    assert.deepEqual(state.snapshot().scores, { ...scores(null), sumber_daya: 4 });
    assert.equal(state.snapshot().status, 'Tersimpan');
});

test('holds unsaved values on failure and retries the same partial update', async () => {
    const bodies = [];
    const state = setup(async (path, options) => {
        if (!options) return null;
        bodies.push(options.body);
        if (bodies.length === 1) throw Object.assign(new Error('offline'), { status: 0 });
        return { id: 1, scores: { ...scores(null), kinerja: 5 } };
    });
    await state.refresh();
    state.edit('kinerja', 5);
    await pause(15);
    assert.equal(state.snapshot().status, 'Gagal menyimpan');
    assert.equal(state.snapshot().scores.kinerja, 5);
    assert.equal(state.snapshot().dirty, true);
    await state.retrySave();
    assert.deepEqual(bodies, [{ scores: { kinerja: 5 } }, { scores: { kinerja: 5 } }]);
    assert.equal(state.snapshot().dirty, false);
});

test('cancel waits for the current save and drops queued edits after the same ID is cancelled', async () => {
    let activeDraft = draft(7, 2);
    let acknowledge;
    const writes = [];
    const state = setup(async (path, options) => {
        if (!options) return activeDraft;
        writes.push([path, options]);
        if (options.method === 'PATCH') return new Promise((resolve) => { acknowledge = resolve; });
        activeDraft = null;
    });
    await state.refresh();
    const target = state.snapshot().draft;
    state.edit('kinerja', 3);
    await pause(15);
    state.edit('kinerja', 4);
    const cancelling = state.transition('cancel', target);
    await pause(15);
    assert.equal(writes.length, 1);
    acknowledge(activeDraft = { ...target, scores: { ...target.scores, kinerja: 3 } });
    await cancelling;
    await pause(15);
    assert.deepEqual(writes[1], ['/7/cancel', { method: 'POST', body: {} }]);
    assert.equal(writes.length, 2);
    assert.equal(state.snapshot().draft, null);
    assert.equal(state.snapshot().dirty, false);
});

test('final waits for acknowledgement and checks all stored scores again', async () => {
    let activeDraft = draft(1, 2);
    let acknowledge;
    const writes = [];
    const state = setup(async (path, options) => {
        if (!options) return activeDraft;
        writes.push(path);
        return new Promise((resolve) => { acknowledge = resolve; });
    });
    await state.refresh();
    state.edit('kinerja', 3);
    assert.equal(state.snapshot().canFinalize, false);
    await pause(15);
    assert.equal(state.snapshot().canFinalize, false);
    acknowledge(activeDraft = { id: 1, scores: { ...scores(2), kinerja: 3 } });
    await pause();
    assert.equal(state.snapshot().canFinalize, true);
    const target = state.snapshot().draft;
    activeDraft = draft(1, 4);
    await state.transition('finalize', target);
    assert.deepEqual(writes, ['/draft']);
    assert.deepEqual(state.snapshot().scores, scores(4));
});

test('reconciles a finalization that timed out after being applied', async () => {
    let activeDraft = draft(1, 3);
    const writes = [];
    const state = setup(async (path, options) => {
        if (!options) return activeDraft;
        writes.push([path, options.body]);
        activeDraft = null;
        throw Object.assign(new Error('timeout'), { status: 0 });
    }, async () => [{ id: 1 }]);
    await state.refresh();
    await state.transition('finalize', state.snapshot().draft);
    assert.deepEqual(writes, [['/1/finalize', {}]]);
    assert.equal(state.snapshot().draft, null);
    assert.equal(state.snapshot().notice, 'Penilaian berhasil difinalkan.');
});

test('focus during save refreshes the Draft and results before writing queued edits', async () => {
    let current = draft(8, 2);
    let acknowledge;
    let resultReads = 0;
    let saves = 0;
    const state = setup(async (path, options) => {
        if (!options) return current;
        saves++;
        return new Promise((resolve) => { acknowledge = resolve; });
    }, async () => { resultReads++; });
    await state.refresh();
    state.edit('kinerja', 4);
    await pause(15);
    state.edit('stabilitas', 5);
    await state.refresh(true);
    current = null;
    acknowledge(draft(8, 4));
    await pause(15);
    assert.equal(saves, 1);
    assert.equal(resultReads, 1);
    assert.equal(state.snapshot().draft, null);
});

test('cancel with a lost response leaves unsent edits on hold while its Draft is still active', async () => {
    const current = draft(9, 2);
    const writes = [];
    const state = setup(async (path, options) => {
        if (!options) return current;
        writes.push(path);
        throw Object.assign(new Error('offline'), { status: 0 });
    });
    await state.refresh();
    state.edit('stabilitas', null);
    await state.transition('cancel', current);
    await pause(15);
    assert.deepEqual(writes, ['/9/cancel']);
    assert.equal(state.snapshot().scores.stabilitas, null);
    assert.equal(state.snapshot().holdPending, true);
    assert.equal(state.snapshot().canCancel, true);
    assert.match(state.snapshot().notice, /belum dapat dipastikan/);
});
