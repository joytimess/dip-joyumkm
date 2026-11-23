import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { join } from 'node:path';

assert.equal(process.env.JOYUMKM_BROWSER_DB, 'joyumkm_testing');
const { chromium } = createRequire(import.meta.url)(process.env.JOYUMKM_PLAYWRIGHT || 'playwright-core');
const origin = 'http://127.0.0.1:8134';
const executablePath = process.env.JOYUMKM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const baseline = process.argv.includes('--before');
const liveTiles = process.argv.includes('--live-tiles');
const requestedWidth = process.argv.find((arg) => arg.startsWith('--width='))?.split('=')[1];
const evidence = process.env.JOYUMKM_WIZARD_EVIDENCE;
if (evidence) await mkdir(evidence, { recursive: true });

const browser = await chromium.launch({ executablePath });
const api = await browser.newContext();
const password = 'Wizard-local-fixture-only';
const errors = [];
const account = await api.request.post(origin + '/api/auth/register', {
    headers: { Accept: 'application/json' },
    data: {
        name: 'Pendamping Uji Wizard', email: `joyumkm-wizard-${randomUUID()}@example.com`,
        password, password_confirmation: password,
    },
});
assert.equal(account.status(), 201);
const { data: session } = await account.json();
const headers = { Accept: 'application/json', Authorization: `Bearer ${session.token}` };
const listUrl = '/umkms?sort=name&direction=asc&per_page=5';
const tilePattern = 'https://tile.openstreetmap.org/**';
const tileFixture = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jkioAAAAASUVORK5CYII=', 'base64');
const addressFixture = {
    province: 'DKI Jakarta', city: 'Jakarta Selatan', address: 'Cipedak Raya, Jakarta Selatan',
    district: 'Jagakarsa', subdistrict: 'Cipedak', postal_code: '12630',
};

