import test from 'node:test';
import assert from 'node:assert/strict';
import { buildSummaryQuery, categoryTone, formatAverage, pageRange } from '../../resources/js/six-factor-dashboard-data.js';

test('summary query carries paging, filter, and sort and omits blank search', () => {
    const query = new URLSearchParams(buildSummaryQuery({ page: 2, perPage: 4, search: '  jaya  ', status: 'final', sort: 'score_desc' }));
    assert.equal(query.get('page'), '2');
    assert.equal(query.get('per_page'), '4');
    assert.equal(query.get('status'), 'final');
    assert.equal(query.get('sort'), 'score_desc');
    assert.equal(query.get('search'), 'jaya');

    const blank = new URLSearchParams(buildSummaryQuery({ page: 1, search: '   ' }));
    assert.equal(blank.has('search'), false);
    assert.equal(blank.get('status'), 'all');
    assert.equal(blank.get('sort'), 'name_asc');
});

test('page range reports the visible slice of the filtered total', () => {
    assert.deepEqual(pageRange(1, 4, 7), { from: 1, to: 4 });
    assert.deepEqual(pageRange(2, 4, 7), { from: 5, to: 7 });
    assert.deepEqual(pageRange(1, 4, 0), { from: 0, to: 0 });
});

test('average shows a dash when no Final exists and never shows NaN', () => {
    assert.equal(formatAverage(null), '—');
    assert.equal(formatAverage(22.8), '22,8 / 30');
});

test('category tone is not the only signal, and unknown categories use the warning tone', () => {
    assert.equal(categoryTone('Sangat Sehat'), 'pill-green');
    assert.equal(categoryTone('Sehat'), 'pill-green');
    assert.equal(categoryTone('Cukup Sehat'), 'pill-amber');
    assert.equal(categoryTone('Kurang Sehat'), 'pill-amber');
});
