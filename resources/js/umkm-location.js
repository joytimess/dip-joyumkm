import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import pin from 'leaflet/dist/images/marker-icon.png';
import pinRetina from 'leaflet/dist/images/marker-icon-2x.png';
import pinShadow from 'leaflet/dist/images/marker-shadow.png';
import { element, setBusy } from './ui';

const mapCenter = [-2.4, 118];
const addressFields = ['province', 'city', 'address', 'district', 'subdistrict', 'postal_code'];
const addressFallback = 'Alamat belum dapat diisi otomatis. Lengkapi detail alamat di bawah.';
const tileFallback = 'Peta belum dapat dimuat. Coba lagi untuk menampilkan peta.';
const markerIcon = L.icon({
    iconUrl: pin, iconRetinaUrl: pinRetina, shadowUrl: pinShadow,
    iconSize: [25, 41], iconAnchor: [12, 41], shadowSize: [41, 41], shadowAnchor: [12, 41],
});

export function bindUmkmLocation({ form, api, signal, syncProvince, onPick }) {
    const panel = form.querySelector('#umkm-location');
    const canvas = panel.querySelector('#umkm-map');
    const searchInput = panel.querySelector('input');
    const searchButton = panel.querySelector('[data-location-search]');
    const centerButton = panel.querySelector('[data-location-center]');
    const retryButton = panel.querySelector('[data-location-retry]');
    const searchStatus = panel.querySelector('[data-search-status]');
    const mapStatus = panel.querySelector('[data-map-status]');
    const pinStatus = panel.querySelector('.umkm-pin-status');
    const results = panel.querySelector('.umkm-location-results');
    const edited = new Set();
    let map;
    let marker;
    let tiles;
    let tilesReady = false;
    let tileTimeout;
    let searchController;
    let reverseController;

    function showTileStatus(message, ready = false, retry = false) {
        tilesReady = ready;
        centerButton.disabled = !ready;
        retryButton.hidden = !retry;
        mapStatus.textContent = message;
    }

    function loadTiles() {
        showTileStatus('Memuat peta…');
        clearTimeout(tileTimeout);
        tileTimeout = setTimeout(() => showTileStatus(tileFallback, false, true), 8000);
    }

    function showPinStatus(label, note) {
        pinStatus.replaceChildren(...[
            element('p', { text: `Titik usaha terpilih (${form.elements.latitude.value}, ${form.elements.longitude.value})` }),
            label ? element('p', { text: label }) : null,
            note ? element('p', { text: note }) : null,
        ].filter(Boolean));
    }

    async function choosePoint(point, label = '') {
        if (panel.hidden) return;
        const { lat, lng } = point.wrap();
        form.elements.latitude.value = lat.toFixed(7);
        form.elements.longitude.value = lng.toFixed(7);
        if (!marker) {
            marker = L.marker([lat, lng], {
                draggable: true, icon: markerIcon, alt: 'Titik usaha terpilih',
            }).addTo(map);
            marker.on('dragend', () => void choosePoint(marker.getLatLng()));
        } else {
            marker.setLatLng([lat, lng]);
        }
        if (label) map.setView([lat, lng], Math.max(16, map.getZoom()), { animate: false });

        reverseController?.abort();
        const controller = new AbortController();
        reverseController = controller;
        for (const field of addressFields) {
            if (!edited.has(field)) form.elements[field].value = '';
        }
        syncProvince();
        onPick();
        showPinStatus(label, 'Mengisi detail alamat…');

        let address;
        try {
            ({ data: address } = await api('/locations/reverse', {
                method: 'POST', body: { lat, lon: lng },
                signal: AbortSignal.any([controller.signal, AbortSignal.timeout(6000)]),
            }));
        } catch {
            address = null;
        }
        if (controller.signal.aborted || signal.aborted) return;

        for (const field of addressFields) {
            if (!edited.has(field) && typeof address?.[field] === 'string') form.elements[field].value = address[field];
        }
        syncProvince();
        showPinStatus(
            address?.address?.split(',').slice(0, 2).join(',') || label,
            address?.address ? '' : addressFallback,
        );
    }

    function show() {
        requestAnimationFrame(() => {
            if (panel.hidden || !form.closest('dialog').open || signal.aborted) return;
            if (map) {
                map.invalidateSize({ pan: false });
                return;
            }
            map = L.map(canvas, {
                zoomControl: false, attributionControl: false, scrollWheelZoom: false, zoomAnimation: false, maxZoom: 19,
            }).setView(mapCenter, 5);
            L.control.zoom({ zoomInTitle: 'Perbesar peta', zoomOutTitle: 'Perkecil peta' }).addTo(map);
            map.on('click', (event) => { if (tilesReady) void choosePoint(event.latlng); });
            loadTiles();
            tiles = L.tileLayer(canvas.dataset.tileUrl, {
                maxZoom: 19, referrerPolicy: 'strict-origin-when-cross-origin',
            });
            tiles.on('loading', loadTiles);
            tiles.on('tileload', () => {
                clearTimeout(tileTimeout);
                showTileStatus('', true);
            });
            tiles.on('load', () => {
                clearTimeout(tileTimeout);
                if (!tilesReady) showTileStatus(tileFallback, false, true);
            });
            tiles.addTo(map);
        });
    }

    function clearResults() {
        results.hidden = true;
        results.replaceChildren();
        searchStatus.textContent = '';
    }

    async function search() {
        const query = searchInput.value.trim().replace(/\s+/g, ' ');
        clearResults();
        if (query.length < 3) {
            searchStatus.textContent = 'Masukkan minimal 3 karakter untuk mencari lokasi.';
            searchInput.focus();
            return;
        }
        searchController?.abort();
        const controller = new AbortController();
        searchController = controller;
        setBusy(searchButton, true, 'Mencari…');
        try {
            const { data } = await api('/locations/search', {
                method: 'POST', body: { q: query },
                signal: AbortSignal.any([controller.signal, AbortSignal.timeout(6000)]),
            });
            if (controller.signal.aborted || signal.aborted) return;
            if (!data.length) {
                searchStatus.textContent = 'Lokasi tidak ditemukan. Pilih titik langsung di peta.';
                return;
            }
            for (const match of data) {
                results.append(element('li', {}, element('button', {
                    type: 'button', class: 'button button-ghost', text: match.label,
                    onclick: () => {
                        if (!tilesReady) {
                            searchStatus.textContent = 'Tunggu peta dimuat sebelum memilih hasil.';
                            return;
                        }
                        void choosePoint(L.latLng(match.latitude, match.longitude), match.label);
                        clearResults();
                        canvas.focus();
                    },
                })));
            }
            results.hidden = false;
            searchStatus.textContent = 'Pilih salah satu hasil pencarian.';
        } catch {
            if (!controller.signal.aborted && !signal.aborted) {
                searchStatus.textContent = 'Pencarian gagal. Coba lagi atau pilih titik langsung di peta.';
            }
        } finally {
            if (searchController === controller && !signal.aborted) setBusy(searchButton, false);
        }
    }

    function reset() {
        searchController?.abort();
        reverseController?.abort();
        clearTimeout(tileTimeout);
        map?.remove();
        map = marker = tiles = null;
        edited.clear();
        clearResults();
        showTileStatus('');
        pinStatus.replaceChildren();
        searchInput.value = '';
        setBusy(searchButton, false);
    }

    function trackEdit({ target }) {
        if (!panel.hidden && addressFields.includes(target.name)) edited.add(target.name);
    }

    form.addEventListener('input', trackEdit, { signal });
    form.addEventListener('change', trackEdit, { signal });
    searchButton.addEventListener('click', search, { signal });
    searchInput.addEventListener('input', () => {
        searchController?.abort();
        clearResults();
    }, { signal });
    searchInput.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        if (!searchButton.disabled && !event.isComposing) void search();
    }, { signal });
    centerButton.addEventListener('click', () => void choosePoint(map.getCenter()), { signal });
    retryButton.addEventListener('click', () => {
        loadTiles();
        tiles?.redraw();
        canvas.focus();
    }, { signal });
    signal.addEventListener('abort', reset, { once: true });

    return { show, reset, hasPoint: () => Boolean(marker) };
}