async function pickLocation(page, wizard, width, choose, capture, next) {
    const map = wizard.locator('#umkm-map');
    const latitude = wizard.locator('#umkm-latitude');
    const longitude = wizard.locator('#umkm-longitude');
    const address = wizard.locator('#umkm-address');
    const city = wizard.locator('#umkm-city');
    const center = wizard.locator('[data-location-center]');
    const retry = wizard.locator('[data-location-retry]');
    const pin = map.locator('.leaflet-marker-icon');
    const geo = wizard.locator('.umkm-pin-status');
    const search = wizard.locator('#umkm-location-search');
    const searchButton = wizard.locator('[data-location-search]');
    let tileFailure = width === 320;
    await page.route(tilePattern, (route) => tileFailure
        ? route.abort()
        : route.fulfill({ contentType: 'image/png', body: tileFixture }));

    let searches = 0;
    await page.route('**/api/locations/search', async (route) => {
        searches++;
        const { q } = route.request().postDataJSON();
        assert.deepEqual(Object.keys(route.request().postDataJSON()), ['q']);
        if (q === 'Jalan belum terdaftar') return route.fulfill({ json: { data: [] } });
        if (q === 'Pencarian gagal') return route.fulfill({ status: 503, json: {} });
        await route.fulfill({ json: { data: [
            { latitude: -6.341, longitude: 106.805, label: 'Cipedak Raya, Jakarta Selatan' },
            { latitude: -6.344, longitude: 106.81, label: 'Cipedak, Jagakarsa' },
        ] } });
    });
    let reverses = 0;
    let releaseOld;
    const oldGate = new Promise((resolve) => { releaseOld = resolve; });
    let oldFinished;
    const oldDone = new Promise((resolve) => { oldFinished = resolve; });
    let releaseCurrent;
    const currentGate = new Promise((resolve) => { releaseCurrent = resolve; });
    await page.route('**/api/locations/reverse', async (route) => {
        reverses++;
        assert.deepEqual(Object.keys(route.request().postDataJSON()), ['lat', 'lon']);
        if (width === 390) return route.fulfill({ status: 503, json: {} });
        if (width === 1440) {
            if (reverses === 1) {
                await oldGate;
                await route.fulfill({ json: { data: { city: 'Hasil lama', address: 'Alamat lama' } } }).catch(() => {});
                oldFinished();
                return;
            }
            await currentGate;
        }
        await route.fulfill({ json: { data: addressFixture } });
    });

    return async () => {
        await map.locator('.leaflet-tile-pane').waitFor({ state: 'attached' });
        assert.ok(await wizard.getByRole('link', { name: 'OpenStreetMap' }).isVisible());
        assert.ok(await latitude.getAttribute('readonly') !== null);
        assert.ok(await longitude.getAttribute('readonly') !== null);
        if (width === 320) {
            await retry.waitFor();
            assert.match(await wizard.locator('[data-map-status]').innerText(), /belum dapat dimuat/);
            assert.equal(await latitude.inputValue(), '');
            assert.equal(await pin.count(), 0);
            await capture('tile-failed');
            tileFailure = false;
            await retry.click();
        }
        await wizard.locator('[data-location-center]:enabled').waitFor();
        if (width === 390) {
            await choose('umkm-province', 'DKI Jakarta');
            await city.fill('Jakarta Selatan');
            await address.fill('Alamat manual setelah pin');
        }
        await next.click();
        assert.match(await wizard.locator('#umkm-error-latitude').innerText(), /Pilih titik lokasi usaha pada peta terlebih dahulu/);
        assert.ok(await map.evaluate((node) => node === document.activeElement));
        assert.equal(await pin.count(), 0);

        if (width === 390) {
            await search.fill('Jalan belum terdaftar');
            assert.equal(searches, 0);
            await search.press('Enter');
            await wizard.locator('[data-search-status]').filter({ hasText: 'Lokasi tidak ditemukan' }).waitFor();
            await search.fill('Pencarian gagal');
            await searchButton.click();
            await wizard.locator('[data-search-status]').filter({ hasText: 'Pencarian gagal' }).waitFor();
            await map.tap({ position: { x: 130, y: 120 } });
            await geo.filter({ hasText: 'Alamat belum dapat diisi otomatis' }).waitFor();
            assert.equal(await address.inputValue(), 'Alamat manual setelah pin');
            const beforeDrag = await latitude.inputValue();
            const { x, y, width: markerWidth, height } = await pin.boundingBox();
            const point = { x: x + markerWidth / 2, y: y + height / 2 };
            const client = await page.context().newCDPSession(page);
            const reverse = page.waitForResponse('**/api/locations/reverse');
            await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [point] });
            await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: point.x + 25, y: point.y + 18 }] });
            await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
            await reverse;
            await client.detach();
            assert.notEqual(await latitude.inputValue(), beforeDrag);
            assert.equal(await address.inputValue(), 'Alamat manual setelah pin');
        } else if (width === 1440) {
            const pane = map.locator('.leaflet-map-pane');
            const beforePan = await pane.evaluate((node) => node.style.transform);
            await map.press('ArrowRight');
            await page.waitForFunction((before) => document.querySelector('.leaflet-map-pane').style.transform !== before, beforePan);
            await map.getByRole('button', { name: 'Perbesar peta' }).click();
            await wizard.locator('[data-location-center]:enabled').waitFor();
            await search.fill('Cipedak Raya, Jakarta Selatan');
            assert.equal(searches, 0);
            await searchButton.click();
            const results = wizard.getByRole('list', { name: 'Hasil pencarian lokasi' });
            await results.waitFor();
            assert.equal(await results.getByRole('button').count(), 2);
            assert.equal(await pin.count(), 0);
            const oldSent = page.waitForRequest('**/api/locations/reverse');
            await results.getByRole('button', { name: 'Cipedak Raya, Jakarta Selatan', exact: true }).click();
            await oldSent;
            const beforeDrag = await latitude.inputValue();
            assert.equal(beforeDrag, '-6.3410000');
            await map.scrollIntoViewIfNeeded();
            await pin.hover();
            const { x, y, width: markerWidth, height } = await pin.boundingBox();
            assert.ok(await pin.evaluate((image) => {
                const bounds = image.getBoundingClientRect();
                return document.elementFromPoint(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2) === image;
            }), 'Pusat marker harus menerima pointer sebelum drag');
            const currentSent = page.waitForRequest('**/api/locations/reverse');
            await page.mouse.move(x + markerWidth / 2, y + height / 2);
            await page.mouse.down();
            await page.mouse.move(x + markerWidth / 2 + 36, y + height / 2 + 21, { steps: 8 });
            await page.mouse.up();
            await currentSent;
            assert.notEqual(await latitude.inputValue(), beforeDrag);
            await address.fill('Koreksi alamat saat reverse berjalan');
            releaseCurrent();
            await wizard.locator('#umkm-city').evaluate((node) => node.scrollIntoView({ block: 'nearest' }));
            await page.waitForFunction(() => document.querySelector('#umkm-city').value === 'Jakarta Selatan');
            releaseOld();
            await oldDone;
            assert.equal(await city.inputValue(), 'Jakarta Selatan');
            assert.equal(await address.inputValue(), 'Koreksi alamat saat reverse berjalan');
            assert.equal(searches, 1);
            assert.equal(reverses, 2);
        } else {
            await center.press('Enter');
            await page.waitForFunction(() => document.querySelector('#umkm-city').value === 'Jakarta Selatan');
        }
        assert.equal(await pin.count(), 1);
        assert.ok(await latitude.inputValue());
        assert.ok(await longitude.inputValue());
        if (width !== 390) {
            assert.equal(await wizard.locator('#umkm-province').inputValue(), 'DKI Jakarta');
            assert.equal(await wizard.locator('#umkm-district').inputValue(), 'Jagakarsa');
            assert.equal(await wizard.locator('#umkm-subdistrict').inputValue(), 'Cipedak');
            assert.equal(await wizard.locator('#umkm-postal-code').inputValue(), '12630');
        }
        await map.scrollIntoViewIfNeeded();
        await capture('B-interaction');
        return [await latitude.inputValue(), await longitude.inputValue()];
    };
}

