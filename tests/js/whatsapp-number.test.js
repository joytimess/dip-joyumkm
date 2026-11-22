import assert from 'node:assert/strict';
import test from 'node:test';
import { localWhatsappDigits, whatsappPayload } from '../../resources/js/whatsapp-number.js';

test('nomor nasional dan paste Indonesia menghasilkan digit yang sama', () => {
    for (const value of ['81234567890', '081234567890', '6281234567890', '+6281234567890', '0812 3456-7890', '+62 812 3456-7890']) {
        assert.equal(localWhatsappDigits(value), '81234567890');
        assert.equal(whatsappPayload(localWhatsappDigits(value)), '6281234567890');
    }
});

test('karakter asing dan format di luar validasi Laravel ditolak', () => {
    for (const value of ['abc81234567890', '+6281234567890x', '+6581234567890', '8+1234567890']) {
        assert.equal(localWhatsappDigits(value), null);
    }
    for (const value of ['', '81234', '91234567890', '8123456789012']) assert.equal(whatsappPayload(value), null);
    assert.equal(whatsappPayload('812345678'), '62812345678');
    assert.equal(whatsappPayload('812345678901'), '62812345678901');
});
