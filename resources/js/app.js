import { bindSessionEvents, guardProtectedPage, bindLogout, initAuthPage } from './auth';
import { bindDialog, openDialog } from './ui';
import { initPublicUI } from './public';
import { initWorkspace } from './workspace';

bindSessionEvents();
if (document.body.classList.contains('public-body')) initPublicUI();
const page = document.body.dataset.page;

if (['login', 'register'].includes(page)) {
    initAuthPage();
} else if (document.body.dataset.protected) {
    const start = async () => {
        const user = await guardProtectedPage(start);
        if (!user) return;
        bindLogout();
        const menu = document.getElementById('menu-button');
        const drawer = document.getElementById('navigation-drawer');
        const searchButton = document.getElementById('search-button');
        const searchDialog = document.getElementById('search-dialog');
        const searchForm = document.getElementById('global-search-form');
        const search = document.getElementById('global-search');
        const compact = matchMedia('(max-width: 1050px)');
        const syncSearch = () => { search.value = new URLSearchParams(location.search).get('search') || ''; };
        function setupOverlay(dialog, button, input) {
            bindDialog(dialog);
            button.addEventListener('click', () => {
                openDialog(dialog, button);
                input?.focus();
                button.setAttribute('aria-expanded', 'true');
            });
            dialog.addEventListener('close', () => button.setAttribute('aria-expanded', String(dialog.open)));
        }
        setupOverlay(drawer, menu);
        setupOverlay(searchDialog, searchButton, search);
        const navigate = initWorkspace(user, [drawer, searchDialog]);
        let searchTimer;
        let pendingSearch;
        const cancelPendingSearch = () => {
            window.clearTimeout(searchTimer);
            pendingSearch?.abort();
        };
        const applyLayout = () => {
            window.clearTimeout(searchTimer);
            if (!compact.matches) {
                if (drawer.open) drawer.close();
                if (searchDialog.open) searchDialog.close();
            }
            document.getElementById(compact.matches ? 'dialog-search' : 'inline-search').append(searchForm);
        };
        compact.addEventListener('change', applyLayout);
        applyLayout();
        syncSearch();
        searchForm.inert = false;
        const runSearch = () => {
            const url = new URL(searchForm.action);
            const term = search.value.trim();
            const inline = location.pathname.replace(/\/$/, '') === url.pathname;
            if (inline && !document.dispatchEvent(new CustomEvent('joyumkm:search', { detail: term, cancelable: true }))) {
                if (searchDialog.open) searchDialog.close();
                syncSearch();
                return;
            }
            if (inline) url.search = location.search;
            url.searchParams.delete('page');
            if (term) url.searchParams.set('search', term);
            else url.searchParams.delete('search');
            if (searchDialog.open && url.href === location.href) searchDialog.close();
            pendingSearch = new AbortController();
            void navigate(url, null, false, pendingSearch.signal);
        };
        search.addEventListener('input', () => {
            cancelPendingSearch();
            if (!compact.matches) searchTimer = window.setTimeout(runSearch, 300);
        });
        searchForm.addEventListener('submit', (event) => { event.preventDefault(); cancelPendingSearch(); runSearch(); });
        searchDialog.addEventListener('close', () => { if (!searchDialog.open) syncSearch(); });
        document.addEventListener('joyumkm:session-ended', cancelPendingSearch, { once: true });
        document.addEventListener('joyumkm:route-changed', () => { window.clearTimeout(searchTimer); syncSearch(); });
    };
    void start();
}
