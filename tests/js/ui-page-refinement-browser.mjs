import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { join } from 'node:path';

assert.equal(process.env.JOYUMKM_BROWSER_DB, 'joyumkm_testing');
const { chromium } = createRequire(import.meta.url)(process.env.JOYUMKM_PLAYWRIGHT || 'playwright-core');
const origin = 'http://127.0.0.1:8129';
const executablePath = process.env.JOYUMKM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const baseline = process.argv.includes('--before');
const evidence = process.env.JOYUMKM_UI3_EVIDENCE;
const folder = evidence && join(evidence, baseline ? 'before' : 'after');
if (folder) await mkdir(folder, { recursive: true });
const browser = await chromium.launch({ executablePath });
const apiContext = await browser.newContext();
const password = 'UI3-local-fixture-only';
const email = `joyumkm-ui3-${randomUUID()}@example.com`;
let headers = { Accept: 'application/json' };
const request = async (path, method = 'GET', data) => {
    const response = await apiContext.request.fetch(origin + '/api' + path, { method, data, headers });
    assert.ok(response.ok(), `${method} ${path}: HTTP ${response.status()}`);
    return response.status() === 204 ? null : (await response.json()).data;
};
const fixtures = [];
let finalizedFixture;
const results = [];
const errors = [];
try {
    const account = await request('/auth/register', 'POST', { name: 'Pendamping QA UI-3', email, password, password_confirmation: password });
    headers = { ...headers, Authorization: 'Bearer ' + account.token };
    const { sectors } = await request('/umkms/options');
    const questionnaire = await request('/questionnaire');
    for (const name of ['Koperasi Pangan Nusantara dan Sentra Olahan Binaan Kecamatan Sukamaju', 'Usaha Anyaman Sukamaju']) {
        fixtures.push(await request('/umkms', 'POST', { name, sector_id: sectors[0].id, province: 'DKI Jakarta', omzet: 12000000 }));
    }
    const [scored, unscored] = fixtures;
    const answers = questionnaire.factors.flatMap((factor) => factor.questions.map((question) => ({ question_id: question.id, value: factor.scale_max })));
    await request(`/umkms/${scored.id}/evaluations`, 'POST', { status: 'final', answers });
    const six = `/umkms/${scored.id}/six-factor-evaluations`;
    const scores = { sumber_daya: 1, kepemimpinan: 2, lingkungan_kerja: 3, nilai_organisasi: 4, stabilitas: 5, kinerja: 4 };
    const draft = await request(six + '/draft', 'PATCH', { scores });
    await request(`${six}/${draft.id}/finalize`, 'POST');
    finalizedFixture = scored.id;
    const portfolio = await request('/umkms/summary');
    for (const viewport of [{ width: 1440, height: 900 }, { width: 768, height: 1024 }, { width: 390, height: 844 }, { width: 320, height: 720 }]) {
        const context = await browser.newContext({ viewport, reducedMotion: 'reduce' });
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        page.on('pageerror', (error) => errors.push(error.message));
        const open = async (path) => assert.equal((await page.goto(origin + path)).status(), 200);
        const capture = async (name, selector, extra = {}) => {
            await page.locator(selector).first().waitFor();
            await page.evaluate(() => document.fonts.ready);
            const metrics = await page.evaluate(() => ({
                overflow: document.documentElement.scrollWidth - innerWidth,
                headings: [...document.querySelectorAll('h1')].filter((node) => node.getClientRects().length).length,
                font: getComputedStyle(document.body).fontFamily,
                emailTop: document.getElementById('email')?.getBoundingClientRect().top ?? null,
            }));
            const screenshot = folder ? `${viewport.width}-${name}.png` : null;
            if (!baseline) {
                assert.ok(metrics.overflow <= 1, `${viewport.width} ${name}: overflow ${metrics.overflow}`);
                assert.equal(metrics.headings, 1, name);
                assert.match(metrics.font, /Poppins/);
            }
            const fullPage = ['landing', 'login', 'register'].includes(name) || (!baseline && /^questionnaire-[1-3]$/.test(name));
            if (screenshot) await page.screenshot({ path: join(folder, screenshot), fullPage });
            const { pathname, search } = new URL(page.url());
            results.push({ name, route: pathname + search, viewport, screenshot, capturedAt: new Date().toISOString(), ...metrics, ...extra });
        };
        await open('/');
        await capture('landing', '.hero h1');
        if (!baseline) {
            await page.getByRole('link', { name: 'Mulai Mendampingi' }).click();
            await page.waitForURL('**/register');
        } else await open('/register');
        await capture('register', '#email');
        if (!baseline && viewport.width === 390) {
            await page.locator('#auth-form button[type="submit"]').click();
            await capture('register-invalid', '[aria-invalid="true"]');
            const passwordInput = page.locator('#password');
            await passwordInput.fill(password);
            await page.locator('[aria-controls="password"]').focus();
            await page.keyboard.press('Space');
            assert.equal(await passwordInput.getAttribute('type'), 'text');
            assert.match(await page.locator('#password-strength-label').innerText(), /kekuatan|kuat|sedang/i);
            await page.keyboard.press('Space');
        }
        await open('/login');
        await capture('login', '#email');
        if (!baseline && viewport.width <= 390) {
            assert.ok(await page.locator('#email').evaluate((node) => node.getBoundingClientRect().top < innerHeight / 2), 'Email terlihat di paruh atas layar');
            assert.ok(await page.locator('#auth-form button[type="submit"]').evaluate((node) => node.getBoundingClientRect().bottom < innerHeight), 'Tombol Masuk terlihat tanpa menggulir');
        }
        if (!baseline && viewport.width === 390) {
            await page.locator('#auth-form button[type="submit"]').click();
            await capture('login-invalid', '[aria-invalid="true"]');
            await page.locator('#email').fill(email);
            await page.locator('#password').fill('tidak-cocok');
            const denied = page.waitForResponse((response) => response.url().endsWith('/api/auth/login'));
            await page.locator('#auth-form button[type="submit"]').click();
            assert.equal((await denied).status(), 401);
            await capture('login-denied', '#form-message:not([hidden])');
            await page.route('**/api/auth/login', (route) => route.abort('internetdisconnected'), { times: 1 });
            await page.locator('#auth-form button[type="submit"]').click();
            await capture('login-network-error', '#form-message:not([hidden])');
        }
        await page.locator('#email').fill(email);
        await page.locator('#password').fill(password);
        const submit = page.locator('#auth-form button[type="submit"]');
        if (!baseline && viewport.width === 390) {
            let release;
            const pending = new Promise((resolve) => { release = resolve; });
            await page.route('**/api/auth/login', async (route) => { await pending; await route.continue(); }, { times: 1 });
            try {
                await submit.click();
                await capture('login-submitting', '#auth-form button[type="submit"][aria-busy="true"]');
            } finally { release(); }
        } else await submit.click();
        await page.waitForURL('**/home');
        await page.locator('#home-health .apexcharts-canvas').waitFor();
        await capture('home', '#umkm-summary[aria-busy="false"]');
        if (!baseline) assert.match(await page.locator('#summary-cards').innerText(), new RegExp(`${portfolio.total_respondents} usaha dengan evaluasi`));
        await open('/umkms');
        await capture('umkms', '#umkm-table-area');
        if (!baseline && viewport.width === 390) {
            await page.locator('#add-umkm').click();
            await capture('umkm-create', '#umkm-modal[open]');
            await page.keyboard.press('Escape');
            await page.locator('#umkm-cards .umkm-icon-button').first().click();
            await page.locator('.umkm-action-menu:popover-open').getByRole('button', { name: 'Ubah' }).click();
            await capture('umkm-edit', '#umkm-modal[open]');
            await page.keyboard.press('Escape');
        }
        for (const [name, business] of [['detail-scored', scored], ['detail-unscored', unscored]]) {
            await open(`/umkms/${business.id}?list=search%3DSukamaju`);
            await capture(name, '#umkm-detail-content');
            if (!baseline) {
                assert.equal(await page.locator('.workspace-breadcrumb a').getAttribute('href').then((url) => new URL(url).searchParams.get('search')), 'Sukamaju');
                assert.match(await page.locator('#umkm-detail-content').innerText(), /Kuesioner Tiga Dimensi/);
                assert.match(await page.locator('#detail-assessment-score').innerText(), business === scored ? /100,0\/100/ : /Skor belum tersedia/);
            }
        }
        await open('/form-data');
        await capture('questionnaire-empty', '#evaluation-empty');
        if (!baseline) {
            assert.equal(await page.locator('.evaluation-sidebar').isVisible(), false);
            if (viewport.width === 390) await capture('questionnaire-picker-ready', '[data-picker-select]:enabled');
        }
        await open('/form-data?umkm=' + scored.id);
        await page.locator('[data-question-id]:visible').first().waitFor();
        if (!baseline) {
            const progress = await page.locator('#evaluation-progress').evaluate((node) => ({ value: node.value, max: node.max }));
            assert.equal(progress.value, progress.max);
        }
        for (let index = 0; index < 3; index++) {
            if (index) await page.locator('#next-factor').click();
            await capture(`questionnaire-${index + 1}`, '[data-question-id]:visible');
        }
        if (!baseline && viewport.width === 390) {
            await page.locator('[data-question-id]:visible').first().check();
            await page.locator('#evaluation-status').getByText(/belum disimpan/).waitFor();
            const confirmation = page.waitForEvent('dialog');
            const goBack = page.locator('[data-context-breadcrumb] [data-context-umkm]').click();
            const dialog = await confirmation;
            assert.match(dialog.message(), /belum disimpan/);
            await dialog.dismiss();
            await goBack;
            await page.locator('#preview-draft').click();
            await capture('questionnaire-draft-preview', '#draft-preview-modal[open]');
            await page.locator('#save-draft').click();
            await page.getByText(/Draft sementara tersimpan/).waitFor();
            await page.locator('#save-evaluation').click();
            await page.getByText(/Evaluasi final tersimpan/).waitFor();
            await capture('questionnaire-final', '#evaluation-server-result');
            await request(`/umkms/${scored.id}/evaluations`, 'POST', { status: 'final', answers });
        }
        await open(six);
        await page.locator('#draft-scores input[type="range"]').first().waitFor();
        await capture('six-factor', '#latest-result .apexcharts-canvas');
        if (!baseline) {
            const slider = page.locator('#draft-scores input[type="range"]').first();
            const geometry = await slider.evaluate((node) => ({
                width: node.getBoundingClientRect().width,
                scaleWidth: node.nextElementSibling.getBoundingClientRect().width,
                value: node.getAttribute('aria-valuetext'),
            }));
            assert.equal(geometry.width, geometry.scaleWidth);
            assert.ok(geometry.width > 129);
            assert.equal(geometry.value, 'Belum dipilih');
            const overlap = await page.locator('#latest-result .apexcharts-radar-series').evaluate((series) => {
                const svg = series.ownerSVGElement;
                const bounds = [...svg.querySelectorAll('.apexcharts-xaxis-label, .apexcharts-yaxis text')]
                    .map((node) => node.getBoundingClientRect());
                return bounds.some((a, index) => bounds.slice(index + 1).some((b) =>
                    Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1
                    && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1));
            });
            assert.equal(overlap, false, 'label radar tidak bertumpuk');
            if (viewport.width === 390) {
                await slider.focus();
                await slider.press('Home');
                await page.getByText('Tersimpan', { exact: true }).waitFor();
                assert.equal(await slider.getAttribute('aria-valuetext'), '1 dari 5');
                await capture('six-factor-minimum', '#score-value-sumber_daya');
                await page.getByRole('button', { name: 'Kosongkan Sumber Daya' }).click();
                await page.getByText('Tersimpan', { exact: true }).waitFor();
                assert.equal(await slider.getAttribute('aria-valuetext'), 'Belum dipilih');
            }
            await page.locator('#final-history button').first().click();
            await capture('six-factor-history', '#historical-result[open] .apexcharts-canvas');
            await page.keyboard.press('Escape');
        }
        for (const [name, path] of [
            ['factor-profile', '/factor-profile?umkm_id=' + scored.id],
            ['distribution', '/distribution'],
            ['comparison', `/comparison?umkm_ids[]=${scored.id}&umkm_ids[]=${unscored.id}`],
        ]) {
            await open(path);
            await capture(name, '.apexcharts-canvas');
            if (!baseline && name === 'factor-profile') assert.match(await page.locator('[data-profile-average]').innerText(), /100,0\s*\/100/);
            if (!baseline && viewport.width === 390) {
                const point = page.locator(name === 'factor-profile' ? '.apexcharts-series .apexcharts-marker' : '.apexcharts-bar-area').first();
                await point.scrollIntoViewIfNeeded();
                await point.hover({ force: true });
                await capture(name + '-tooltip', '.apexcharts-tooltip.apexcharts-active .chart-tooltip');
            }
        }
        if (!baseline && viewport.width === 390) {
            await open('/factor-profile?umkm_id=' + unscored.id);
            await capture('factor-profile-unscored', '[data-profile-average]');
            assert.equal(await page.locator('.apexcharts-canvas').count(), 0);
            assert.equal(await page.getByText('Skor keseluruhan belum tersedia').count(), 1);
            await open('/comparison?umkm_ids=');
            await capture('comparison-empty', '#page-data .empty-state');
            await open('/comparison?umkm_ids[]=' + unscored.id);
            await capture('comparison-single', '.chart-table');
            assert.match(await page.locator('.chart-table').innerText(), /Belum dijawab/);
            await page.route('**/api/dashboard/distribution', (route) => route.fulfill({ status: 503 }), { times: 1 });
            await open('/distribution');
            await capture('distribution-error', '#page-data .error-state');
            await page.locator('#page-data').getByRole('button', { name: 'Coba lagi' }).click();
            await page.locator('.apexcharts-canvas').first().waitFor();
        }
        await context.close();
        console.log(`PASS ${viewport.width}×${viewport.height}`);
    }
    if (!baseline) {
        const zoom = await chromium.launchPersistentContext('', { executablePath, viewport: { width: 1440, height: 900 } });
        try {
            const page = zoom.pages()[0];
            await page.goto('chrome://settings/appearance');
            await page.getByRole('combobox', { name: /page zoom/i }).selectOption({ label: '200%' });
            await page.goto(origin + '/login');
            assert.equal(await page.evaluate(() => innerWidth), 720);
            assert.equal(await page.evaluate(() => devicePixelRatio), 2);
            await page.locator('#email').fill(email);
            await page.locator('#password').fill(password);
            await page.locator('#auth-form button[type="submit"]').click();
            await page.waitForURL('**/home');
            for (const [name, path, selector] of [
                ['home', '/home', '#summary-cards .metric-value'],
                ['umkms', '/umkms', '#umkm-cards li'],
                ['questionnaire', '/form-data?umkm=' + scored.id, '[data-question-id]:visible'],
                ['six-factor', six, '#draft-scores input[type="range"]'],
                ['comparison', '/comparison', '.apexcharts-canvas'],
            ]) {
                await page.goto(origin + path);
                await page.locator(selector).first().waitFor();
                const metrics = await page.evaluate(() => ({ width: innerWidth, height: innerHeight, overflow: document.documentElement.scrollWidth - innerWidth }));
                assert.ok(metrics.overflow <= 1, `Zoom 200% ${name}: overflow ${metrics.overflow}`);
                const screenshot = folder ? `zoom200-${name}.png` : null;
                if (screenshot) await page.screenshot({ path: join(folder, screenshot) });
                results.push({ name, route: new URL(page.url()).pathname, screenshot, zoom: 2, capturedAt: new Date().toISOString(), ...metrics });
            }
        } finally {
            await zoom.close();
        }
        console.log('PASS Chrome page zoom 200%');
    }
    assert.deepEqual(errors, []);
} finally {
    if (folder) await writeFile(join(folder, 'manifest.json'), JSON.stringify(results, null, 2));
    // The existing API protects UMKM with a Final six-factor evaluation from deletion.
    for (const fixture of fixtures.filter(({ id }) => id !== finalizedFixture)) await request('/umkms/' + fixture.id, 'DELETE');
    if (headers.Authorization) await request('/auth/logout', 'POST');
    await browser.close();
}
