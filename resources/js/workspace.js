import { getToken, concealWorkspace, redirectToLogin } from './api';
import { confirmAction, showError } from './ui';

const pages = {
    home: () => import('./pages/home'),
    umkms: () => import('./pages/umkms'),
    'umkm-detail': () => import('./pages/umkm-detail'),
    'six-factor-evaluation': () => import('./pages/umkm-detail'),
    'form-data': () => import('./pages/form-data'),
    'factor-profile': () => import('./pages/factor-profile'),
    distribution: () => import('./pages/distribution'),
    comparison: () => import('./pages/comparison'),
};

export function initWorkspace(user, overlays) {
    const main = document.getElementById('main-content');
    const status = document.getElementById('navigation-status');
    const problem = document.getElementById('navigation-error');
    const token = getToken();
    let url = new URL(location.href);
    let position = history.state?.workspaceIndex ?? 0;
    let restoring = false;
    let leaving = false;
    let navigation;
    let page;
    const scroll = new Map();
    history.scrollRestoration = 'manual';
    history.replaceState({ workspaceIndex: position }, '', url);

    function validSession() {
        if (leaving) return false;
        if (token && getToken() === token) return true;
        concealWorkspace();
        if (getToken()) location.reload();
        else redirectToLogin();
        return false;
    }

    function unmount() {
        page?.abort();
        main.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
    }

    async function mount() {
        const scope = new AbortController();
        page = scope;
        main.inert = false;
        main.querySelectorAll('[data-user-name]').forEach((node) => { node.textContent = user.name; });
        try {
            const module = await pages[document.body.dataset.page]();
            if (!scope.signal.aborted && validSession()) await module.init(scope.signal);
        } catch (error) {
            if (!scope.signal.aborted && error.name !== 'AbortError') {
                showError(problem, { message: 'Halaman belum dapat disiapkan.' }, () => location.reload());
                problem.hidden = false;
            }
        }
    }

    // Halaman menolak pindah dengan preventDefault(); bila perlu konfirmasi, halaman menaruh pesannya di event.detail.confirmation.
    async function allowLeave() {
        const event = new CustomEvent('joyumkm:before-route-change', { cancelable: true, detail: { confirmation: null } });
        if (document.dispatchEvent(event)) return true;
        return event.detail.confirmation ? confirmAction(event.detail.confirmation) : false;
    }

    function closeOverlays(returnFocus = true) {
        for (const dialog of overlays) {
            if (!dialog.open) continue;
            if (!returnFocus) dialog._opener = null;
            dialog.close();
        }
    }

    async function navigate(target, nextPosition = null, retry = false, intent) {
        if (!validSession()) return;
        const traversal = nextPosition !== null;
        if (traversal && !retry && !(await allowLeave())) {
            if (traversal && nextPosition !== position) {
                restoring = true;
                history.go(position - nextPosition);
            }
            return;
        }
        navigation?.abort();
        if (target.href === url.href && !retry && !page.signal.aborted) {
            closeOverlays();
            main.inert = false;
            main.removeAttribute('aria-busy');
            status.textContent = '';
            problem.hidden = true;
            return;
        }
        const request = new AbortController();
        navigation = request;
        problem.hidden = true;
        main.setAttribute('aria-busy', 'true');
        status.textContent = 'Memuat halaman…';
        if (traversal && !retry) {
            scroll.set(position, window.scrollY);
            main.inert = true;
            unmount();
        }
        try {
            const signals = [request.signal, AbortSignal.timeout(15000)];
            if (intent) signals.push(intent);
            const response = await fetch(target, {
                cache: 'no-store', signal: AbortSignal.any(signals),
            });
            if (!response.ok || response.redirected) throw new Error('Page unavailable');
            const content = new DOMParser().parseFromString(await response.text(), 'text/html');
            const incoming = content.querySelector('body[data-protected] #main-content');
            const name = content.body.dataset.page;
            if (!incoming || !Object.hasOwn(pages, name)) throw new Error('Missing workspace content');
            if (request.signal.aborted || intent?.aborted || !validSession()) return;
            if (!traversal && !(await allowLeave())) return;
            if (request.signal.aborted || intent?.aborted || !validSession()) return;
            if (!traversal) {
                scroll.set(position, window.scrollY);
                nextPosition = (history.state?.workspaceIndex ?? position) + 1;
                scroll.delete(nextPosition);
                history.pushState({ workspaceIndex: nextPosition }, '', target);
            }
            unmount();
            position = nextPosition;
            url = target;
            main.replaceChildren(...incoming.childNodes);
            document.body.dataset.page = name;
            document.title = content.title;
            document.querySelectorAll('.workspace-navigation a').forEach((link) => {
                const active = link.dataset.workspacePage === incoming.dataset.navigationPage;
                link.classList.toggle('active', active);
                link.classList.toggle('is-active', active);
                if (active) link.setAttribute('aria-current', new URL(link.href).pathname === target.pathname ? 'page' : 'location');
                else link.removeAttribute('aria-current');
            });
            document.dispatchEvent(new Event('joyumkm:route-changed'));
            closeOverlays(false);
            void mount();
            const heading = main.querySelector('h1, #welcome-title') || main;
            heading.tabIndex = -1;
            heading.focus({ preventScroll: true });
            const anchor = target.hash && document.getElementById(target.hash.slice(1));
            if (anchor) anchor.scrollIntoView();
            else window.scrollTo(0, traversal ? scroll.get(position) || 0 : 0);
            status.textContent = document.title;
        } catch {
            if (request.signal.aborted || intent?.aborted || !validSession()) return;
            closeOverlays();
            showError(problem, { message: 'Halaman tujuan belum dapat dimuat.' }, () => navigate(target, traversal ? nextPosition : null, true));
            problem.hidden = false;
            problem.querySelector('button')?.focus();
        } finally {
            if (navigation === request && !request.signal.aborted) {
                main.removeAttribute('aria-busy');
                if (status.textContent === 'Memuat halaman…') status.textContent = '';
            }
        }
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey
            || event.shiftKey || event.altKey || (link.target && link.target !== '_self') || link.hasAttribute('download')) return;
        const target = new URL(link.href);
        if (target.origin !== location.origin || !/^\/(?:home|umkms(?:\/\d+(?:\/six-factor-evaluations)?)?|form-data|factor-profile|distribution|comparison)\/?$/.test(target.pathname)) return;
        if (target.hash && target.pathname === url.pathname && target.search === url.search) {
            const anchor = document.getElementById(target.hash.slice(1));
            if (!anchor) return;
            event.preventDefault();
            if (target.href !== url.href) {
                scroll.set(position, window.scrollY);
                history.pushState({ workspaceIndex: ++position }, '', target);
                url = target;
            }
            anchor.focus({ preventScroll: true });
            anchor.scrollIntoView();
            return;
        }
        event.preventDefault();
        void navigate(target);
    });

    window.addEventListener('popstate', (event) => {
        if (restoring) { restoring = false; return; }
        const target = new URL(location.href);
        if (target.pathname === url.pathname && target.search === url.search && target.hash !== url.hash) {
            closeOverlays();
            scroll.set(position, window.scrollY);
            position = event.state?.workspaceIndex ?? position;
            url = target;
            const anchor = target.hash && document.getElementById(target.hash.slice(1));
            if (anchor) anchor.scrollIntoView();
            else window.scrollTo(0, scroll.get(position) || 0);
            return;
        }
        void navigate(target, event.state?.workspaceIndex ?? position);
    });

    window.addEventListener('joyumkm:query-updated', () => {
        url = new URL(location.href);
        position = history.state.workspaceIndex;
    });

    document.addEventListener('joyumkm:session-ended', () => {
        leaving = true;
        navigation?.abort();
        unmount();
        closeOverlays(false);
        main.replaceChildren();
        document.querySelectorAll('[data-user-name], [data-user-initials], #toast-region').forEach((node) => node.replaceChildren());
    }, { once: true });

    void mount();
    return navigate;
}
