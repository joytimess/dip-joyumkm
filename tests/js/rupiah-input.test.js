import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatRupiahInput, parseRupiah } from '../../resources/js/ui.js';

test('rupiah input keeps digits only and formats thousands', () => {
    assert.equal(formatRupiahInput('dsd'), '');
    assert.equal(formatRupiahInput('kontol bego'), '');
    assert.equal(formatRupiahInput('50000000'), '50.000.000');
    assert.equal(formatRupiahInput('5a0.0b00'), '50.000');
    assert.equal(formatRupiahInput('-12,5'), '125');
    assert.equal(formatRupiahInput('0'), '0');
    assert.equal(formatRupiahInput('99999999999999999999'), '99999999999999999999');
});

test('parseRupiah reads formatted values back as integers', () => {
    assert.equal(parseRupiah(''), null);
    assert.equal(parseRupiah('50.000.000'), 50000000);
    assert.equal(parseRupiah('0'), 0);
});
