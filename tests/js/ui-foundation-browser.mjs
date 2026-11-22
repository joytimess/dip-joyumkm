import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { businessProfile } from './umkm-fixture.mjs';

assert.equal(process.env.JOYUMKM_BROWSER_DB, 'joyumkm_testing');
const { chromium } = createRequire(import.meta.url)(process.env.JOYUMKM_PLAYWRIGHT || 'playwright-core');
const origin = new URL(process.env.JOYUMKM_UI1_ORIGIN || 'http://127.0.0.1:8131').origin;
assert.equal(new URL(origin).hostname, '127.0.0.1');
const baseline = process.argv.includes('--baseline');
const evidence = process.env.JOYUMKM_UI1_EVIDENCE;
const folder = evidence && join(evidence, baseline ? 'baseline' : 'final');
if (folder) await mkdir(folder, { recursive: true });
const browser = await chromium.launch({
    executablePath: process.env.JOYUMKM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
});
const password = 'UI1-local-fixture-only';
const email = `joyumkm-ui1-${randomUUID()}@example.com`;
const longName = 'Koperasi Pengolahan Pangan dan Kerajinan Binaan Kecamatan Sukamaju untuk Pemasaran Produk Usaha Keluarga';
const api = await browser.newContext();
let headers = { Accept: 'application/json' };
const request = async (path, method = 'GET', data) => {
    const response = await api.request.fetch(origin + '/api' + path, { method, data, headers });
    assert.ok(response.ok(), `${method} ${path}: HTTP ${response.status()}`);
    return response.status() === 204 ? null : (await response.json()).data;
};
const businesses = [];
const results = {};
const errors = [];
function luminance(hex) {
    const digits = hex.slice(1);
    const pairs = digits.length === 3 ? Array.from(digits, (digit) => digit.repeat(2)) : digits.match(/../g);
    return pairs.map((pair) => Number.parseInt(pair, 16) / 255)
        .map((value, i) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4) * [0.2126, 0.7152, 0.0722][i])
        .reduce((sum, value) => sum + value);
}

