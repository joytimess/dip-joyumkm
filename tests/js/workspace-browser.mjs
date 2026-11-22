import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdir, mkdtemp, rmdir, unlink, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { businessProfile } from './umkm-fixture.mjs';

assert.equal(process.env.JOYUMKM_BROWSER_DB, 'joyumkm_testing');
const { chromium } = createRequire(import.meta.url)(process.env.JOYUMKM_PLAYWRIGHT || 'playwright-core');
const origin = 'http://127.0.0.1:8129';
const artifacts = process.env.JOYUMKM_UI0_EVIDENCE;
if (artifacts) await mkdir(artifacts, { recursive: true });
const password = 'UI0-browser-test-only-2026';
const email = 'joyumkm-ui0-' + randomUUID() + '@example.com';
const executablePath = process.env.JOYUMKM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const browser = await chromium.launch({ executablePath });
const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 } });
desktop.setDefaultTimeout(8000);
const page = await desktop.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let authChecks = 0;
let documents = 0;
page.on('request', (request) => {
    if (request.isNavigationRequest()) documents++;
    if (new URL(request.url()).pathname === '/api/auth/me') authChecks++;
});
const screenshot = (name, tab = page) => artifacts && tab.screenshot({ path: join(artifacts, name + '.png') });
const ready = (tab = page) => tab.locator('.topbar [data-user-name]').filter({ hasText: 'JoyUMKM UI0 QA' }).waitFor();
const shown = (name, tab = page) => tab.getByRole('heading', { name, exact: typeof name === 'string' }).waitFor();
const request = (path, options = {}, context = desktop) => context.request.fetch(origin + '/api' + path, {
    ...options, headers: { Accept: 'application/json', ...options.headers },
});
const menu = (path, tab = page, selector = '#desktop-sidebar') => tab.locator(selector + ' nav a[href$="' + path + '"]');
const noOverflow = async (tab) => assert.ok(await tab.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
const check = (description) => console.log('PASS ' + description);

try {
    const response = await request('/auth/register', { method: 'POST', data: { name: 'JoyUMKM UI0 QA', email, password, password_confirmation: password } });
    assert.equal(response.status(), 201);
    const { data: account } = await response.json();
    await page.goto(origin + '/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('#auth-form button[type="submit"]').click();
    await ready();
    await page.evaluate(() => { window.initialSidebar = document.querySelector('#desktop-sidebar'); });
    const baseline = process.argv.includes('--baseline');
    await page.route('**/api/auth/me', async (route) => {
        if (baseline) await new Promise((resolve) => setTimeout(resolve, 1100));
        await route.continue();
    });
    const start = [documents, authChecks];
    await page.locator('#desktop-sidebar a[href$="/umkms"]').click();
    if (baseline) {
        await page.locator('#auth-status').waitFor();
        await screenshot('before-session-spinner');
    }
    await page.waitForURL('**/umkms');
    await ready();
    console.log('Home → UMKM document/auth requests:', documents - start[0], authChecks - start[1]);
    assert.equal(await page.evaluate(() => window.initialSidebar === document.querySelector('#desktop-sidebar')), true, 'workspace shell survives navigation');
    assert.deepEqual([documents, authChecks], start);

    const headers = { Authorization: 'Bearer ' + account.token };
    const { data: options } = await (await request('/umkms/options', { headers })).json();
    const { data: business } = await (await request('/umkms', { headers, method: 'POST', data: {
        name: 'Usaha UI0 QA', sector_id: options.sectors[0].id, province: 'DKI Jakarta', omzet: 1000000,
        ...businessProfile,
    } })).json();
    const waitForBusiness = (tab = page) => tab.getByRole('link', { name: business.name, exact: true }).waitFor();
    await page.reload();
    await ready();
    await waitForBusiness();
    await page.evaluate(() => { window.initialSidebar = document.querySelector('#desktop-sidebar'); window.initialHeader = document.querySelector('.topbar'); });
    const matrixStart = [documents, authChecks];
    const frames = [];
    let recordMatrix = true;
    async function visit(path, heading, title = heading) {
        const start = Date.now();
        await menu(path).click();
        await shown(heading);
        if (recordMatrix) frames.push({ path, elapsedMs: Date.now() - start, documents: documents - matrixStart[0], authChecks: authChecks - matrixStart[1] });
        assert.equal(await menu(path).getAttribute('aria-current'), 'page');
        assert.equal(await page.locator('h1, #welcome-title').evaluate((heading) => heading === document.activeElement), true);
        assert.ok((await page.title()).includes(title));
    }
    await visit('/factor-profile', 'Profil Faktor', 'Factor Profile');
    await visit('/distribution', 'Distribusi Kesehatan', 'Distribution');
    await visit('/comparison', 'Perbandingan UMKM', 'Comparison');
    await visit('/form-data', 'Kuesioner Tiga Dimensi');
    await visit('/home', /^Selamat datang/, 'Home');
    await visit('/umkms', 'Data UMKM');
    await waitForBusiness();
    await page.getByRole('link', { name: business.name, exact: true }).click();
    await shown(business.name);
    await page.locator('#main-content').getByRole('link', { name: 'Penilaian Enam Faktor' }).click();
    await shown('Penilaian Enam Faktor');
    await page.locator('#draft-scores:not(:disabled)').waitFor();
    assert.equal(await menu('/umkms?mode=six-factor').getAttribute('aria-current'), 'location');
    await page.goBack();
    await shown(business.name);
    await page.goForward();
    await shown('Penilaian Enam Faktor');
    recordMatrix = false;
    assert.deepEqual([documents, authChecks], matrixStart);
    assert.equal(await page.evaluate(() => window.initialSidebar === document.querySelector('#desktop-sidebar') && window.initialHeader === document.querySelector('.topbar')), true);
    await screenshot('after-workspace-six-factor');
    check('eight routes, persistent sidebar/topbar, focus, title, active menu and Back/Forward');
    await page.locator('.skip-link').focus();
    await page.keyboard.press('Enter');
    await page.waitForURL((url) => url.hash === '#main-content');
    assert.equal(new URL(page.url()).hash, '#main-content');
    assert.equal(await page.locator('#main-content').evaluate((node) => node === document.activeElement), true);
    await page.goBack();
    await page.waitForURL((url) => url.hash === '');
    assert.equal(new URL(page.url()).hash, '');

    const search = page.locator('#global-search');
    await search.fill(business.name);
    await search.press('Enter');
    await page.waitForURL('**/umkms?search=*');
    await waitForBusiness();
    await page.reload();
    await ready();
    assert.equal(await search.inputValue(), business.name);
    await waitForBusiness();
    await page.goto(origin + '/factor-profile?umkm_id=' + business.id);
    await ready();
    await page.waitForFunction((id) => document.querySelector('[data-picker-select]')?.value === String(id), business.id);
    const popupPromise = desktop.waitForEvent('page');
    await menu('/umkms').click({ modifiers: ['Control'] });
    const popup = await popupPromise;
    await ready(popup);
    await waitForBusiness(popup);
    await popup.close();
    const middlePromise = desktop.waitForEvent('page');
    await menu('/umkms').click({ button: 'middle' });
    const middle = await middlePromise;
    await ready(middle);
    await middle.close();
    check('search query, refresh, direct UMKM picker URL and Ctrl+click new tab');

    let releaseQuestionnaire;
    const slowQuestionnaire = new Promise((resolve) => { releaseQuestionnaire = resolve; });
    await page.route('**/api/questionnaire', async (route) => {
        await slowQuestionnaire;
        await route.continue().catch(() => {});
    }, { times: 1 });
    await visit('/form-data', 'Kuesioner Tiga Dimensi');
    await page.locator('[data-picker-select]').selectOption(String(business.id));
    await page.locator('#questionnaire-state[aria-busy="true"] .skeleton').first().waitFor();
    assert.equal(await page.locator('[data-question-id]:visible').count(), 0);
    await screenshot('desktop-form-skeleton');
    releaseQuestionnaire();
    const radio = page.locator('[data-question-id]:visible').first();
    await radio.check();
    const dialogs = [];
    const keepEdits = async (dialog) => { dialogs.push(dialog.message()); await dialog.dismiss(); };
    page.on('dialog', keepEdits);
    await menu('/home').click();
    await page.waitForFunction(() => document.querySelector('#main-content')?.getAttribute('aria-busy') !== 'true');
    assert.equal(dialogs.length, 1);
    assert.equal(await radio.isChecked(), true);
    await page.goBack();
    await page.waitForURL('**/form-data?umkm=' + business.id);
    assert.equal(dialogs.length, 2);
    assert.equal(await radio.isChecked(), true);
    page.off('dialog', keepEdits);
    page.once('dialog', (dialog) => dialog.accept());
    await visit('/home', /^Selamat datang/, 'Home');
    check('dirty questionnaire survives canceled menu navigation and Back');

    await page.route('**/api/dashboard/distribution', (route) => route.fulfill({
        status: 500, json: { message: 'raw-server-exception-marker' },
    }), { times: 1 });
    await visit('/distribution', 'Distribusi Kesehatan', 'Distribution');
    const retryChart = page.locator('#page-data').getByRole('button', { name: 'Coba lagi' });
    await retryChart.waitFor();
    assert.equal(await page.getByText('raw-server-exception-marker').count(), 0);
    await screenshot('desktop-chart-error');
    await retryChart.click();
    await page.locator('#page-data[aria-busy="false"]').waitFor();
    assert.equal(await retryChart.count(), 0);
    check('local chart error and successful retry');

    let releaseSummary;
    const slowSummary = new Promise((resolve) => { releaseSummary = resolve; });
    await page.route('**/api/umkms/summary', async (route) => {
        await slowSummary;
        await route.continue().catch(() => {});
    }, { times: 1 });
    await visit('/home', /^Selamat datang/, 'Home');
    await page.locator('#home-health[aria-busy="false"]').waitFor();
    await page.locator('#umkm-summary[aria-busy="true"] .skeleton').first().waitFor();
    await screenshot('desktop-independent-skeleton');
    releaseSummary();
    await page.locator('#umkm-summary[aria-busy="false"]').waitFor();
    check('independent KPI and chart loading');

    await page.route('**/api/umkms?*', (route) => route.fulfill({ status: 403, json: {} }), { times: 1 });
    await visit('/umkms', 'Data UMKM');
    await page.locator('#umkm-list-state').getByText('Anda tidak memiliki akses ke bagian ini.').waitFor();
    await page.locator('#umkm-summary[aria-busy="false"]').waitFor();
    await ready();
    await page.locator('#umkm-list-state button').click();
    await waitForBusiness();
    await page.locator('#add-umkm').click();
    await page.locator('#umkm-name').fill('Perubahan UMKM belum tersimpan');
    page.once('dialog', (dialog) => dialog.dismiss());
    await page.goBack();
    await page.waitForURL('**/umkms');
    assert.equal(await page.locator('#umkm-name').inputValue(), 'Perubahan UMKM belum tersimpan');
    assert.equal(await page.locator('#umkm-modal').isVisible(), true);
    await page.locator('#umkm-modal').getByRole('button', { name: 'Batal' }).click();
    await page.getByRole('button', { name: 'Buang perubahan', exact: true }).click();
    check('403 stays local, table retry succeeds and Back preserves UMKM dialog edits');

    await page.route('**/factor-profile', async (route) => {
        if (route.request().resourceType() === 'fetch') await new Promise((resolve) => setTimeout(resolve, 400));
        await route.continue().catch(() => {});
    }, { times: 1 });
    await page.evaluate(() => ['/factor-profile', '/comparison', '/distribution'].forEach((path) => {
        document.querySelector('#desktop-sidebar nav a[href$="' + path + '"]').click();
    }));
    await shown('Distribusi Kesehatan');
    await page.locator('#page-data[aria-busy="false"]').waitFor();
    assert.ok(page.url().endsWith('/distribution'));
    await page.route('**/comparison', (route) => route.abort('failed'), { times: 1 });
    await menu('/comparison').click();
    await page.locator('#navigation-error button').click();
    await shown('Perbandingan UMKM');
    assert.equal(await page.locator('#navigation-error').isVisible(), false);
    assert.equal(await page.locator('#desktop-sidebar [aria-current="page"]').count(), 1);
    assert.equal(await menu('/comparison').getAttribute('aria-current'), 'page');
    check('rapid navigation, canceled responses, failed route fetch and retry');

    for (const viewport of [{ width: 768, height: 1024 }, { width: 390, height: 844 }]) {
        await page.setViewportSize(viewport);
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.locator('#menu-button').click();
        let releaseList;
        const slowList = new Promise((resolve) => { releaseList = resolve; });
        await page.route('**/api/umkms?*', async (route) => {
            await slowList;
            await route.continue().catch(() => {});
        }, { times: 1 });
        await menu('/umkms', page, '#navigation-drawer').click();
        await shown('Data UMKM');
        await page.locator('#umkm-list-state[aria-busy="true"] .umkm-card-skeleton .skeleton').first().waitFor();
        assert.equal(await page.locator('#navigation-drawer').isVisible(), false);
        assert.equal(await page.locator('#menu-button').getAttribute('aria-expanded'), 'false');
        await noOverflow(page);
        if (viewport.width === 390) await page.locator('#umkm-list-state').scrollIntoViewIfNeeded();
        await screenshot(viewport.width + '-card-skeleton');
        releaseList();
        await waitForBusiness();
        await noOverflow(page);
        await screenshot(viewport.width + '-card-ready');
        await page.locator('#menu-button').click();
        await menu('/home', page, '#navigation-drawer').click();
        await shown(/^Selamat datang/);
    }
    check('tablet and mobile drawer, skeleton, reduced motion and viewport width');

    if (artifacts && process.env.JOYUMKM_UI0_VIDEO) {
        const videoDir = await mkdtemp(join(tmpdir(), 'joyumkm-ui0-video-'));
        const recording = await browser.newContext({
            viewport: { width: 1440, height: 900 },
            storageState: await desktop.storageState(),
            recordVideo: { dir: videoDir, size: { width: 1440, height: 900 } },
        });
        const videoPage = await recording.newPage();
        const video = videoPage.video();
        await videoPage.goto(origin + '/home');
        await ready(videoPage);
        await videoPage.evaluate(() => { window.recordedShell = document.querySelector('.topbar'); });
        await videoPage.route('**/api/dashboard/distribution', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 650));
            await route.continue();
        });
        await menu('/distribution', videoPage).click();
        await videoPage.locator('#page-data[aria-busy="true"] .skeleton').first().waitFor();
        await videoPage.locator('#page-data[aria-busy="false"]').waitFor();
        await menu('/umkms', videoPage).click();
        await waitForBusiness(videoPage);
        assert.equal(await videoPage.evaluate(() => window.recordedShell === document.querySelector('.topbar')), true);
        await recording.close();
        await video.saveAs(join(artifacts, 'desktop-navigation.webm'));
        await unlink(await video.path());
        await rmdir(videoDir);
    }

    const newTab = await desktop.newPage();
    await newTab.route('**/api/auth/me', (route) => route.abort('internetdisconnected'), { times: 1 });
    await newTab.goto(origin + '/umkms/' + business.id);
    await newTab.getByText('Sesi belum dapat diverifikasi.', { exact: false }).waitFor();
    assert.equal(await newTab.locator('.topbar [data-user-name]').textContent(), '');
    assert.equal(await newTab.locator('#main-content').evaluate((node) => node.inert), true);
    await screenshot('session-error', newTab);
    await newTab.locator('#auth-status button').click();
    await ready(newTab);
    await shown(business.name, newTab);
    check('cold-entry network failure, inert content and same-document recovery');
    const timeoutTab = await desktop.newPage();
    await timeoutTab.addInitScript(() => {
        const fullTimeout = AbortSignal.timeout;
        let testFirstApiTimeout = true;
        AbortSignal.timeout = (duration) => {
            if (duration === 20000 && testFirstApiTimeout) {
                testFirstApiTimeout = false;
                return fullTimeout(150);
            }
            return fullTimeout(duration);
        };
    });
    await timeoutTab.route('**/api/auth/me', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 400));
        await route.continue().catch(() => {});
    }, { times: 1 });
    await timeoutTab.goto(origin + '/home');
    await timeoutTab.getByText('Sesi belum dapat diverifikasi.', { exact: false }).waitFor();
    await timeoutTab.locator('#auth-status button').click();
    await ready(timeoutTab);
    await timeoutTab.close();
    check('cold-entry request timeout keeps bearer and allows retry');

    const replacement = await request('/auth/register', { method: 'POST', data: {
        name: 'JoyUMKM UI0 Replacement', email: 'replacement-' + email, password, password_confirmation: password,
    } });
    const { data: other } = await replacement.json();
    assert.equal(replacement.status(), 201);
    await newTab.evaluate((token) => localStorage.setItem('joyumkm.token', token), other.token);
    await page.waitForFunction(() => document.querySelector('.topbar [data-user-name]')?.textContent === 'JoyUMKM UI0 Replacement');
    assert.equal(await page.getByText(business.name, { exact: true }).count(), 0);
    await newTab.reload();
    await newTab.locator('.topbar [data-user-name]').getByText('JoyUMKM UI0 Replacement').waitFor();
    await newTab.getByText('Data tidak ditemukan.').waitFor();
    check('cross-tab token replacement removes previous owner data');

    await newTab.locator('#desktop-sidebar [data-logout]').click();
    await newTab.waitForURL('**/login');
    await page.waitForURL('**/login');
    assert.equal(await page.locator('#app-shell').count(), 0);
    const fresh = await browser.newPage();
    await fresh.goto(origin + '/umkms/' + business.id);
    await fresh.waitForURL('**/login');
    await fresh.close();
    const noScript = await chromium.launch({ executablePath, args: ['--blink-settings=scriptEnabled=false'] });
    try {
        const nativeTab = await noScript.newPage({ javaScriptEnabled: false });
        await nativeTab.goto(origin + '/umkms/' + business.id);
        await nativeTab.locator('noscript p').waitFor({ timeout: 8000 });
        assert.equal(await nativeTab.locator('#main-content[inert]').count(), 1);
        assert.equal(await nativeTab.getByText(business.name, { exact: true }).count(), 0);
        await menu('/umkms', nativeTab).click();
        await nativeTab.waitForURL('**/umkms');
    } finally {
        await noScript.close();
    }
    await page.goBack();
    await page.waitForURL('**/login');
    check('logout, cross-tab invalidation, no token on direct entry and history');

    await newTab.locator('#email').fill(email);
    await newTab.locator('#password').fill(password);
    await newTab.locator('#auth-form button[type="submit"]').click();
    await ready(newTab);
    const liveToken = await newTab.evaluate(() => localStorage.getItem('joyumkm.token'));
    const revoked = await request('/auth/logout', { method: 'POST', headers: { Authorization: 'Bearer ' + liveToken } });
    assert.ok(revoked.ok());
    await menu('/umkms', newTab).click();
    await newTab.waitForURL('**/login');
    assert.equal(await newTab.locator('#app-shell').count(), 0);
    check('actual revoked bearer returns to login on protected API request');

    if (artifacts) await writeFile(join(artifacts, 'navigation-measurements.json'), JSON.stringify({
        initialTransition: { documents: 0, authChecks: 0 }, routeTransitions: frames,
    }, null, 2) + '\n');
    assert.deepEqual(errors, []);
} finally {
    await browser.close();
}
