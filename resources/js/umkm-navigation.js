export function withListContext(path, list = new URLSearchParams(location.search).get('list')) {
    const url = new URL(path, location.origin);
    if (list) url.searchParams.set('list', list);
    return url.pathname + url.search;
}

export function syncUmkmBreadcrumb(umkm) {
    const breadcrumb = document.querySelector('[data-context-breadcrumb]');
    const fallback = document.querySelector('[data-unselected-umkm]');
    breadcrumb.hidden = !umkm;
    if (fallback) fallback.hidden = Boolean(umkm);
    if (umkm) {
        const detail = breadcrumb.querySelector('[data-context-umkm]');
        detail.href = withListContext('/umkms/' + umkm.id);
        detail.textContent = umkm.name;
    }
}
