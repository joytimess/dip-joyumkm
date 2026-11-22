export const whatsappNumberError = 'Masukkan nomor HP yang valid, misalnya 81234567890.';

export function localWhatsappDigits(value) {
    const number = value.trim().replace(/[ -]/g, '');
    if (!/^(?:[0-9]*|\+628[0-9]*)$/.test(number)) return null;
    return number.replace(/^(?:0|\+?62)(?=8)/, '');
}

export function whatsappPayload(digits) {
    return /^8[0-9]{8,11}$/.test(digits) ? `62${digits}` : null;
}

export function bindWhatsappInput(input, onChange) {
    input.addEventListener('input', () => {
        const raw = input.value;
        const normalized = localWhatsappDigits(raw);
        const value = normalized ?? localWhatsappDigits(raw.replace(/\D/g, ''));
        if (value !== raw) {
            const fromEnd = raw.slice(input.selectionStart ?? raw.length).replace(/\D/g, '').length;
            const caret = Math.max(0, value.length - fromEnd);
            input.value = value;
            input.setSelectionRange(caret, caret);
        }
        input.setCustomValidity(normalized === null ? whatsappNumberError : '');
        onChange();
    });
    input.addEventListener('paste', (event) => {
        const pasted = event.clipboardData?.getData('text');
        if (!pasted) return;
        event.preventDefault();
        input.setRangeText(localWhatsappDigits(pasted) ?? pasted, input.selectionStart, input.selectionEnd, 'end');
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}
