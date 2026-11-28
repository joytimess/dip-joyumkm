/**
 * Peran dan filter portofolio. Otoritas peran selalu dari /auth/me (diset di body[data-role] setelah hidrasi);
 * server tetap memverifikasi setiap request. Filter Analyst hanya preferensi tampilan Superadmin.
 */
const FILTER_KEY = 'joyumkm.analyst_filter';
export const ROLE_LABELS = { superadmin: 'Superadmin', analyst: 'Analyst', user: 'Pendamping UMKM' };

export const currentRole = () => document.body.dataset.role || '';
export const isSuperadmin = () => currentRole() === 'superadmin';

export function getAnalystFilter() {
    const value = Number(sessionStorage.getItem(FILTER_KEY));
    return Number.isInteger(value) && value > 0 ? value : null;
}

export function setAnalystFilter(id) {
    if (id) sessionStorage.setItem(FILTER_KEY, String(id));
    else sessionStorage.removeItem(FILTER_KEY);
}

/** Menambahkan analyst_id pada pembacaan portofolio (daftar, ringkasan, dashboard), bukan detail per ID. */
export function withAnalystFilter(path, method = 'GET') {
    const id = getAnalystFilter();
    if (!id || method !== 'GET' || !/^\/(?:dashboard\/|umkms(?:$|\?|\/summary))/.test(path)) return path;
    return path + (path.includes('?') ? '&' : '?') + `analyst_id=${id}`;
}

export function applyRole(user) {
    document.body.dataset.role = user.role;
    document.querySelectorAll('.user-details small').forEach((node) => { node.textContent = ROLE_LABELS[user.role] || ROLE_LABELS.user; });
    document.querySelectorAll('[data-role-only]').forEach((node) => { node.hidden = node.dataset.roleOnly !== user.role; });
}

export function formatWib(iso) {
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(iso)) + ' WIB';
}