async function pasteNumber(input, text) {
    await input.evaluate((node, value) => {
        node.focus();
        node.select();
        const data = new DataTransfer();
        data.setData('text/plain', value);
        node.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    }, text);
}

async function exerciseLiveTiles(page, wizard) {
    await wizard.locator('#umkm-name').fill('Usaha Uji Peta OSM');
    await wizard.locator('#umkm-sector-trigger').click();
    await wizard.locator('#umkm-sector-panel li button').nth(1).click();
    await wizard.locator('#umkm-omzet').fill('12000000');
    await wizard.locator('#umkm-business-capital').fill('50000000');
    await wizard.locator('#umkm-omzet-period-trigger').click();
    await wizard.locator('#umkm-omzet-period-panel').getByRole('button', { name: 'Bulanan', exact: true }).click();
    await wizard.locator('#umkm-step-next').click();
    const map = wizard.locator('#umkm-map');
    await page.waitForFunction(() => [...document.querySelectorAll('#umkm-map .leaflet-tile-loaded')]
        .some((image) => image.naturalWidth >= 256 && new URL(image.src).hostname === 'tile.openstreetmap.org'), null, { timeout: 12000 });
    await map.scrollIntoViewIfNeeded();
    assert.ok(await wizard.getByRole('link', { name: 'OpenStreetMap' }).isVisible());
    if (evidence) await wizard.screenshot({ path: join(evidence, 'after-390-B-osm.png') });
    await page.setViewportSize({ width: 1440, height: 900 });
    await wizard.locator('[data-location-center]:enabled').waitFor();
    await map.getByRole('button', { name: 'Perbesar peta' }).click();
    await wizard.locator('[data-location-center]:enabled').waitFor();
    await map.click({ position: { x: 180, y: 95 } });
    await wizard.locator('.umkm-pin-status').filter({ hasText: 'Titik usaha terpilih' }).waitFor();
    assert.equal(await map.locator('.leaflet-marker-icon').count(), 1);
    assert.ok(await map.locator('.leaflet-marker-icon').evaluate((image) => image.complete && image.naturalWidth > 0));
    if (evidence) await wizard.screenshot({ path: join(evidence, 'after-1440-B-osm.png') });
    console.log('OSM tiles live 390/1440 dan click pin PASS');
}

