import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as pause } from 'node:timers/promises';

// Run against the loopback Laravel server connected to joyumkm_testing.
assert.equal(process.env.JOYUMKM_BROWSER_DB, 'joyumkm_testing');
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.JOYUMKM_PLAYWRIGHT || 'playwright-core');
const origin = 'http://127.0.0.1:8129';
const run = randomUUID().slice(0, 8);
const password = 'P2-browser-test-only-2026';
const keys = ['sumber_daya', 'kepemimpinan', 'lingkungan_kerja', 'nilai_organisasi', 'stabilitas', 'kinerja'];
const labels = ['Sumber Daya', 'Kepemimpinan', 'Lingkungan Kerja', 'Nilai Organisasi', 'Stabilitas', 'Kinerja'];
const evaluated = (scores) => Object.fromEntries(keys.map((key, index) => [key, scores[index]]));
const checks = [];

function pass(check) {
    checks.push(check);
    console.log('PASS ' + check);
}

async function request(path, { token, method = 'GET', body, status = 200 } = {}) {
    const response = await fetch(origin + '/api' + path, {
        method,
        headers: {
            Accept: 'application/json',
            ...(token && { Authorization: 'Bearer ' + token }),
            ...(body !== undefined && { 'Content-Type': 'application/json' }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const payload = await response.json();
    assert.equal(response.status, status, path + ': ' + (payload.message || response.status));
    return payload;
}

async function register(suffix) {
    const email = 'joyumkm-p2-' + run + '-' + suffix + '@example.com';
    const { data } = await request('/auth/register', {
        method: 'POST', status: 201, body: { name: 'JoyUMKM P2 QA', email, password, password_confirmation: password },
    });
    return { email, token: data.token };
}

const owner = await register('owner');
const outsider = await register('outsider');
const { data: options } = await request('/umkms/options', { token: owner.token });
async function createUmkm(account, suffix) {
    const { data } = await request('/umkms', {
        token: account.token, method: 'POST', status: 201,
        body: { name: 'Usaha P2 ' + run + ' ' + suffix, province: 'DKI Jakarta', sector_id: options.sectors[0].id, omzet: 12000000 },
    });
    return data;
}

const own = await createUmkm(owner, 'utama');
const sibling = await createUmkm(owner, 'cabang');
const foreign = await createUmkm(outsider, 'akun lain');
const prefix = '/umkms/' + own.id + '/six-factor-evaluations';
const ownApi = (suffix, options = {}) => request(prefix + suffix, { token: owner.token, ...options });
const readDraft = async () => (await ownApi('/draft')).data;
const browser = await chromium.launch({
    executablePath: process.env.JOYUMKM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: true,
});
const desktop = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Jakarta' });
const page = await desktop.newPage();
const pageErrors = [];
desktop.on('page', (tab) => tab.on('pageerror', (error) => pageErrors.push(error.message)));
page.on('pageerror', (error) => pageErrors.push(error.message));
const control = (key, tab = page) => tab.locator('#score-' + key);
const valueLabel = (key, tab = page) => tab.locator('#score-value-' + key);
async function setScore(key, value, tab = page) {
    const slider = control(key, tab);
    const current = Number(await slider.inputValue());
    if (current === value && await slider.getAttribute('data-unselected') !== null) await slider.press('Home');
    const arrow = value > current ? 'ArrowRight' : 'ArrowLeft';
    for (let moved = 0; moved < Math.abs(value - current); moved++) await slider.press(arrow);
    assert.equal(await valueLabel(key, tab).textContent(), value + ' / 5');
}
const visible = (locator) => locator.waitFor({ state: 'visible' });
const saveStatus = (text, tab = page) => tab.waitForFunction((expected) =>
    document.getElementById('draft-status')?.textContent === expected, text);
const enabled = (selector, tab = page) => tab.waitForFunction((id) => !document.querySelector(id)?.matches(':disabled'), selector);
const ready = async (tab = page) => {
    await visible(control(keys[0], tab));
    await enabled('#draft-scores', tab);
};
const confirm = async (action, tab = page) => {
    await enabled('#' + action + '-draft', tab);
    await tab.locator('#' + action + '-draft').click();
    await tab.locator('#confirm-draft-action').click();
};
const overflow = async (tab) => tab.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const waitResponse = (suffix, method, tab = page) => tab.waitForResponse((response) =>
    new URL(response.url()).pathname === '/api' + prefix + suffix && response.request().method() === method);
const hold = () => {
    let release;
    const promise = new Promise((resolve) => { release = resolve; });
    return { promise, release };
};
async function login(tab, email) {
    await tab.goto(origin + '/login');
    await tab.locator('#email').fill(email);
    await tab.locator('#password').fill(password);
    await tab.locator('#auth-form button[type="submit"]').click();
    await tab.waitForURL('**/home');
}

try {
    await login(page, owner.email);
    await page.goto(origin + '/umkms');
    await page.getByRole('link', { name: own.name, exact: true }).click();
    await visible(page.getByRole('heading', { name: own.name }));
    await page.locator('#main-content').getByRole('link', { name: 'Penilaian Enam Faktor', exact: true }).click();
    await ready();
    assert.equal(new URL(page.url()).pathname, prefix);
    assert.equal(await page.locator('#detail-title').textContent(), own.name);
    await page.locator('.workspace-breadcrumb [data-context-umkm]').click();
    await visible(page.getByRole('heading', { name: own.name }));
    await page.locator('#main-content').getByRole('link', { name: 'Penilaian Enam Faktor', exact: true }).click();
    await ready();
    pass('AC-P2-01 navigasi V1 dan identitas UMKM');

    assert.deepEqual(await page.locator('#draft-scores label').allTextContents(), labels);
    assert.equal(await page.locator('#draft-scores input[type="range"]').count(), 6);
    assert.equal(await page.locator('#draft-scores select, #draft-scores input[type="radio"]').count(), 0);
    for (const key of keys) {
        const slider = control(key);
        assert.equal(await slider.inputValue(), '1');
        assert.equal(await slider.getAttribute('min'), '1');
        assert.equal(await slider.getAttribute('max'), '5');
        assert.equal(await slider.getAttribute('step'), '1');
        assert.equal(await slider.getAttribute('aria-valuetext'), 'Belum dipilih');
        assert.equal(await valueLabel(key).textContent(), 'Belum dipilih');
    }
    const scales = await page.locator('.six-factor-range-scale').evaluateAll((rows) =>
        rows.map((row) => Array.from(row.children, (number) => number.textContent)));
    assert.deepEqual(scales, Array(6).fill(['1', '2', '3', '4', '5']));
    await page.locator('label[for="score-sumber_daya"]').click();
    await control('sumber_daya').press('Tab');
    await page.keyboard.press('Shift+Tab');
    assert.equal(await control('sumber_daya').evaluate((node) => node.matches(':focus-visible') && getComputedStyle(node).outlineStyle !== 'none'), true);
    await visible(page.locator('#latest-result').getByText('Belum ada penilaian Final'));
    await visible(page.locator('#final-history').getByText('Belum ada penilaian Final'));
    await pause(800);
    assert.equal(await readDraft(), null);
    assert.equal(await page.locator('#finalize-draft').isDisabled(), true);
    assert.equal(await overflow(page), 0);

    const heldSave = hold();
    const reachedServer = hold();
    const patches = [];
    async function delayedSave(route) {
        if (route.request().method() !== 'PATCH') return route.continue();
        const response = await route.fetch();
        patches.push({ status: response.status(), body: route.request().postDataJSON() });
        if (patches.length === 1) {
            reachedServer.release();
            await heldSave.promise;
        }
        await route.fulfill({ response });
    }
    const draftUrl = origin + '/api' + prefix + '/draft';
    await page.route(draftUrl, delayedSave);
    await setScore('sumber_daya', 1);
    await setScore('kepemimpinan', 2);
    await saveStatus('Menunggu penyimpanan');
    assert.equal(await page.locator('#finalize-draft').isDisabled(), true);
    await reachedServer.promise;
    await saveStatus('Menyimpan…');
    for (const value of [2, 3, 4, 5, 4]) await setScore('sumber_daya', value);
    await page.getByRole('button', { name: 'Kosongkan Kepemimpinan' }).focus();
    await page.keyboard.press('Enter');
    assert.equal(await valueLabel('kepemimpinan').textContent(), 'Belum dipilih');
    assert.equal(await control('kepemimpinan').evaluate((node) => node === document.activeElement), true);
    await pause(800);
    assert.equal(patches.length, 1);
    assert.equal(await page.locator('#finalize-draft').isDisabled(), true);
    heldSave.release();
    await saveStatus('Tersimpan');
    assert.deepEqual(patches, [
        { status: 201, body: { scores: { sumber_daya: 1, kepemimpinan: 2 } } },
        { status: 200, body: { scores: { sumber_daya: 4, kepemimpinan: null } } },
    ]);
    await page.unroute(draftUrl, delayedSave);
    pass('AC-P2-02,03 enam slider native, label angka, keyboard 1–5, serialisasi PATCH dan null');

    await page.reload();
    await ready();
    assert.equal(await control('sumber_daya').inputValue(), '4');
    assert.equal(await valueLabel('sumber_daya').textContent(), '4 / 5');
    assert.equal(await valueLabel('kepemimpinan').textContent(), 'Belum dipilih');
    const mouseSlider = await control('stabilitas').boundingBox();
    const trackY = mouseSlider.y + mouseSlider.height / 2;
    await page.mouse.move(mouseSlider.x + 8, trackY);
    await page.mouse.down();
    await page.mouse.move(mouseSlider.x + mouseSlider.width - 8, trackY, { steps: 8 });
    await page.mouse.up();
    assert.equal(await valueLabel('stabilitas').textContent(), '5 / 5');
    await saveStatus('Tersimpan');
    await page.getByRole('button', { name: 'Kosongkan Sumber Daya' }).click();
    await saveStatus('Tersimpan');
    await page.reload();
    await ready();
    assert.equal(await valueLabel('sumber_daya').textContent(), 'Belum dipilih');
    assert.equal(await control('sumber_daya').inputValue(), '1');
    assert.equal(await control('stabilitas').inputValue(), '5');
    assert.equal((await readDraft()).scores.sumber_daya, null);
    pass('AC-P2-04 form kosong, geser dengan mouse, reload Draft parsial dan pengosongan');

    for (const score of [4, 5]) {
        const beforeAck = hold();
        const afterCommit = hold();
        async function holdFinalSave(route) {
            if (route.request().method() !== 'PATCH') return route.continue();
            const response = await route.fetch();
            afterCommit.release();
            await beforeAck.promise;
            await route.fulfill({ response });
        }
        if (score === 4) {
            for (const key of keys.slice(0, -1)) await setScore(key, score);
            await saveStatus('Tersimpan');
            assert.equal(await control('kinerja').inputValue(), '1');
            assert.equal(await valueLabel('kinerja').textContent(), 'Belum dipilih');
            assert.equal((await readDraft()).scores.kinerja, null);
            assert.equal(await page.locator('#finalize-draft').isDisabled(), true);
            await page.route(draftUrl, holdFinalSave);
        }
        for (const key of keys) await setScore(key, score);
        if (score === 4) {
            await saveStatus('Menunggu penyimpanan');
            assert.equal(await page.locator('#finalize-draft').isDisabled(), true);
            await afterCommit.promise;
            await saveStatus('Menyimpan…');
            assert.equal(await page.locator('#finalize-draft').isDisabled(), true);
            beforeAck.release();
            await saveStatus('Tersimpan');
            await page.unroute(draftUrl, holdFinalSave);
        }
        await enabled('#finalize-draft');
        const id = (await readDraft()).id;
        const posted = waitResponse('/' + id + '/finalize', 'POST');
        await confirm('finalize');
        const response = await posted;
        assert.equal(response.status(), 200);
        assert.equal(response.request().postData(), '{}');
        const { data } = await response.json();
        assert.equal(data.id, id);
        assert.equal(data.total_score, score * 6);
        assert.equal(data.category, score === 4 ? 'Sehat' : 'Sangat Sehat');
        await visible(page.locator('#draft-notice').getByText('Penilaian berhasil difinalkan.').or(page.getByText('Penilaian berhasil difinalkan.', { exact: true })));
        await ready();
        await pause(800);
        assert.equal(await readDraft(), null);
    }
    const history = (await ownApi('')).data;
    const latest = (await ownApi('/latest')).data;
    assert.deepEqual(latest, history[0]);
    assert.deepEqual(history.map((row) => row.total_score), [30, 24]);
    const historicalButton = page.getByRole('button', { name: 'Lihat hasil Final #' + history[1].id });
    await historicalButton.click();
    const historical = page.locator('#historical-result');
    await visible(historical.locator('.apexcharts-radar-series'));
    assert.deepEqual(await historical.locator('dd').allTextContents(), Array(6).fill('4'));
    assert.deepEqual(await page.locator('#latest-result dd').allTextContents(), Array(6).fill('5'));
    assert.equal(await historical.locator('.apexcharts-xaxis-label').count(), 6);
    assert.deepEqual(await historical.locator('.apexcharts-yaxis text').allTextContents(), ['5', '4', '3', '2', '1']);
    await page.keyboard.press('Escape');
    assert.equal(await historicalButton.evaluate((node) => node === document.activeElement), true);
    assert.equal(await overflow(page), 0);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: join(tmpdir(), 'joyumkm-p2-slider-desktop.png'), fullPage: true });
    pass('AC-P2-05,07,08 dua Final melalui UI, histori, radar 1–5, dan panel terbaru');

    await setScore('kinerja', 3);
    await saveStatus('Tersimpan');
    const cancellationId = (await readDraft()).id;
    await page.locator('#cancel-draft').click();
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#cancel-draft').evaluate((node) => node === document.activeElement), true);
    assert.equal((await readDraft()).id, cancellationId);

    const cancelSave = hold();
    const cancelSaveStarted = hold();
    let savesDuringCancel = 0;
    await page.route(draftUrl, async (route) => {
        if (route.request().method() !== 'PATCH') return route.continue();
        savesDuringCancel++;
        const response = await route.fetch();
        cancelSaveStarted.release();
        await cancelSave.promise;
        await route.fulfill({ response });
    });
    await setScore('kinerja', 4);
    await cancelSaveStarted.promise;
    await setScore('stabilitas', 2);
    const cancelled = waitResponse('/' + cancellationId + '/cancel', 'POST');
    await confirm('cancel');
    assert.equal((await readDraft()).scores.kinerja, 4);
    await pause(800);
    cancelSave.release();
    const cancelledResponse = await cancelled;
    assert.equal(cancelledResponse.request().postData(), '{}');
    assert.equal(cancelledResponse.status(), 200);
    assert.deepEqual((await cancelledResponse.json()).data.scores, evaluated([null, null, null, null, null, 4]));
    await ready();
    await pause(800);
    assert.equal(await readDraft(), null);
    assert.equal(savesDuringCancel, 1);
    assert.deepEqual((await ownApi('')).data, history);
    await page.unroute(draftUrl);
    pass('AC-P2-06 Escape dan pembatalan saat penyimpanan berjalan');

    const mobile = await browser.newContext({ viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true, timezoneId: 'Asia/Jakarta' });
    const phone = await mobile.newPage();
    phone.on('pageerror', (error) => pageErrors.push(error.message));
    await login(phone, owner.email);
    await phone.goto(origin + prefix);
    await ready(phone);
    await visible(phone.locator('#latest-result .apexcharts-radar-series'));
    const labelsFit = await phone.locator('#latest-result .apexcharts-xaxis-label').evaluateAll((nodes) => nodes.length === 6 && nodes.every((node) => {
        const bounds = node.getBoundingClientRect();
        const svg = node.ownerSVGElement.getBoundingClientRect();
        return bounds.left >= svg.left - 1 && bounds.right <= svg.right + 1;
    }));
    assert.equal(labelsFit, true, 'six radar labels fit inside the mobile chart');
    const touchSlider = control('stabilitas', phone);
    const touchBounds = await touchSlider.boundingBox();
    await touchSlider.tap({ position: { x: 8, y: touchBounds.height / 2 } });
    assert.equal(await valueLabel('stabilitas', phone).textContent(), '1 / 5');
    await saveStatus('Tersimpan', phone);
    assert.equal((await readDraft()).scores.stabilitas, 1);
    await touchSlider.tap({ position: { x: touchBounds.width - 8, y: touchBounds.height / 2 } });
    assert.equal(await valueLabel('stabilitas', phone).textContent(), '5 / 5');
    await saveStatus('Tersimpan', phone);
    assert.equal((await readDraft()).scores.stabilitas, 5);
    await phone.getByRole('button', { name: 'Kosongkan Stabilitas' }).tap();
    await saveStatus('Tersimpan', phone);
    assert.equal((await readDraft()).scores.stabilitas, null);
    assert.equal(await valueLabel('stabilitas', phone).textContent(), 'Belum dipilih');
    assert.equal(await overflow(phone), 0);
    const draftBox = await phone.locator('section[aria-labelledby="draft-title"]').boundingBox();
    const latestBox = await phone.locator('section[aria-labelledby="latest-title"]').boundingBox();
    assert.ok(latestBox.y > draftBox.y + draftBox.height);
    await phone.evaluate(() => window.scrollTo(0, 0));
    await phone.screenshot({ path: join(tmpdir(), 'joyumkm-p2-slider-mobile.png'), fullPage: true });
    await phone.setViewportSize({ width: 320, height: 680 });
    await phone.locator('#cancel-draft').tap();
    assert.equal(await phone.locator('#draft-confirmation').evaluate((node) => node.scrollWidth <= node.clientWidth), true);
    await phone.locator('#draft-confirmation [data-close-dialog]').tap();
    await mobile.close();
    pass('AC-P2-02,10 Chrome 360 px, layout bertumpuk, sentuhan, fokus dan skor teks');

    const sixFactorUrl = origin + '/api' + prefix;
    const failingReads = new Set(['', '/latest', '/draft']);
    async function failReads(route) {
        const suffix = route.request().url().slice(sixFactorUrl.length);
        if (route.request().method() !== 'GET' || !failingReads.delete(suffix)) return route.continue();
        await route.fulfill({ status: 500, json: { message: 'SQLSTATE PRIVATE TRACE' } });
    }
    const sixFactorRequests = (url) => url.href.startsWith(sixFactorUrl);
    await page.route(sixFactorRequests, failReads);
    await page.reload();
    const loadStates = ['#draft-load', '#latest-result', '#final-history'];
    for (const selector of loadStates) {
        await visible(page.locator(selector).getByText('Terjadi kesalahan pada server. Silakan coba lagi.'));
    }
    assert.equal(await page.getByText('SQLSTATE PRIVATE TRACE').count(), 0);
    for (const selector of loadStates) await page.locator(selector).getByRole('button', { name: 'Coba lagi' }).click();
    await page.unroute(sixFactorRequests, failReads);
    await ready();
    await visible(page.locator('#latest-result .apexcharts-radar-series'));
    pass('AC-P2-09 500 publik dan retry terpisah pada Draft, terbaru dan histori');

    let inject = 'offline';
    async function failSaves(route) {
        if (route.request().method() !== 'PATCH' || !inject) return route.continue();
        const failure = inject;
        inject = '';
        if (failure === 'offline') return route.abort('internetdisconnected');
        if (failure === 'field') {
            return route.continue({ postData: JSON.stringify({ scores: { kinerja: '5' } }) });
        }
        return route.continue({ postData: '{' });
    }
    await page.route(draftUrl, failSaves);
    await setScore('kinerja', 2);
    await saveStatus('Gagal menyimpan');
    assert.equal(await control('kinerja').inputValue(), '2');
    assert.equal((await readDraft()).scores.kinerja, null);
    const leaveWarning = page.waitForEvent('dialog');
    const leaving = page.locator('.workspace-breadcrumb [data-context-umkm]').click();
    const warning = await leaveWarning;
    assert.equal(warning.type(), 'confirm');
    assert.match(warning.message(), /Perubahan belum tersimpan/);
    await warning.dismiss();
    await leaving;
    const refreshWarning = page.waitForEvent('dialog');
    const refreshing = page.evaluate(() => window.location.reload());
    const warningRefresh = await refreshWarning;
    assert.equal(warningRefresh.type(), 'beforeunload');
    await warningRefresh.dismiss();
    await refreshing;
    const logoutWarning = page.waitForEvent('dialog');
    const loggingOut = page.locator('#desktop-sidebar [data-logout]').click();
    const warningLogout = await logoutWarning;
    assert.equal(warningLogout.type(), 'confirm');
    await warningLogout.dismiss();
    await loggingOut;
    assert.equal(new URL(page.url()).pathname, prefix);

    await page.locator('#retry-save').click();
    await saveStatus('Tersimpan');
    assert.equal((await readDraft()).scores.kinerja, 2);
    inject = 'field';
    await setScore('kinerja', 5);
    await saveStatus('Gagal menyimpan');
    await visible(page.locator('#error-kinerja').getByText(/bilangan bulat/));
    assert.equal(await control('kinerja').getAttribute('aria-invalid'), 'true');
    await page.locator('#retry-save').click();
    await saveStatus('Tersimpan');
    inject = 'body';
    await setScore('kinerja', 3);
    await saveStatus('Gagal menyimpan');
    await visible(page.locator('#six-factor-form .form-message').getByText(/JSON/));
    await page.locator('#retry-save').click();
    await saveStatus('Tersimpan');
    await page.unroute(draftUrl, failSaves);
    pass('AC-P2-03,09 offline, input tertahan, peringatan keluar, HTTP 422 field/body dan retry');

    const patchActual = async (scores) => (await ownApi('/draft', { method: 'PATCH', body: { scores } })).data;
    const focus = async (tab) => {
        await tab.bringToFront();
        await tab.evaluate(() => window.dispatchEvent(new Event('focus')));
    };
    const full = evaluated(Array(6).fill(3));
    const active = await patchActual(full);
    const second = await desktop.newPage();
    await second.goto(origin + prefix);
    await ready(second);
    await focus(page);
    await enabled('#finalize-draft');
    await page.locator('#finalize-draft').click();
    await confirm('cancel', second);
    await visible(second.getByText('Draft berhasil dibatalkan.', { exact: true }));
    const replacement = (await ownApi('/draft', { method: 'PATCH', status: 201, body: { scores: full } })).data;
    assert.notEqual(replacement.id, active.id);
    let terminalWrites = 0;
    function countTerminal(request) {
        if (request.method() === 'POST' && request.url().startsWith(sixFactorUrl)) terminalWrites++;
    }
    page.on('request', countTerminal);
    await page.locator('#confirm-draft-action').click();
    await visible(page.getByText(/Draft telah berubah/));
    await ready();
    assert.equal(terminalWrites, 0);
    assert.equal((await readDraft()).id, replacement.id);
    page.off('request', countTerminal);

    let race = 'partial';
    async function updateAfterCheck(route) {
        if (route.request().method() !== 'GET' || !race) return route.continue();
        const response = await route.fetch();
        const current = (await response.json()).data;
        const mode = race;
        race = '';
        if (mode === 'partial') await patchActual({ kinerja: null });
        else await ownApi('/' + current.id + '/cancel', { method: 'POST', body: {} });
        await route.fulfill({ response });
    }
    await page.route(draftUrl, updateAfterCheck);
    const rejected = waitResponse('/' + replacement.id + '/finalize', 'POST');
    await confirm('finalize');
    assert.equal((await rejected).status(), 422);
    await visible(page.locator('#six-factor-form .form-message').getByText('Lengkapi nilai untuk Kinerja.'));
    assert.equal(await valueLabel('kinerja').textContent(), 'Belum dipilih');
    await setScore('kinerja', 3);
    await enabled('#finalize-draft');
    race = 'cancel';
    const conflict = waitResponse('/' + replacement.id + '/finalize', 'POST');
    await confirm('finalize');
    assert.equal((await conflict).status(), 409);
    await visible(page.getByText(/Draft telah berubah/));
    await ready();
    assert.equal(await readDraft(), null);
    await page.unroute(draftUrl, updateAfterCheck);
    pass('AC-P2-09 dua tab dengan ID usang, HTTP 422 Final parsial dan 409 dari API aktual');

    const timedOut = (await ownApi('/draft', { method: 'PATCH', status: 201, body: { scores: full } })).data;
    await focus(page);
    await enabled('#finalize-draft');
    let timedOutRequests = 0;
    const releaseTimeout = hold();
    await page.route(sixFactorUrl + '/' + timedOut.id + '/finalize', async (route) => {
        timedOutRequests++;
        assert.equal((await route.fetch()).status(), 200);
        await releaseTimeout.promise;
        await route.abort();
    });
    await confirm('finalize');
    await visible(page.getByText('Penilaian berhasil difinalkan.', { exact: true }));
    releaseTimeout.release();
    assert.equal(timedOutRequests, 1);
    assert.equal(await readDraft(), null);
    assert.equal((await ownApi('/latest')).data.id, timedOut.id);
    const refreshedHistory = hold();
    const releaseHistory = hold();
    await second.route(sixFactorUrl, async (route) => {
        const response = await route.fetch();
        refreshedHistory.release();
        await releaseHistory.promise;
        await route.fulfill({ response });
    });
    await focus(second);
    await refreshedHistory.promise;
    const oldResult = second.locator('#history-result-' + history[1].id);
    await oldResult.click();
    releaseHistory.release();
    await second.locator('#history-result-' + timedOut.id).waitFor({ state: 'attached' });
    await second.keyboard.press('Escape');
    assert.equal(await oldResult.evaluate((node) => node === document.activeElement), true);
    await second.close();
    pass('AC-P2-09 Final dikomit sebelum timeout fetch dan kedua tab sinkron');

    const unknown = (await ownApi('/draft', { method: 'PATCH', status: 201, body: { scores: { sumber_daya: 3 } } })).data;
    await focus(page);
    await enabled('#cancel-draft');
    await page.route(sixFactorUrl + '/' + unknown.id + '/cancel', async (route) => {
        assert.equal((await route.fetch()).status(), 200);
        await route.abort('connectionreset');
    });
    await confirm('cancel');
    await visible(page.getByText(/Hasil aksi belum dapat dipastikan/));
    assert.equal(await readDraft(), null);
    assert.equal(await page.locator('#cancel-draft').isDisabled(), true);
    pass('AC-P2-09 respons Batal terputus setelah komit, status pasti tidak diklaim');

    const outsiders = await browser.newContext();
    const otherPage = await outsiders.newPage();
    await login(otherPage, outsider.email);
    await otherPage.goto(origin + prefix);
    await visible(otherPage.getByText('Data tidak ditemukan.', { exact: true }));
    assert.equal(await otherPage.locator('#six-factor-evaluation').isVisible(), false);
    assert.equal((await ownApi('/draft', { token: outsider.token, status: 404 })).message, 'Data tidak ditemukan.');
    assert.equal((await request('/umkms/' + sibling.id + '/six-factor-evaluations/' + timedOut.id + '/cancel', {
        token: owner.token, method: 'POST', body: {}, status: 404,
    })).message, 'Data tidak ditemukan.');
    assert.equal((await request('/umkms/' + foreign.id + '/six-factor-evaluations', { token: owner.token, status: 404 })).message, 'Data tidak ditemukan.');
    await outsiders.close();

    await page.goto(origin + '/umkms/' + own.id);
    await page.getByRole('link', { name: 'Isi Kuesioner', exact: true }).click();
    await enabled('#preview-draft');
    await page.locator('#preview-draft').click();
    await page.locator('#save-draft').click();
    await page.waitForFunction(() => document.querySelector('#save-draft')?.closest('dialog')?.open === false);
    const legacy = await request('/umkms/' + own.id + '/evaluations/latest', { token: owner.token });
    assert.equal(legacy.data.status, 'draft');
    assert.equal((await ownApi('/latest')).data.id, timedOut.id);
    await page.goto(origin + '/factor-profile?umkm_id=' + own.id);
    await visible(page.getByRole('heading', { name: 'Profil Faktor', exact: true }));
    await visible(page.getByText('Data faktor belum lengkap', { exact: true }));
    assert.equal(await page.locator('#latest-result').count(), 0);
    const { data: questionnaire } = await request('/questionnaire', { token: owner.token });
    const answers = questionnaire.factors.flatMap((factor) => factor.questions.map((question) => ({
        question_id: question.id, value: question.options.at(-1).value,
    })));
    await request('/umkms/' + own.id + '/evaluations', {
        token: owner.token, method: 'POST', status: 201, body: { status: 'final', answers },
    });
    await page.reload();
    await visible(page.locator('.apexcharts-radar-series'));
    assert.equal(await page.locator('.apexcharts-xaxis-label').count(), 3);
    const legacyScale = (await page.locator('.apexcharts-yaxis text').allTextContents()).map((text) => parseInt(text, 10));
    assert.equal(Math.min(...legacyScale), 0);
    assert.equal(Math.max(...legacyScale), 100);
    pass('AC-P2-08,11 evaluasi legacy melalui UI dan dua akun/salah induk melalui API aktual');

    await page.goto(origin + prefix);
    await ready();
    const browserToken = await page.evaluate(() => localStorage.getItem('joyumkm.token'));
    await request('/auth/logout', { token: browserToken, method: 'POST' });
    const unauthorized = waitResponse('/draft', 'PATCH');
    await setScore('stabilitas', 5);
    assert.equal((await unauthorized).status(), 401);
    await page.waitForURL('**/login');
    assert.equal(await page.evaluate(() => localStorage.getItem('joyumkm.token')), null);
    assert.equal(await readDraft(), null);
    pass('AC-P2-11 token dicabut saat halaman aktif, 401 membersihkan sesi dan menghentikan simpan');

    assert.equal(pageErrors.length, 0, pageErrors.join('\n'));
    console.log(JSON.stringify({ run, browser: browser.version(), checks }));
} finally {
    await browser.close();
}
