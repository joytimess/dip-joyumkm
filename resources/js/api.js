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

export async function api(path, { method = 'GET', body, signal, authenticated = true, redirectOnUnauthorized = true } = {}) {
    signal?.throwIfAborted();
    const token = authenticated ? getToken() : null;
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (token) headers.Authorization = `Bearer ${token}`;
    let response;
    let payload;
    try {
        const timeout = AbortSignal.timeout(20000);
        response = await fetch(`/api${path}`, {
            method, headers, body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'omit', cache: 'no-store', signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
        });
        payload = await response.json().catch((error) => {
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
        throw new ApiError(message, response.status, payload.errors || {});
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