async function exerciseWizard(page, wizard, width) {
    const add = page.locator('#add-umkm');
    const next = wizard.getByRole('button', { name: 'Lanjut', exact: true });
    const back = wizard.getByRole('button', { name: 'Kembali', exact: true });
    const save = wizard.locator('#save-umkm');
    const status = wizard.locator('#umkm-whatsapp-status');
    const name = page.locator('#umkm-name');
    const subject = `Usaha Wizard ${width}`;
    const number = page.locator('#umkm-whatsapp-number');
    const activeStep = () => wizard.locator('[aria-current="step"]').innerText();
    const focused = (selector) => page.locator(selector).evaluate((node) => document.activeElement === node);
    const capture = (stage) => evidence && wizard.screenshot({ path: join(evidence, `after-${width}-${stage}.png`) });
    const choose = async (key, option) => {
        await wizard.locator(`#${key}-trigger`).click();
        const panel = wizard.locator(`#${key}-panel`);
        if (option) {
            const search = panel.getByRole('searchbox');
            if (await search.isVisible()) await search.fill(option);
            await panel.getByRole('button', { name: option, exact: true }).click();
        } else await panel.locator('li button').nth(1).click();
    };
    const exerciseMap = await pickLocation(page, wizard, width, choose, capture, next);

    let writes = 0;
    let allowWrite;
    const writeGate = new Promise((resolve) => { allowWrite = resolve; });
    await page.route('**/api/umkms', async (route) => {
        if (route.request().method() === 'POST') {
            writes++;
            if (width === 768 && writes === 1) {
                await route.fulfill({ status: 422, json: { message: 'Data belum valid.', errors: { city: ['Periksa kabupaten/kota.'] } } });
                return;
            }
            await writeGate;
        }
        await route.continue();
    });

    assert.equal(await activeStep(), 'A. Informasi');
    assert.ok(await focused('#umkm-name'));
    assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, listUrl);
    await next.click();
    await wizard.locator('[data-error="omzet_period"]').filter({ hasText: 'wajib' }).waitFor();
    assert.ok(await focused('#umkm-name'));
    await name.fill(subject);
    await next.click();
    assert.ok(await focused('#umkm-sector-trigger'));
    await choose('umkm-sector');
    await wizard.locator('#umkm-omzet').fill('-1000');
    await choose('umkm-omzet-period', 'Bulanan');
    await next.click();
    assert.match(await wizard.locator('[data-error="omzet"]').innerText(), /bilangan bulat/);
    await wizard.locator('#umkm-omzet').fill('12000000');
    await wizard.locator('#umkm-year-founded').fill('2020');
    await wizard.locator('#umkm-employee-count').fill('0');
    await next.click();
    assert.equal(await activeStep(), 'B. Lokasi');
    assert.ok(await focused('#umkm-step-title'));
    const [latitude, longitude] = await exerciseMap();
    const correctedAddress = await wizard.locator('#umkm-address').inputValue();
    await back.click();
    assert.equal(await name.inputValue(), subject);
    assert.equal(await wizard.locator('#umkm-omzet').inputValue(), '12.000.000');
    await next.click();
    assert.equal(await wizard.locator('#umkm-city').inputValue(), 'Jakarta Selatan');
    assert.equal(await wizard.locator('#umkm-address').inputValue(), correctedAddress);
    assert.equal(await wizard.locator('#umkm-latitude').inputValue(), latitude);
    assert.equal(await wizard.locator('.leaflet-marker-icon').count(), 1);
    await next.click();
    assert.equal(await activeStep(), 'C. Kontak');
    await save.click();
    assert.match(await wizard.locator('[data-error="whatsapp_number"]').innerText(), /wajib/);
    assert.equal(writes, 0);
    await wizard.locator('#umkm-owner-name').fill('Pemilik Uji');
    await wizard.locator('#umkm-business-email').fill('usaha@example.com');
    await number.fill('0812abc99999');
    await save.click();
    assert.match(await wizard.locator('[data-error="whatsapp_number"]').innerText(), /nomor HP yang valid/);
    assert.ok(await focused('#umkm-whatsapp-number'));
    assert.equal(writes, 0);

    if (width === 1440) {
        await page.keyboard.press('Escape');
        const discard = page.getByRole('dialog', { name: 'Buang perubahan?' });
        await discard.waitFor();
        await discard.getByRole('button', { name: 'Batal' }).click();
        assert.equal(await number.inputValue(), '81299999');
        assert.ok(await focused('#umkm-whatsapp-number'));
        await page.locator('[data-logout]').first().dispatchEvent('click');
        await discard.waitFor();
        await page.keyboard.press('Escape');
        await discard.waitFor({ state: 'hidden' });
        assert.equal(await activeStep(), 'C. Kontak');
    }

    let lookups = 0;
    let releasePrevious;
    let acknowledgePrevious;
    let releasePendingSave;
    const previousLookup = new Promise((resolve) => { releasePrevious = resolve; });
    const previousFinished = new Promise((resolve) => { acknowledgePrevious = resolve; });
    const pendingSaveLookup = new Promise((resolve) => { releasePendingSave = resolve; });
    await page.route('**/api/umkms/whatsapp-check', async (route) => {
        lookups++;
        const { whatsapp_number } = route.request().postDataJSON();
        if (width === 390) {
            if (whatsapp_number.endsWith('0002')) await pendingSaveLookup;
            await route.continue().catch((error) => {
                if (!whatsapp_number.endsWith('0002')) throw error;
            });
        } else if (width === 768) {
            await route.fulfill({ status: 429, json: { message: 'Terlalu banyak percobaan.' } });
        } else {
            const old = whatsapp_number.endsWith('0000');
            if (old) await previousLookup;
            const result = old || width === 320 ? 'registered' : 'unregistered';
            await route.fulfill({ status: 200, json: { data: { normalized_number: whatsapp_number, status: result } } }).catch((error) => {
                if (!old) throw error;
            });
            if (old) acknowledgePrevious();
        }
    });
    if (width === 1440) {
        const pending = page.waitForRequest('**/api/umkms/whatsapp-check');
        await number.fill('081234560000');
        await number.press('Tab');
        await pending;
        await status.filter({ hasText: 'Memeriksa' }).waitFor();
        await capture('pending');
    }
    assert.equal(await wizard.locator('.umkm-phone-field span').innerText(), '+62');
    await pasteNumber(number, '+62 812 3456-0001');
    assert.equal(await number.inputValue(), '81234560001');
    await number.press('Tab');
    const label = { 320: 'Terdaftar di WhatsApp', 390: 'Belum diperiksa', 768: 'Belum diperiksa', 1440: 'Tidak terdaftar' }[width];
    await status.filter({ hasText: label }).waitFor();
    releasePrevious();
    if (width === 1440) await previousFinished;
    await number.focus();
    await number.press('Tab');
    assert.equal(lookups, width === 1440 ? 2 : 1);
    assert.match(await status.innerText(), new RegExp(label));
    const bounds = await wizard.evaluate((node) => {
        const body = node.querySelector('.dialog-body');
        const footer = node.querySelector('.dialog-footer');
        const box = node.getBoundingClientRect();
        return {
            scrolls: body.scrollHeight > body.clientHeight,
            fits: box.left >= 0 && box.right <= innerWidth && box.top >= 0 && box.bottom <= innerHeight,
            visibleFooter: footer.getBoundingClientRect().bottom <= innerHeight,
            footerRows: new Set([...footer.querySelectorAll('button:not([hidden])')].map((button) => button.offsetTop)).size,
            targetHeight: node.querySelector('#save-umkm').getBoundingClientRect().height,
            overflow: document.documentElement.scrollWidth > innerWidth,
        };
    });
    assert.ok(bounds.fits && bounds.visibleFooter && !bounds.overflow && bounds.targetHeight >= 44 && bounds.footerRows === 1);
    if (width === 320) assert.ok(bounds.scrolls);
    await save.focus();
    await wizard.locator('.dialog-body').evaluate((body) => { body.scrollTop = 0; });
    await capture('C');

    if (width === 768) {
        await save.click();
        await wizard.locator('[data-error="city"]').filter({ hasText: 'Periksa kabupaten/kota' }).waitFor();
        assert.equal(await activeStep(), 'B. Lokasi');
        assert.ok(await focused('#umkm-city'));
        await next.click();
        assert.equal(await number.inputValue(), '81234560001');
    }
    if (width === 390) {
        await number.fill('081234560002');
        await number.press('Tab');
        await status.filter({ hasText: 'Memeriksa' }).waitFor();
    }
    const created = page.waitForResponse((response) => response.url() === origin + '/api/umkms' && response.request().method() === 'POST');
    await save.click();
    await wizard.locator('#save-umkm[aria-busy="true"]:disabled').waitFor();
    await wizard.locator('form').evaluate((form) => form.requestSubmit());
    await page.keyboard.press('Escape');
    assert.ok(await wizard.isVisible());
    allowWrite();
    const response = await created;
    assert.equal(response.status(), 201);
    assert.equal(writes, width === 768 ? 2 : 1);
    const { data } = await response.json();
    assert.equal(data.whatsapp_registration_status, 'unchecked');
    assert.equal(data.whatsapp_number, width === 390 ? '6281234560002' : '6281234560001');
    assert.equal(data.latitude, latitude);
    assert.equal(data.longitude, longitude);
    assert.equal(data.address, correctedAddress);
    assert.equal(data.employee_count, 0);
    await wizard.waitFor({ state: 'hidden' });
    releasePendingSave();
    await page.locator('.toast-success').filter({ hasText: 'berhasil ditambahkan' }).waitFor();
    assert.ok(await focused('#add-umkm'));
    assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, listUrl);

    const business = width === 1440
        ? page.locator('#umkm-rows .umkm-name').getByText(subject, { exact: true })
        : page.getByRole('link', { name: subject, exact: true });
    await business.waitFor();
    if (width < 1050) assert.ok(await page.locator('#umkm-cards').isVisible());
    else assert.ok(await page.locator('#umkm-table-area table').isVisible());
    if (width === 1440) {
        await page.evaluate(() => { window.wizardSidebar = document.querySelector('#desktop-sidebar'); });
        await page.getByRole('button', { name: `Aksi untuk ${subject}` }).click();
        await page.getByRole('link', { name: 'Detail UMKM', exact: true }).click();
        await page.locator('#detail-title').filter({ hasText: subject }).waitFor();
        assert.ok(await page.evaluate(() => window.wizardSidebar === document.querySelector('#desktop-sidebar')));
        await page.goBack();
        await business.waitFor();
        await page.getByRole('button', { name: `Aksi untuk ${subject}` }).click();
        await page.getByRole('button', { name: 'Ubah', exact: true }).click();
        await wizard.getByRole('heading', { name: 'Ubah Data UMKM' }).waitFor();
        assert.equal(await wizard.locator('.umkm-steps').isVisible(), false);
        await wizard.locator('#umkm-omzet').fill('13000000');
        await page.goForward();
        const leave = page.getByRole('dialog', { name: 'Buang perubahan?' });
        await leave.getByRole('button', { name: 'Batal' }).click();
        await page.waitForURL(origin + listUrl);
        assert.equal(await wizard.locator('#umkm-omzet').inputValue(), '13.000.000');
        const patched = page.waitForResponse((res) => res.request().method() === 'PATCH');
        await save.click();
        const update = await patched;
        assert.equal(update.status(), 200);
        assert.equal((await update.json()).data.whatsapp_number, '6281234560001');
        await wizard.waitFor({ state: 'hidden' });
    }
    const cleanup = await api.request.delete(`${origin}/api/umkms/${data.id}`, { headers });
    assert.equal(cleanup.status(), 200);
    await add.waitFor();
    if (width === 390) {
        await add.click();
        await name.fill('Uji ulang peta');
        await choose('umkm-sector');
        await wizard.locator('#umkm-omzet').fill('100000');
        await wizard.locator('#umkm-business-capital').fill('0');
        await choose('umkm-omzet-period', 'Bulanan');
        await next.click();
        await wizard.locator('[data-location-center]:enabled').waitFor();
        assert.equal(await wizard.locator('.leaflet-marker-icon').count(), 0);
        assert.equal(await wizard.locator('.leaflet-control-zoom').count(), 1);
        assert.equal(await wizard.locator('#umkm-latitude').inputValue(), '');
        await wizard.getByRole('button', { name: 'Tutup formulir' }).click();
        await page.getByRole('button', { name: 'Buang perubahan', exact: true }).click();
        await wizard.waitFor({ state: 'hidden' });
    }
}