try {
    const account = await request('/auth/register', 'POST', {
        name: 'JoyUMKM UI1 QA', email, password, password_confirmation: password,
    });
    headers = { ...headers, Authorization: 'Bearer ' + account.token };
    const options = await request('/umkms/options');
    const questionnaire = await request('/questionnaire');
    for (const name of ['Usaha QA Sukamaju', longName]) {
        const business = await request('/umkms', 'POST', { name, sector_id: options.sectors[0].id, province: 'DKI Jakarta', omzet: 1000000, ...businessProfile });
        businesses.push(business);
        await request('/umkms/' + business.id + '/evaluations', 'POST', {
            status: 'final',
            answers: questionnaire.factors.flatMap((factor) => factor.questions.map((question) => ({
                question_id: question.id, value: factor.scale_max,
            }))),
        });
    }
    for (const viewport of [{ width: 1440, height: 900 }, { width: 768, height: 1024 }, { width: 390, height: 844 }]) {
        const context = await browser.newContext({ viewport, reducedMotion: viewport.width === 390 ? 'reduce' : 'no-preference' });
        const page = await context.newPage();
        page.setDefaultTimeout(8000);
        page.on('pageerror', (error) => errors.push(error.message));
        const metrics = results[viewport.width] = {};
        const shot = async (name, selector = 'body') => {
            await page.evaluate(() => document.fonts.ready);
            const measurement = await page.waitForFunction((target) => {
                const node = document.querySelector(target);
                const font = node && getComputedStyle(node).fontFamily;
                return font && { font, overflow: document.documentElement.scrollWidth - innerWidth };
            }, selector, { timeout: 8000 });
            const measured = await measurement.jsonValue();
            await measurement.dispose();
            metrics[name] = measured;
            if (!baseline) {
                const missingIcons = await page.evaluate(() => Array.from(document.querySelectorAll('svg use[href^="#joy-i-"]'))
                    .map((node) => node.getAttribute('href').slice(1)).filter((id) => !document.getElementById(id)));
                assert.deepEqual(missingIcons, [], name);
            }
            if (folder) await page.screenshot({ path: join(folder, `${viewport.width}-${name}.png`) });
        };
        const goto = async (path) => {
            const response = await page.goto(origin + path);
            assert.equal(response.status(), 200);
        };
        await goto('/');
        await shot('landing', 'h1');
        if (!baseline) {
            assert.equal(await page.evaluate(() => Array.from(document.fonts).some((face) => face.family === 'Poppins' && face.status === 'loaded')), true);
            if (viewport.width === 390) {
                const timing = await page.locator('.hero-actions .btn').first().evaluate((node) => parseFloat(getComputedStyle(node).transitionDuration));
                assert.ok(timing <= 0.00001, 'reduced-motion transition');
            }
        }
        await goto('/register');
        await shot('register', 'input');
        await goto('/login');
        await shot('login', 'input');
        if (!baseline) {
            const eye = page.getByRole('button', { name: 'Tampilkan kata sandi' });
            await eye.focus();
            await page.keyboard.press('Space');
            assert.equal(await page.locator('#password').getAttribute('type'), 'text');
            await page.keyboard.press('Space');
            assert.equal(await page.locator('#password').getAttribute('type'), 'password');
            assert.equal(await page.locator('#password').getAttribute('autocomplete'), 'current-password');
        }
        await page.locator('#email').fill(email);
        await page.locator('#password').fill(password);
        await page.locator('#auth-form button[type="submit"]').click();
        await page.waitForURL('**/home');
        await page.locator('.apexcharts-canvas').first().waitFor();
        await shot('home', 'h1, #welcome-title');
        await goto('/umkms');
        const compactList = !baseline && viewport.width <= 1050;
        const row = page.locator(compactList ? '#umkm-cards li' : '#umkm-rows tr').filter({ hasText: longName });
        await row.waitFor();
        metrics.longNameFits = await row.getByRole('link', { name: longName, exact: true }).evaluate((node) => {
            const range = document.createRange();
            range.selectNodeContents(node);
            const cell = node.closest('td, li').getBoundingClientRect();
            return Array.from(range.getClientRects()).every((line) => line.left >= cell.left && line.right <= cell.right);
        });
        await shot(compactList ? 'umkm-cards' : 'umkm-table', compactList ? '#umkm-cards' : '#umkm-rows');
        const trigger = row.getByRole('button', { name: `Aksi untuk ${longName}` });
        await trigger.focus();
        await page.keyboard.press('Enter');
        const menu = page.locator('.umkm-action-menu:popover-open');
        const item = menu.getByRole('link', { name: 'Isi Kuesioner' });
        await item.waitFor();
        await shot('umkm-menu-focus', '.umkm-action-menu:popover-open');
        const focus = await item.evaluate((node) => ({
            active: node === document.activeElement,
            visible: node.matches(':focus-visible') && getComputedStyle(node).outlineStyle !== 'none',
        }));
        await page.keyboard.press('Escape');
        await menu.waitFor({ state: 'hidden' });
        assert.equal(await trigger.evaluate((node) => document.activeElement === node), true);
        await trigger.click();
        await item.evaluate((node) => node.blur());
        const edit = menu.getByRole('button', { name: 'Ubah' });
        await edit.hover();
        await shot('umkm-menu-hover', '.umkm-action-menu:popover-open');
        metrics.menu = await menu.evaluate((node) => {
            const [normal, hover, danger] = Array.from(node.querySelectorAll('.row-action'), (item) => getComputedStyle(item));
            const bounds = node.getBoundingClientRect();
            return { surface: getComputedStyle(node).backgroundColor, normal: normal.backgroundColor,
                text: normal.color, hover: hover.backgroundColor, danger: danger.color,
                fits: bounds.left >= 0 && bounds.right <= innerWidth, icons: node.querySelectorAll('svg').length };
        });
        if (!baseline) assert.deepEqual(focus, { active: true, visible: true });
        await page.locator('h1').click();
        await menu.waitFor({ state: 'hidden' });
        await trigger.click();
        await menu.getByRole('button', { name: 'Hapus' }).click();
        const deletion = page.locator('#delete-umkm-modal[open]');
        await deletion.waitFor();
        await shot('umkm-delete-long-name', '#delete-umkm-name');
        await page.keyboard.press('Escape');
        await deletion.waitFor({ state: 'hidden' });
        assert.equal(await trigger.evaluate((node) => document.activeElement === node), true);
        await page.locator('#add-umkm').click();
        await shot('umkm-form-focus', '#umkm-name');
        await page.locator(baseline ? '#save-umkm' : '#umkm-step-next').click();
        await page.locator('#umkm-omzet[aria-invalid="true"]').waitFor();
        await shot('umkm-form-invalid', '#umkm-error-omzet');
        await page.keyboard.press('Escape');
        if (!baseline) {
            await trigger.click();
            await edit.click();
            await page.getByRole('heading', { name: 'Ubah Data UMKM' }).waitFor();
            await page.locator('#umkm-omzet').fill('1000001');
            await page.route('**/api/umkms/' + businesses[1].id, async (route) => {
                await shot('umkm-form-saving', '#save-umkm[aria-busy="true"]:disabled');
                await route.continue();
            }, { times: 1 });
            await page.locator('#save-umkm').click();
            await page.locator('#umkm-modal').waitFor({ state: 'hidden' });
            const toast = page.locator('.toast-success').filter({ hasText: 'berhasil diperbarui' });
            await toast.waitFor();
            await shot('umkm-success-toast', '.toast-success');
            await toast.getByRole('button', { name: 'Tutup notifikasi' }).click();
            await toast.waitFor({ state: 'hidden' });
        }
        await goto('/form-data?umkm=' + businesses[0].id);
        await page.locator('#preview-draft:not(:disabled)').waitFor();
        if (!baseline) {
            const radio = page.locator('.radio-pill input').first();
            await radio.focus();
            assert.equal(await radio.evaluate((node) => getComputedStyle(node.closest('label')).outlineStyle), 'solid');
        }
        await shot('questionnaire', '#evaluation-form');
        await page.locator('#preview-draft').click();
        await page.locator('#draft-preview-modal[open]').waitFor();
        await shot('questionnaire-dialog', '#draft-preview-title');
        for (const [name, query] of [['factor-profile', '?umkm_id=' + businesses[0].id], ['distribution', ''], ['comparison', '']]) {
            await goto('/' + name + query);
            await page.locator('.apexcharts-canvas').first().waitFor();
            await shot(name, '.apexcharts-canvas');
            const chartText = page.locator('.apexcharts-text').first();
            metrics[name].labelFont = await chartText.evaluate((node) => getComputedStyle(node).fontFamily);
            if (!baseline) {
                const point = page.locator(name === 'factor-profile' ? '.apexcharts-series .apexcharts-marker' : '.apexcharts-bar-area').first();
                await point.scrollIntoViewIfNeeded();
                await point.hover({ force: true });
                const tooltip = page.locator('.apexcharts-tooltip.apexcharts-active .chart-tooltip').first();
                await tooltip.waitFor();
                assert.match(await tooltip.innerText(), /100,0\/100/);
                await shot(name + '-tooltip', '.apexcharts-tooltip.apexcharts-active .chart-tooltip');
            }
        }
        for (const [name, path, pattern, selector] of [
            ['home', '/home', '**/api/dashboard/distribution', '#home-health'],
            ['umkm', '/umkms', '**/api/umkms?*', '#umkm-list-state'],
            ['chart', '/distribution', '**/api/dashboard/distribution', '#page-data'],
            ['picker', '/form-data', '**/api/umkms?*', '#umkm-picker'],
        ]) {
            let release;
            const pending = new Promise((resolve) => { release = resolve; });
            const pause = async (route) => { await pending; await route.continue(); };
            await page.route(pattern, pause, { times: 1 });
            try {
                await Promise.all([page.waitForRequest(pattern), goto(path)]);
                await page.locator('.topbar [data-user-name]').filter({ hasText: 'JoyUMKM UI1 QA' }).first().waitFor({ state: 'attached' });
                const container = page.locator(selector);
                await container.locator('.skeleton:visible').first().waitFor();
                await container.scrollIntoViewIfNeeded();
                await shot(name + '-skeleton');
            } finally {
                release();
                await page.unrouteAll({ behavior: 'wait' });
            }
        }
        await context.close();
        console.log('Captured ' + viewport.width + '×' + viewport.height);
    }
    if (!baseline) {
        const touch = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch: true });
        await touch.addInitScript((token) => localStorage.setItem('joyumkm.token', token), account.token);
        await touch.goto(origin + '/umkms');
        const action = touch.locator('#umkm-cards .umkm-icon-button').first();
        await action.waitFor();
        const size = await action.boundingBox();
        assert.ok(size.height >= 44 && size.width >= 44, 'touch action target');
        await action.tap();
        await touch.locator('.umkm-action-menu:popover-open').getByRole('button', { name: 'Hapus' }).tap();
        await touch.locator('#delete-umkm-modal[open]').getByRole('button', { name: 'Batal' }).tap();
        await touch.locator('#delete-umkm-modal').waitFor({ state: 'hidden' });
        await touch.close();

        const fallback = await browser.newPage({ viewport: { width: 390, height: 844 } });
        let blockedFonts = 0;
        await fallback.route(/\.woff2?$/, (route) => { blockedFonts++; return route.abort(); });
        await fallback.goto(origin + '/login');
        await fallback.evaluate(() => document.fonts.ready);
        const fonts = await fallback.locator('h1').evaluate((node) => ({
            family: getComputedStyle(node).fontFamily,
            loaded: Array.from(document.fonts).some((face) => face.family === 'Poppins' && face.status === 'loaded'),
            overflow: document.documentElement.scrollWidth - innerWidth,
        }));
        assert.match(fonts.family, /sans-serif/);
        assert.ok(blockedFonts > 0, 'font requests were blocked');
        assert.equal(fonts.loaded, false);
        assert.ok(fonts.overflow <= 0, 'login fallback overflow: ' + fonts.overflow);
        const contrast = await fallback.evaluate(() => {
            const styles = getComputedStyle(document.documentElement);
            return [
                ['brand-primary', 'surface'], ['text-primary', 'surface'], ['text-muted', 'surface'],
                ['text-muted', 'canvas'], ['status-good', 'status-good-surface'], ['status-warn', 'status-warn-surface'],
                ['status-error', 'status-error-surface'],
            ].map((pair) => pair.map((token) => styles.getPropertyValue('--' + token).trim()));
        });
        const ratios = contrast.map(([foreground, background]) => {
            const [dark, light] = [luminance(foreground), luminance(background)].sort((a, b) => a - b);
            return { foreground, background, ratio: (light + .05) / (dark + .05) };
        });
        for (const pair of ratios) assert.ok(pair.ratio >= 4.5, JSON.stringify(pair));
        if (folder) {
            await fallback.screenshot({ path: join(folder, '390-login-font-fallback.png') });
            await writeFile(join(folder, 'contrast.json'), JSON.stringify(ratios, null, 2));
        }
        await fallback.close();
    }
    if (folder) await writeFile(join(folder, 'measurements.json'), JSON.stringify(results, null, 2));
    assert.deepEqual(errors, []);
    for (const metrics of Object.values(results)) {
        if (!baseline) {
            for (const [name, measurement] of Object.entries(metrics)) {
                if (name === 'menu' || name === 'longNameFits') continue;
                assert.match(measurement.font, /Poppins/i, name);
                assert.ok(measurement.overflow <= 0, name + ' overflows');
                if (measurement.labelFont) assert.match(measurement.labelFont, /Poppins/i, name + ' label');
            }
            assert.equal(metrics.longNameFits, true, 'long UMKM name stays inside its cell');
            assert.deepEqual(metrics.menu, { surface: 'rgb(255, 255, 255)', normal: 'rgba(0, 0, 0, 0)',
                text: 'rgb(16, 30, 64)', hover: 'rgb(238, 241, 245)', danger: 'rgb(165, 33, 53)', fits: true, icons: 3 });
        }
    }
} finally {
    try {
        for (const business of businesses) await request('/umkms/' + business.id, 'DELETE');
        if (businesses.length) await request('/auth/logout', 'POST');
    } finally {
        await browser.close();
    }
}
