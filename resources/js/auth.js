import { api, TOKEN_KEY, USER_KEY, clearSession, getToken, redirectToLogin, saveSession } from './api';
import { renderErrors, clearErrors, setBusy, showError, toast } from './ui';

export function bindSessionEvents() {
    window.addEventListener('storage', (event) => {
        if (event.key !== TOKEN_KEY && event.key !== null) return;
        if (document.body.dataset.protected) {
            document.getElementById('app-shell').hidden = true;
            if (!getToken()) redirectToLogin();
            else window.location.reload();
        } else if (['login', 'register'].includes(document.body.dataset.page) && getToken()) {
            window.location.reload();
        }
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) window.location.reload();
    });
}

export async function guardProtectedPage() {
    if (!getToken()) { clearSession(); redirectToLogin(); return null; }
    const status = document.getElementById('auth-status');
    try {
        const { data: user } = await api('/auth/me');
        localStorage.setItem(USER_KEY, JSON.stringify(user));
        document.querySelectorAll('[data-user-name]').forEach((node) => { node.textContent = user.name; });
        const initials = user.name.trim().split(/\s+/u).slice(0, 2).map((word) => Array.from(word)[0]).join('').toLocaleUpperCase('id-ID');
        document.querySelectorAll('[data-user-initials]').forEach((node) => { node.textContent = initials; });
        status.hidden = true;
        document.getElementById('app-shell').hidden = false;
        return user;
    } catch (error) {
        if (error.status !== 401) {
            showError(status, error, () => window.location.reload());
        }
        return null;
    }
}

export function bindLogout() {
    document.querySelectorAll('[data-logout]').forEach((button) => button.addEventListener('click', async () => {
        setBusy(button, true, 'Keluar…');
        try {
            await api('/auth/logout', { method: 'POST' });
            clearSession();
            redirectToLogin();
        } catch (error) {
            if (error.status !== 401) {
                window.dispatchEvent(new Event('joyumkm:logout-failed'));
                toast(error.message, 'error');
                setBusy(button, false);
            }
        }
    }));
}

export async function initAuthPage() {
    const form = document.getElementById('auth-form');
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
