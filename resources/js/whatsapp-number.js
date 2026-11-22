export function localWhatsappDigits(value) {
    const number = value.trim().replace(/[ -]/g, '');
    if (!/^(?:[0-9]*|\+628[0-9]*)$/.test(number)) return null;
    return number.replace(/^(?:0|\+?62)(?=8)/, '');
}

export function whatsappPayload(digits) {
    return /^8[0-9]{8,11}$/.test(digits) ? `62${digits}` : null;
}
