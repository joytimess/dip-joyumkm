import { api, TOKEN_KEY, USER_KEY, clearSession, concealWorkspace, getToken, redirectToLogin, saveSession } from './api';
import { renderErrors, clearErrors, setBusy, showError, toast } from './ui';

export function bindSessionEvents() {
    window.addEventListener('storage', (event) => {
        if (event.key !== TOKEN_KEY && event.key !== null) return;
        if (document.body.dataset.protected) {
            concealWorkspace();
            if (!getToken()) redirectToLogin();
            else window.location.reload();
        } else if (['login', 'register'].includes(document.body.dataset.page) && getToken()) {
            window.location.reload();
        }
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) window.location.reload();
    });
    window.addEventListener('pagehide', () => concealWorkspace());
}

export async function guardProtectedPage(retry) {
    if (!getToken()) { clearSession(); redirectToLogin(); return null; }
    const shell = document.getElementById('app-shell');
    const status = document.getElementById('auth-status');
    status.hidden = false;
    status.textContent = 'Memverifikasi sesi…';
    status.setAttribute('aria-busy', 'true');
    try {
        const { data: user } = await api('/auth/me');
        if (shell.hidden) return null;
        localStorage.setItem(USER_KEY, JSON.stringify(user));
        document.querySelectorAll('[data-user-name]').forEach((node) => { node.textContent = user.name; });
        const initials = user.name.trim().split(/\s+/u).slice(0, 2).map((word) => Array.from(word)[0]).join('').toLocaleUpperCase('id-ID');
        document.querySelectorAll('[data-user-initials]').forEach((node) => { node.textContent = initials; });
        status.hidden = true;
        return user;
    } catch (error) {
        if (error.name === 'AbortError' || error.status === 401) return null;
        const message = error.status === 403 ? error.message : 'Sesi belum dapat diverifikasi. Periksa koneksi Anda, lalu coba lagi.';
        showError(status, { message }, retry);
        return null;
    }
}

export function bindLogout() {
    const token = getToken();
    document.querySelectorAll('[data-logout]').forEach((button) => button.addEventListener('click', async () => {
        if (!token || getToken() !== token) return;
        setBusy(button, true, 'Keluar…');
        try {
            await api('/auth/logout', { method: 'POST' });
            if (getToken() !== token) return;
            clearSession();
            redirectToLogin();
        } catch (error) {
            if (error.status !== 401 && error.name !== 'AbortError') {
                window.dispatchEvent(new Event('joyumkm:logout-failed'));
                toast(error.message, 'error');
                setBusy(button, false);
            }
        }
    }));
}

export async function initAuthPage() {
    const form = document.getElementById('auth-form');
    bindAuthPresentation(form);
    const submit = form.querySelector('[type="submit"]');
    const status = document.getElementById('auth-status');
    if (getToken()) {
        submit.disabled = true;
        status.textContent = 'Memeriksa sesi Anda…';
        try {
            await api('/auth/me', { redirectOnUnauthorized: false });
            window.location.replace('/home');
            return;
        } catch (error) {
            if (error.status !== 401) {
                showError(status, error, () => window.location.reload());
                return;
            }
        }
    }
    status.hidden = true;
    submit.disabled = false;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
        clearErrors(form);
        const body = Object.fromEntries(new FormData(form));
        const errors = Object.fromEntries(Array.from(form.elements)
            .filter((field) => field instanceof HTMLInputElement && !field.validity.valid)
            .map((field) => [field.name, [field.validationMessage]]));
        if (form.dataset.authAction === 'register') {
            if (Array.from(body.password).length < 8) errors.password = ['Kata sandi minimal 8 karakter.'];
            if (new TextEncoder().encode(body.password).length > 72 || body.password.includes('\0')) {
                errors.password = ['Kata sandi maksimal 72 byte dan tidak boleh mengandung karakter NUL.'];
            }
            if (body.password_confirmation !== body.password) {
                errors.password_confirmation = ['Konfirmasi kata sandi belum sama.'];
            }
        }
        if (Object.keys(errors).length) {
            renderErrors(form, { message: 'Periksa kembali isian Anda.', errors });
            return;
        }
        setBusy(submit, true, form.dataset.authAction === 'register' ? 'Membuat akun…' : 'Memeriksa…');
        try {
            const { data } = await api(`/auth/${form.dataset.authAction}`, { method: 'POST', body, authenticated: false });
            saveSession(data);
            window.location.replace('/home');
        } catch (error) {
            renderErrors(form, error);
            if (!Object.keys(error.errors).length) form.querySelector('.form-message')?.focus();
            setBusy(submit, false);
        }
    });
}

function bindAuthPresentation(form) {
    form.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        button.addEventListener('click', () => {
            const showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(showing));
            button.setAttribute('aria-label', showing ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
            button.querySelector('use')?.setAttribute('href', showing ? '#joy-i-eye-off' : '#joy-i-eye');
        });
    });

    const strength = form.querySelector('[data-password-strength]');
    if (!strength) return;
    const password = form.elements.namedItem('password');
    const updateStrength = () => {
        const value = password.value;
        let level = Array.from(value).length >= 8 ? 1 : 0;
        if (level && (/[A-Z]/.test(value) && /[a-z]/.test(value) || /[a-zA-Z]/.test(value) && /\d/.test(value))) level = 2;
        if (level === 2 && Array.from(value).length >= 12 && /[^a-zA-Z\d]/.test(value)) level = 3;
        strength.dataset.level = String(level);
        strength.querySelectorAll('[data-strength-segment]').forEach((segment, index) => segment.classList.toggle('active', index < level));
        const label = form.querySelector('[data-strength-label]');
        label.textContent = !value ? 'Gunakan minimal 8 karakter, maksimal 72 byte.'
            : new TextEncoder().encode(value).length > 72 ? 'Terlalu panjang · maksimal 72 byte.'
            : ['Terlalu pendek · minimal 8 karakter.', 'Cukup · coba kombinasikan huruf dan angka.', 'Baik · gunakan kata sandi yang unik.', 'Lebih kuat · gunakan kata sandi berbeda untuk setiap layanan.'][level];
    };
    password.addEventListener('input', updateStrength);
    updateStrength();
}
