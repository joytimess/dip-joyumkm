import { setAnalystFilter, withAnalystFilter } from './role';

export const TOKEN_KEY = 'joyumkm.token';
export const USER_KEY = 'joyumkm.user';
let redirecting = false;

export class ApiError extends Error {
    constructor(message, status = 0, errors = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }
}

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function saveSession(data) {
    setAnalystFilter(null);
    localStorage.setItem(USER_KEY, JSON.stringify(data.user));
    localStorage.setItem(TOKEN_KEY, data.token);
}

export function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    setAnalystFilter(null);
    concealWorkspace();
}

export function concealWorkspace() {
    document.getElementById('app-shell')?.setAttribute('hidden', '');
    document.dispatchEvent(new Event('joyumkm:session-ended'));
}

export function redirectToLogin() {
    if (!redirecting) {
        redirecting = true;
        window.location.replace('/login');
    }
}

export async function api(path, { method = 'GET', body, signal, authenticated = true, redirectOnUnauthorized = true, expectedStatus, download = false } = {}) {
    signal?.throwIfAborted();
    const token = authenticated ? getToken() : null;
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (token) headers.Authorization = `Bearer ${token}`;
    let response;
    let payload;
    try {
        const timeout = AbortSignal.timeout(20000);
        response = await fetch(`/api${withAnalystFilter(path, method)}`, {
            method, headers, body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'omit', cache: 'no-store', signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
        });
        payload = download && response.ok ? await response.blob() : await response.json().catch((error) => {
            if (error.name === 'SyntaxError' && !response.ok) return {};
            throw error;
        });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        if (error.name === 'TimeoutError') throw new ApiError('Waktu tunggu habis. Periksa koneksi dan hasil permintaan sebelum mencoba lagi.');
        throw new ApiError('Tidak dapat terhubung. Periksa koneksi Anda, lalu coba lagi.');
    }
    signal?.throwIfAborted();
    if (authenticated && getToken() !== token) throw new DOMException('Sesi berubah.', 'AbortError');
    if (!response.ok) {
        if (response.status === 401 && authenticated) {
            clearSession();
            if (redirectOnUnauthorized) redirectToLogin();
        }
        const message = response.status === 403 ? 'Anda tidak memiliki akses ke bagian ini.'
            : response.status >= 500 ? 'Terjadi gangguan pada server. Silakan coba lagi.'
                : payload.message || 'Permintaan gagal. Silakan coba lagi.';
        const failure = new ApiError(message, response.status, payload.errors || {});
        failure.code = payload.code;
        throw failure;
    }
    if (expectedStatus !== undefined && response.status !== expectedStatus) {
        throw new ApiError('Respons server tidak sesuai. Periksa daftar UMKM sebelum mencoba lagi.', response.status);
    }
    return payload;
}

export function pageApi(pageSignal) {
    const token = getToken();
    return async (path, options = {}) => {
        pageSignal.throwIfAborted();
        if (!token || getToken() !== token) throw new DOMException('Sesi berubah.', 'AbortError');
        return api(path, {
            ...options,
            signal: options.signal ? AbortSignal.any([pageSignal, options.signal]) : pageSignal,
        });
    };
}
