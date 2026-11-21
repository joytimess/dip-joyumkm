import test from 'node:test';
import assert from 'node:assert/strict';
import { draftProgress } from '../../resources/js/six-factor-dashboard-data.js';

test('draft progress counts only selected scores', () => {
    assert.equal(draftProgress({ sumber_daya: 1, kepemimpinan: 5, kinerja: null }), 2);
    assert.equal(draftProgress({}), 0);
});
