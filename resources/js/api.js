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
    localStorage.setItem(USER_KEY, JSON.stringify(data.user));
    localStorage.setItem(TOKEN_KEY, data.token);
}

export function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    document.getElementById('app-shell')?.setAttribute('hidden', '');
}

export function redirectToLogin() {
    if (!redirecting) {
        redirecting = true;
        window.location.replace('/login');
    }
}

export async function api(path, { method = 'GET', body, signal, authenticated = true, redirectOnUnauthorized = true } = {}) {
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (authenticated && getToken()) headers.Authorization = `Bearer ${getToken()}`;
    let response;
    let payload;
    try {
        const timeout = AbortSignal.timeout(20000);
        response = await fetch(`/api${path}`, {
            method, headers, body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'omit', cache: 'no-store', signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
        });
        payload = await response.json();
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        if (error.name === 'TimeoutError') throw new ApiError('Waktu tunggu habis. Periksa koneksi dan hasil permintaan sebelum mencoba lagi.');
        throw new ApiError('Tidak dapat terhubung. Periksa koneksi Anda, lalu coba lagi.');
    }
    if (!response.ok) {
        if (response.status === 401 && authenticated) {
            clearSession();
            if (redirectOnUnauthorized) redirectToLogin();
        }
        throw new ApiError(payload.message || 'Permintaan gagal. Silakan coba lagi.', response.status, payload.errors || {});
    }
    return payload;
}
