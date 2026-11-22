import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { businessProfile } from './umkm-fixture.mjs';

assert.equal(process.env.JOYUMKM_BROWSER_DB, 'joyumkm_testing');
const { chromium } = createRequire(import.meta.url)(process.env.JOYUMKM_PLAYWRIGHT || 'playwright-core');
const origin = 'http://127.0.0.1:8129';
const evidence = process.env.JOYUMKM_UI2_EVIDENCE;
const browser = await chromium.launch({
    executablePath: process.env.JOYUMKM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
});
const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
const page = await context.newPage();
page.setDefaultTimeout(8000);
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let headers = { Accept: 'application/json' };
const request = async (path, method = 'GET', data) => {
    const response = await context.request.fetch(origin + '/api' + path, { method, data, headers });
    assert.ok(response.ok(), `${method} ${path}: HTTP ${response.status()}`);
    return response.status() === 204 ? null : (await response.json()).data;
};
const photo = async (name) => {
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), name);
    if (evidence) {
        await mkdir(evidence, { recursive: true });
        await page.evaluate(() => document.fonts.ready);
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: join(evidence, name + '.png') });
    }
};
const focusIs = async (locator) => assert.equal(await locator.evaluate((node) => node === document.activeElement), true);
const businesses = [];
const name = 'Koperasi Pangan Élan & Usaha ' + 'Panjang'.repeat(12);
const search = 'Élan & Usaha';
try {
    const password = 'UI2-local-fixture-only';
    const account = await request('/auth/register', 'POST', {
        name: 'Pendamping QA UI-2', email: `joyumkm-ui2-${randomUUID()}@example.com`, password, password_confirmation: password,
    });
    headers = { ...headers, Authorization: 'Bearer ' + account.token };
    const options = await request('/umkms/options');
    for (const businessName of [name, 'Usaha QA Pembanding']) {
        businesses.push(await request('/umkms', 'POST', { name: businessName, sector_id: options.sectors[0].id, province: 'DKI Jakarta', omzet: 1000000, ...businessProfile }));
    }
    await page.goto(origin + '/login');
    await page.locator('#email').fill(account.user.email);
    await page.locator('#password').fill(password);
    await page.locator('#auth-form button[type="submit"]').click();
    await page.waitForURL('**/home');
    const drawer = page.locator('#navigation-drawer');
    const menu = page.locator('#menu-button');
    const font = await page.evaluate(() => getComputedStyle(document.body).fontFamily);
    assert.match(font, /Poppins/);
    await menu.focus();
    await page.keyboard.press('Enter');
    await drawer.waitFor();
    await focusIs(drawer.getByRole('button', { name: 'Tutup menu' }));
    assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'hidden');
    await photo('390-drawer');
    await page.keyboard.press('Escape');
    await drawer.waitFor({ state: 'hidden' });
    await focusIs(menu);
    await menu.tap();
    await page.mouse.click(380, 180);
    await drawer.waitFor({ state: 'hidden' });
    console.log('PASS drawer keyboard, backdrop, focus and scroll lock');

    const searchButton = page.locator('#search-button');
    const searchDialog = page.locator('#search-dialog');
    const searchInput = page.locator('#global-search');
    await searchButton.tap();
    await focusIs(searchInput);
    await photo('390-search');
    await page.keyboard.press('Escape');
    await focusIs(searchButton);
    await searchButton.tap();
    await searchInput.fill('  ' + search + '  ');
    await searchInput.press('Enter');
    await page.waitForURL((url) => url.searchParams.get('search') === search);
    const cards = page.locator('#umkm-cards');
    const card = cards.getByRole('link', { name, exact: true });
    await card.waitFor();
    assert.equal(await cards.getByRole('listitem').count(), 1);
    assert.equal(await page.locator('.data-table').isVisible(), false);
    assert.equal(await searchDialog.isVisible(), false);
    await photo('390-cards-long-name');
    await searchButton.tap();
    await searchInput.fill('Perubahan belum dikirim');
    await page.goBack();
    await page.waitForURL('**/home');
    await searchDialog.waitFor({ state: 'hidden' });
    await page.goForward();
    await card.waitFor();
    assert.equal(await searchInput.inputValue(), search);
    console.log('PASS mobile search, encoded query, Back and Forward');

    const filter = page.locator('#filter-toggle');
    const sheet = page.locator('#filter-dialog');
    await page.locator('#umkm-sort').selectOption('name:asc');
    await card.waitFor();
    await filter.tap();
    await sheet.waitFor();
    await focusIs(sheet.getByRole('button', { name: 'Tutup filter' }));
    await sheet.getByLabel('Provinsi').selectOption('DKI Jakarta');
    await sheet.getByLabel('Status evaluasi').selectOption('none');
    await photo('390-filter-sheet');
    await sheet.getByRole('button', { name: 'Terapkan Filter' }).tap();
    await page.waitForURL((url) => url.searchParams.get('status') === 'none');
    assert.match(await filter.innerText(), /2/);
    await card.click();
    await page.getByRole('heading', { name, exact: true, level: 1 }).waitFor();
    await photo('390-breadcrumb');
    await page.getByRole('navigation', { name: 'Breadcrumb' }).getByRole('link', { name: 'Data UMKM' }).click();
    await card.waitFor();
    assert.equal(new URL(page.url()).searchParams.get('province'), 'DKI Jakarta');
    await filter.tap();
    await sheet.getByLabel('Provinsi').selectOption('');
    await page.keyboard.press('Escape');
    await filter.tap();
    assert.equal(await sheet.getByLabel('Provinsi').inputValue(), 'DKI Jakarta');
    await sheet.getByRole('button', { name: 'Reset Filter' }).tap();
    await page.waitForURL((url) => !url.searchParams.has('status')
        && url.searchParams.get('search') === search && url.searchParams.get('sort') === 'name');
    await card.waitFor();
    console.log('PASS filter apply, active count, cancel, reset and breadcrumb');

    const table = page.locator('.data-table');
    for (const viewport of [{ width: 768, height: 1024 }, { width: 1050, height: 900 }, { width: 1051, height: 900 }, { width: 1440, height: 900 }]) {
        const compact = viewport.width <= 1050;
        if (viewport.width === 1051) await menu.tap();
        await page.setViewportSize(viewport);
        await (compact ? cards : table).waitFor();
        assert.equal(await (compact ? table : cards).isVisible(), false);
        assert.equal(await drawer.isVisible(), false);
        assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'visible');
        await photo(`${viewport.width}-workspace`);
        if (viewport.width === 768) {
            await menu.tap();
            await drawer.waitFor();
            assert.equal(await drawer.locator('[aria-current="page"]').innerText(), 'Data UMKM');
            await photo('768-drawer');
            await drawer.getByRole('button', { name: 'Tutup menu' }).tap();
        }
    }
    assert.equal(await page.locator('#desktop-sidebar').evaluate((node) => getComputedStyle(node).backgroundColor), 'rgb(16, 30, 64)');
    const contrast = await page.locator('#desktop-sidebar').evaluate((sidebar) => {
        const luminance = (color) => color.match(/\d+/g).slice(0, 3)
            .map((channel) => Number(channel) / 255)
            .map((channel) => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
            .reduce((total, channel, index) => total + channel * [0.2126, 0.7152, 0.0722][index], 0);
        return ['.nav-section-label', '.nav-link:not(.is-active)', '.nav-link.is-active'].map((selector) => {
            const styles = getComputedStyle(sidebar.querySelector(selector));
            const background = selector.endsWith('.is-active') ? styles.backgroundColor : getComputedStyle(sidebar).backgroundColor;
            return (luminance(styles.color) + 0.05) / (luminance(background) + 0.05);
        });
    });
    assert.ok(contrast.every((ratio) => ratio >= 4.5), JSON.stringify(contrast));
    const active = page.locator('#desktop-sidebar [aria-current]');
    assert.equal(await active.count(), 1);
    assert.equal(await active.evaluate((node) => getComputedStyle(node).backgroundColor), 'rgb(2, 7, 202)');
    assert.equal(await page.locator('#umkm-table-area .data-table-wrap').evaluate((node) => node.scrollWidth <= node.clientWidth), true);
    await page.setViewportSize({ width: 1050, height: 900 });
    await filter.tap();
    await sheet.waitFor();
    await page.setViewportSize({ width: 1440, height: 900 });
    await sheet.waitFor({ state: 'hidden' });
    assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'visible');
    await page.locator('#desktop-sidebar').getByRole('link', { name: 'Penilaian Enam Faktor' }).click();
    await page.waitForURL((url) => url.searchParams.get('mode') === 'six-factor');
    await table.getByRole('link', { name, exact: true }).click();
    await page.getByRole('heading', { name: 'Penilaian Enam Faktor', exact: true }).waitFor();
    assert.equal(await active.innerText(), 'Penilaian Enam Faktor');
    assert.equal(await active.getAttribute('aria-current'), 'location');
    await photo('1440-six-factor-context');
    console.log('PASS responsive transition, sidebar tokens and Enam Faktor entry point');

    let listRequests = 0;
    page.on('request', (request) => {
        if (new URL(request.url()).pathname === '/api/umkms' && request.method() === 'GET') listRequests++;
    });
    await page.setViewportSize({ width: 320, height: 740 });
    await page.goto(origin + '/umkms?per_page=1&sort=name&direction=asc');
    await card.waitFor();
    assert.equal(await page.locator('#umkm-per-page').inputValue(), '1');
    await photo('320-compact');
    await page.getByRole('button', { name: 'Halaman berikutnya' }).tap();
    await cards.getByRole('link', { name: businesses[1].name }).waitFor();
    assert.equal(new URL(page.url()).searchParams.get('page'), '2');
    await page.locator('#umkm-sort').selectOption('name:desc');
    await page.waitForURL((url) => url.searchParams.get('direction') === 'desc' && !url.searchParams.has('page'));
    await cards.getByRole('link', { name: businesses[1].name }).waitFor();
    assert.equal(listRequests, 3);
    await page.setViewportSize({ width: 1440, height: 900 });
    await table.getByRole('link', { name: businesses[1].name }).waitFor();
    assert.equal(listRequests, 3, 'switching renderers does not refetch');
    assert.equal(await table.locator('[aria-sort="descending"]').getAttribute('data-sort'), 'name');

    let releaseOld;
    const held = new Promise((resolve) => { releaseOld = resolve; });
    await page.route((url) => url.pathname === '/api/umkms' && url.searchParams.get('search') === 'lama', async (route) => {
        await held;
        await route.continue().catch(() => {});
    }, { times: 1 });
    await searchInput.fill('lama');
    await page.waitForURL((url) => url.searchParams.get('search') === 'lama');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('.umkm-card-skeleton').waitFor();
    await photo('390-card-skeleton');
    await searchButton.tap();
    await searchInput.fill('tidak ditemukan');
    await searchInput.press('Enter');
    await page.getByText('Tidak ada hasil sesuai filter', { exact: true }).waitFor();
    releaseOld();
    await photo('390-card-empty');
    await page.route((url) => url.pathname === '/api/umkms', (route) => route.fulfill({
        status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Daftar UMKM sedang tidak tersedia.' }),
    }), { times: 1 });
    await searchButton.tap();
    await searchInput.fill('');
    await searchInput.press('Enter');
    const retry = page.locator('#umkm-list-state').getByRole('button', { name: 'Coba lagi' });
    await retry.waitFor();
    await photo('390-card-error');
    await retry.tap();
    await cards.getByRole('link', { name: businesses[1].name }).waitFor();
    assert.equal(await searchInput.inputValue(), '');
    console.log('PASS sort, pagination, shared fetch, stale search, loading, empty and retry');

    await page.getByRole('button', { name: 'Halaman berikutnya' }).tap();
    await card.waitFor();
    const priorHistory = await page.evaluate(() => history.length);
    await cards.getByRole('button', { name: `Aksi untuk ${name}` }).tap();
    await page.locator('.umkm-action-menu:popover-open').getByRole('button', { name: 'Hapus' }).tap();
    assert.equal(await page.locator('#delete-umkm-name').textContent(), name);
    await photo('390-delete-long-name');
    await page.locator('#confirm-delete-umkm').tap();
    await page.waitForURL((url) => url.searchParams.get('page') === '1');
    await cards.getByRole('link', { name: businesses[1].name }).waitFor();
    businesses.shift();
    assert.equal(await page.evaluate(() => history.length), priorHistory);
    console.log('PASS mobile delete and out-of-range pagination correction');

    assert.deepEqual(errors, []);
} finally {
    for (const business of businesses) await request('/umkms/' + business.id, 'DELETE');
    if (headers.Authorization) await request('/auth/logout', 'POST');
    await browser.close();
}