try {
    for (const [width, height] of [[320, 720], [390, 844], [768, 1024], [1440, 900]]) {
        if (requestedWidth && width !== Number(requestedWidth)) continue;
        if (baseline && ![390, 1440].includes(width)) continue;
        if (liveTiles && width !== 390) continue;
        const context = await browser.newContext({ viewport: { width, height }, reducedMotion: 'reduce', hasTouch: width === 390 });
        await context.addInitScript((session) => {
            localStorage.setItem('joyumkm.token', session.token);
            localStorage.setItem('joyumkm.user', JSON.stringify(session.user));
        }, session);
        const page = await context.newPage();
        page.setDefaultTimeout(7000);
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('request', (request) => {
            const url = new URL(request.url());
            if (url.hostname === 'open-wa.dokumenkita.online'
                || url.pathname.includes('/contacts/check/')
                || Object.hasOwn(request.headers(), 'x-api-key')) {
                errors.push('Browser mengakses integrasi OpenWA secara langsung.');
            }
        });
        await page.goto(origin + (baseline ? '/umkms' : listUrl));
        await page.locator('#add-umkm').click();
        const wizard = page.locator('#umkm-modal');
        await wizard.waitFor();
        await page.evaluate(() => document.fonts.ready);
        if (baseline) {
            if (evidence) await wizard.screenshot({ path: join(evidence, `before-${width}.png`) });
        } else if (liveTiles) {
            await exerciseLiveTiles(page, wizard);
        } else {
            await exerciseWizard(page, wizard, width);
            console.log(`Wizard ${width} px PASS`);
        }
        await context.close();
    }
    assert.deepEqual(errors, []);
} finally {
    await browser.close();
}
