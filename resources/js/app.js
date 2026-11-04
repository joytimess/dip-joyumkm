import { bindSessionEvents, guardProtectedPage, bindLogout, initAuthPage } from './auth';
import { bindDialog, openDialog, toast } from './ui';
import { initPublicUI } from './public';

bindSessionEvents();
if (document.body.classList.contains('public-body')) initPublicUI();
const page = document.body.dataset.page;

if (['login', 'register'].includes(page)) {
    initAuthPage();
} else if (document.body.dataset.protected) {
    guardProtectedPage().then((user) => {
        if (!user) return;
        bindLogout();
        const drawer = document.getElementById('navigation-drawer');
        const menu = document.getElementById('menu-button');
        bindDialog(drawer);
        menu.addEventListener('click', () => {
            openDialog(drawer, menu);
            menu.setAttribute('aria-expanded', 'true');
        });
        drawer.addEventListener('close', () => menu.setAttribute('aria-expanded', 'false'));
        document.querySelectorAll('[data-notification-btn]').forEach((btn) => {
            btn.addEventListener('click', () => toast('Tidak ada notifikasi baru saat ini.'));
        });
        document.querySelectorAll('[data-profile-btn]').forEach((btn) => {
            btn.addEventListener('click', () => toast('Halaman profil pengguna belum terhubung pada versi ini.'));
        });
        document.querySelectorAll('[data-help-btn]').forEach((btn) => {
            btn.addEventListener('click', () => toast('Pusat bantuan JoyUMKM dapat diakses melalui tim pendamping.'));
        });
        const searchForm = document.getElementById('global-search-form');
        const search = document.getElementById('global-search');
        search.value = new URLSearchParams(window.location.search).get('search') || '';
        let searchTimer;
        const runSearch = () => {
            if (page === 'umkms') {
                window.dispatchEvent(new CustomEvent('joyumkm:search', { detail: search.value.trim() }));
            } else {
                window.location.assign(`/umkms?${new URLSearchParams({ search: search.value.trim() })}`);
            }
        };
        search.addEventListener('input', () => { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(runSearch, 300); });
        searchForm.addEventListener('submit', (event) => { event.preventDefault(); window.clearTimeout(searchTimer); runSearch(); });
        if (page === 'umkms') import('./pages/umkms').then((module) => module.init());
        if (page === 'home') import('./pages/home').then((module) => module.init());
        if (page === 'form-data') import('./pages/form-data').then((module) => module.init());
        if (page === 'factor-profile') import('./pages/factor-profile').then((module) => module.init());
        if (page === 'distribution') import('./pages/distribution').then((module) => module.init());
        if (page === 'comparison') import('./pages/comparison').then((module) => module.init());
    });
}
