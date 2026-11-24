import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { reportFixture, unsafeText } from './intelligence-fixture.mjs';

const { chromium } = createRequire(import.meta.url)(process.env.JOYUMKM_PLAYWRIGHT || 'playwright-core');
const origin = 'http://127.0.0.1:8138';
const executablePath = process.env.JOYUMKM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const evidence = process.env.JOYUMKM_INTELLIGENCE_EVIDENCE;
if (evidence) await mkdir(evidence, { recursive: true });
const browser = await chromium.launch({ executablePath });
const sector = { id: 1, name: 'Jasa' };
const awaitReport = { status: 'awaiting_report', data: null };
const availableReport = (id, umkmId) => {
    const data = reportFixture(id, umkmId);
    data.report.executive_summary = `Ringkasan evaluasi ${id} untuk usaha ${umkmId}. Pencatatan kas yang tersebar menyulitkan pemilik membandingkan pengeluaran setiap bulan. Mulai dari satu catatan harian, lalu tinjau hasilnya setelah 30 hari.`;
    data.report.perspectives.finance.findings.push({ type: 'fact', statement: unsafeText });
    return { status: 'available', data };
};
const historyPage = (page, umkmId, total = 13) => ({
    data: Array.from({ length: Math.max(0, Math.min(10, total - (page - 1) * 10)) }, (_, index) => {
        const { report, ...metadata } = reportFixture(14 - (page - 1) * 10 - index, umkmId);
        return metadata;
    }),
    meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / 10)), per_page: 10, total },
});
const waitText = (locator, value) => locator.getByText(value, { exact: true }).first().waitFor();
const navigateToOtherUmkm = (page) => page.evaluate(() => {
    const link = document.createElement('a');
    link.href = '/umkms/6';
    document.getElementById('main-content').append(link);
    link.click();
});
const delayed = () => {
    let resolve;
    const promise = new Promise((release) => { resolve = release; });
    return { promise, resolve };
};

async function visit(viewport, reducedMotion = 'reduce') {
    const context = await browser.newContext({ viewport, reducedMotion });
    const page = await context.newPage();
    const seen = [];
    const errors = [];
    const behavior = {};
    page.on('pageerror', (error) => errors.push(error.message));
    await context.addInitScript(() => {
        if (sessionStorage.getItem('intelligence-browser-fixture')) return;
        sessionStorage.setItem('intelligence-browser-fixture', '1');
        localStorage.setItem('joyumkm.token', 'intelligence-browser-fixture');
    });
    await context.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin === origin && request.method() === 'GET' && !url.pathname.startsWith('/api/')) {
            return route.continue();
        }
        const respond = (json, status = 200) => route.fulfill({ json, status });
        if (url.origin !== origin || request.method() !== 'GET'
            || request.headers().authorization !== 'Bearer intelligence-browser-fixture') {
            errors.push(`Unexpected request: ${request.method()} ${url.pathname}`);
            return route.abort();
        }
        const { pathname, searchParams } = url;
        seen.push(pathname + url.search);
        if (pathname === '/api/auth/me') return respond({ data: { id: 1, name: 'Pemilik Contoh' } });
        if (pathname === '/api/umkms/options') return respond({ data: { sectors: [sector], provinces: ['DKI Jakarta'] } });
        const match = pathname.match(/^\/api\/umkms\/(5|6)(?:\/(.*))?$/);
        const id = Number(match?.[1]);
        const suffix = match?.[2];
        if (id && !suffix) {
            return respond({ data: {
                id, name: `Usaha Contoh ${id}`, sector, province: 'DKI Jakarta', omzet: 0,
                latitude: null, longitude: null, latest_score: null, evaluation_status: 'none',
            } });
        }
        if (suffix === 'six-factor-evaluations/draft' || suffix === 'six-factor-evaluations/latest') {
            return respond({ data: null });
        }
        if (suffix === 'intelligence-reports/latest') {
            const override = behavior.latest;
            if (override) return override({ route, respond, id });
            const payload = availableReport(14, id);
            payload.data.report.limitations.push('TeksPanjang'.repeat(40));
            return respond(payload);
        }
        if (suffix === 'intelligence-reports') {
            const requestedPage = Number(searchParams.get('page'));
            assert.equal(searchParams.get('per_page'), '10');
            if (behavior.history) return behavior.history({ respond, requestedPage, id });
            return respond(historyPage(requestedPage, id));
        }
        const reportId = Number(suffix?.match(/^intelligence-reports\/(\d+)$/)?.[1]);
        if (reportId) {
            if (behavior.detail) return behavior.detail({ respond, reportId, id });
            return respond({ data: reportFixture(reportId, id) });
        }
        errors.push(`Unexpected API path: ${pathname}`);
        return route.abort();
    });
    const response = await page.goto(origin + '/umkms/5');
    assert.equal(response.status(), 200);
    await page.locator('#panel-profil').waitFor();
    return { page, context, seen, errors, behavior };
}

const sizes = [
    { width: 1440, height: 900 },
    { width: 768, height: 1024 },
    { width: 390, height: 844 },
];

async function verifyMotion(viewport) {
    const { page, context, errors } = await visit(viewport, 'no-preference');
    await page.getByRole('tab', { name: 'Intelligence' }).click();
    await waitText(page, 'Ringkasan Eksekutif');
    const short = page.locator('details').filter({ has: page.locator('summary', { hasText: 'Operasional dan SDM' }) });
    const long = page.locator('details').filter({ has: page.locator('#intelligence-latest-limitations') });
    const screenshot = async (state) => {
        if (evidence) await page.screenshot({ path: join(evidence, `${viewport.width}-${state}.png`) });
    };

    async function sample(disclosure, opens, capture = false) {
        const summary = disclosure.locator('summary');
        const body = disclosure.locator('.intelligence-disclosure-body');
        await summary.evaluate((node) => node.scrollIntoView({ block: 'start' }));
        const before = await disclosure.evaluate((node) => node.getBoundingClientRect().height);
        const timing = await disclosure.evaluate((node, pause) => {
            const [summary, body] = node.children;
            if (node.open) {
                body.tabIndex = 0;
                body.focus({ preventScroll: true });
            }
            summary.click();
            const animation = node.getAnimations()[0];
            const { easing, duration } = animation.effect.getTiming();
            if (pause) {
                animation.pause();
                animation.currentTime = 0;
            }
            return { easing, duration, open: node.open, inert: body.inert, summaryFocused: document.activeElement === summary };
        }, capture);
        assert.equal(timing.duration, 240);
        assert.equal(timing.easing, 'ease-in-out');
        assert.equal(timing.open, true);
        assert.equal(timing.inert, !opens);
        if (!opens) assert.equal(timing.summaryFocused, true);

        let middle;
        if (capture) {
            const direction = opens ? 'open' : 'close';
            await screenshot(`accordion-${direction}-start`);
            middle = await disclosure.evaluate((node) => {
                node.getAnimations()[0].currentTime = 120;
                return node.getBoundingClientRect().height;
            });
            await screenshot(`accordion-${direction}-middle`);
            await disclosure.evaluate((node) => node.getAnimations()[0].finish());
            await disclosure.evaluate((node) => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            await screenshot(`accordion-${direction}-end`);
        } else {
            const heights = await disclosure.evaluate((node) => new Promise((resolve) => {
                const heights = [];
                function frame() {
                    if (!node.getAnimations().length) { resolve(heights); return; }
                    heights.push(node.getBoundingClientRect().height);
                    requestAnimationFrame(frame);
                }
                requestAnimationFrame(frame);
            }));
            assert.ok(heights.length >= 2);
            middle = heights[Math.floor(heights.length / 2)];
        }
        const final = await disclosure.evaluate((node) => ({
            height: node.getBoundingClientRect().height, open: node.open,
            animations: node.getAnimations().length, overflow: node.style.overflow,
        }));
        assert.ok(opens ? before < middle && middle < final.height : before > middle && middle > final.height);
        assert.equal(final.open, opens);
        assert.equal(final.animations, 0);
        assert.equal(final.overflow, '');
        assert.equal(await body.isVisible(), opens);
    }

    await sample(short, true);
    await sample(short, false);
    await sample(long, true, viewport.width === 1440);
    await sample(long, false, viewport.width === 1440);

    await long.evaluate((node) => {
        const summary = node.querySelector('summary');
        summary.click();
        const opening = node.getAnimations()[0];
        opening.pause();
        opening.currentTime = 80;
        const visibleHeight = node.getBoundingClientRect().height;
        summary.click();
        const closing = node.getAnimations()[0];
        if (Math.abs(parseFloat(closing.effect.getKeyframes()[0].height) - visibleHeight) >= 1) throw new Error('Close jumped');
        closing.pause();
        closing.currentTime = 50;
        const closingHeight = node.getBoundingClientRect().height;
        summary.click();
        const reopening = node.getAnimations()[0];
        if (Math.abs(parseFloat(reopening.effect.getKeyframes()[0].height) - closingHeight) >= 1) throw new Error('Reopen jumped');
        reopening.pause();
        reopening.currentTime = 80;
        if (node.children[1].inert || summary.getAttribute('aria-expanded') !== 'true') throw new Error('Stale disclosure state');
    });
    const target = await long.evaluate((node) => node.getAnimations()[0].effect.getKeyframes().at(-1).height);
    await page.setViewportSize({ ...viewport, width: viewport.width === 1440 ? 390 : 768 });
    await page.waitForFunction((oldHeight) => {
        const [animation] = document.querySelector('#intelligence-latest-limitations').parentElement.getAnimations();
        return animation && animation.effect.getKeyframes().at(-1).height !== oldHeight;
    }, target, { polling: 'raf' });
    await long.evaluate((node) => node.getAnimations()[0].pause());
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.waitForFunction(() => !document.querySelector('#intelligence-latest-limitations').parentElement.getAnimations().length);
    assert.equal(await long.getAttribute('open'), '');
    await long.locator('summary').click();
    assert.equal(await long.getAttribute('open'), null);
    assert.equal(await long.evaluate((node) => node.getAnimations().length), 0);

    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await long.evaluate((node) => {
        node.querySelector('summary').click();
        node.getAnimations()[0].pause();
    });
    await page.getByRole('tab', { name: 'Profil Usaha' }).click();
    assert.equal(await long.evaluate((node) => node.getAnimations().length), 0);
    await page.getByRole('tab', { name: 'Intelligence' }).click();
    assert.equal(await long.getAttribute('open'), '');
    const previous = await long.elementHandle();
    await long.evaluate((node) => {
        node.querySelector('summary').click();
        node.getAnimations()[0].pause();
    });
    await page.getByRole('button', { name: 'Muat ulang' }).click();
    assert.deepEqual(await previous.evaluate((node) => [node.isConnected, node.getAnimations().length]), [false, 0]);
    await waitText(page, 'Ringkasan Eksekutif');
    if (viewport.width === 1440) {
        const departing = await long.elementHandle();
        await long.evaluate((node) => {
            node.querySelector('summary').click();
            node.getAnimations()[0].pause();
        });
        await navigateToOtherUmkm(page);
        await page.waitForURL('**/umkms/6');
        assert.deepEqual(await departing.evaluate((node) => [node.isConnected, node.getAnimations().length]), [false, 0]);
    }
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS Chrome ${browser.version()} motion ${viewport.width}×${viewport.height}`);
}

try {
    for (const viewport of sizes) {
        const { page, context, seen, errors, behavior } = await visit(viewport);
        const tabs = page.getByRole('tablist', { name: 'Bagian detail UMKM' });
        const profile = tabs.getByRole('tab', { name: 'Profil Usaha' });
        const intelligence = tabs.getByRole('tab', { name: 'Intelligence' });
        const panel = page.getByRole('tabpanel', { name: 'Intelligence' });
        const latest = page.locator('[data-intelligence-latest]');
        const reportMeta = page.locator('[data-intelligence-report-meta]');
        const selection = page.locator('[data-intelligence-selection]');
        const history = page.locator('[data-intelligence-history]');
        const refresh = panel.getByRole('button', { name: 'Muat ulang' });
        const reportCalls = () => seen.filter((path) => path.includes('intelligence-reports'));
        const screenshot = async (state) => {
            if (evidence) await page.screenshot({ path: join(evidence, `${viewport.width}-${state}.png`) });
        };

        assert.equal(await profile.getAttribute('aria-selected'), 'true');
        assert.equal(await intelligence.getAttribute('aria-controls'), 'panel-intelligence');
        await tabs.getByRole('tab', { name: 'Lokasi Usaha' }).click();
        await waitText(page.locator('#panel-lokasi'), 'Titik koordinat belum tersedia. Gunakan Edit Lokasi untuk memilih titik di peta.');
        await tabs.getByRole('tab', { name: 'Kontak Usaha' }).click();
        await page.getByRole('heading', { name: 'Kontak Usaha' }).waitFor();
        await tabs.getByRole('tab', { name: 'Penilaian' }).click();
        await waitText(page.locator('#panel-penilaian'), 'Belum ada penilaian final.');
        assert.equal(reportCalls().length, 0);

        const first = delayed();
        behavior.latest = async ({ respond, id }) => {
            await first.promise;
            const result = availableReport(14, id);
            result.data.report.limitations.push('TeksPanjang'.repeat(40));
            return respond(result);
        };
        await tabs.getByRole('tab', { name: 'Penilaian' }).focus();
        await page.keyboard.press('End');
        await latest.locator('[aria-hidden="true"]').waitFor();
        assert.equal(await latest.getAttribute('aria-busy'), 'true');
        assert.equal(await refresh.getAttribute('aria-disabled'), 'true');
        await refresh.dispatchEvent('click');
        assert.equal(await intelligence.evaluate((node) => node === document.activeElement), true);
        assert.equal(await intelligence.getAttribute('tabindex'), '0');
        assert.equal(await page.locator('#app-shell').isVisible(), true);
        first.resolve();
        await waitText(latest, 'Ringkasan Eksekutif');
        await waitText(history, 'Halaman 1 dari 2');
        assert.equal(reportCalls().filter((path) => path.endsWith('/latest')).length, 1);
        assert.equal(await history.getByRole('button', { name: /Lihat laporan/ }).count(), 10);
        assert.ok((await history.getByRole('button', { name: /Lihat laporan/ }).first().boundingBox()).height >= 44);
        assert.ok((await latest.textContent()).includes(unsafeText));
        assert.equal(await latest.locator('img, script').count(), 0);
        assert.equal(await page.evaluate(() => window.reportExecuted), undefined);
        const browserState = await page.evaluate(() => JSON.stringify([localStorage, sessionStorage, location.href]));
        assert.doesNotMatch(browserState, /Ringkasan evaluasi|reportExecuted/);
        await waitText(latest, '3 dari 6 skor faktor tersedia untuk visualisasi.');
        await page.evaluate(() => document.fonts.ready);
        const metrics = await latest.locator('.intelligence-metrics dd').evaluateAll((values) => values.map((value) => ({
            height: value.getBoundingClientRect().height,
            lineHeight: parseFloat(getComputedStyle(value).lineHeight),
            scrollWidth: value.scrollWidth,
            width: value.clientWidth,
        })));
        assert.ok(metrics.every(({ height, lineHeight, scrollWidth, width }) => height <= Math.ceil(lineHeight) + 1
            && scrollWidth <= width + 1), JSON.stringify(metrics));
        assert.equal(await latest.getByRole('meter').count(), 6);
        assert.equal(await latest.getByRole('meter', { name: 'INS' }).getAttribute('aria-valuetext'), '83,33 dari skala 0–100');
        const priority = latest.locator('summary').filter({ hasText: 'Rapikan pencatatan harian' });
        assert.deepEqual(await latest.locator('.intelligence-priority-rank').allTextContents(), ['01', '02']);
        const priorityBody = priority.locator('..').locator('.intelligence-disclosure-body');
        assert.equal(await priority.getAttribute('aria-expanded'), 'false');
        await priority.focus();
        await page.keyboard.press('Space');
        await waitText(priorityBody, 'Catat kas harian.');
        await waitText(priorityBody, 'Catatan lengkap 30 hari.');
        await waitText(priorityBody, 'Sediakan waktu setiap sore.');
        await screenshot('priority-open');
        const secondPriority = latest.locator('summary').filter({ hasText: 'Kumpulkan catatan penjualan' });
        await secondPriority.click();
        assert.equal(await priorityBody.isVisible(), true);
        await secondPriority.click();
        await priority.focus();
        await page.keyboard.press('Enter');
        assert.equal(await priorityBody.isVisible(), false);
        const phases = latest.getByRole('tablist', { name: 'Tahapan rencana aksi' });
        const plan = latest.getByRole('region', { name: 'Rencana Aksi 90 Hari' });
        const phasePanel = plan.getByRole('tabpanel');
        await waitText(plan, 'Tahap 1 dari 3');
        assert.equal(await phasePanel.getByRole('listitem').count(), 2);
        await waitText(phasePanel, 'Target: 30 hari tercatat.');
        const [firstAction, secondAction] = await phasePanel.getByRole('listitem').all();
        const firstActionBox = await firstAction.boundingBox();
        const secondActionBox = await secondAction.boundingBox();
        if (viewport.width === 390) {
            assert.ok(secondActionBox.y >= firstActionBox.y + firstActionBox.height);
        } else {
            assert.equal(firstActionBox.y, secondActionBox.y);
            assert.ok(secondActionBox.x >= firstActionBox.x + firstActionBox.width);
        }
        const phaseCalls = reportCalls().length;
        const firstPhase = phases.getByRole('tab', { name: 'Hari 1–30' });
        for (const control of [refresh, priority, firstPhase, latest.getByRole('link', { name: 'Ringkasan' }),
            panel.getByRole('link', { name: 'Metodologi parsial' })]) {
            assert.ok((await control.boundingBox()).height >= 44);
        }
        if (evidence) await plan.screenshot({ path: join(evidence, `${viewport.width}-timeline-first.png`) });
        await firstPhase.focus();
        await page.keyboard.press('ArrowRight');
        await waitText(latest.getByRole('tabpanel'), 'Bandingkan pengeluaran bulanan.');
        await waitText(plan, 'Tahap 2 dari 3');
        assert.equal(await phasePanel.getByText('Target:', { exact: true }).count(), 0);
        await screenshot('timeline');
        await page.keyboard.press('End');
        await waitText(latest.getByRole('tabpanel'), 'Belum ada kegiatan untuk tahap ini.');
        await waitText(plan, 'Tahap 3 dari 3');
        if (viewport.width === 1440) await screenshot('timeline-last');
        await page.keyboard.press('ArrowRight');
        await waitText(latest.getByRole('tabpanel'), 'Mulai buku kas.');
        await page.keyboard.press('Home');
        assert.equal(await phases.getByRole('tab', { name: 'Hari 1–30' }).getAttribute('aria-selected'), 'true');
        await phases.getByRole('tab', { name: 'Hari 31–60' }).click();
        await waitText(latest.getByRole('tabpanel'), 'Bandingkan pengeluaran bulanan.');
        assert.equal(reportCalls().length, phaseCalls);
        await latest.getByRole('link', { name: 'Analisis skor' }).click();
        assert.equal(await page.locator(':focus').innerText(), 'Analisis Skor');
        await screenshot('scores');
        assert.equal(await panel.evaluate((node) => getComputedStyle(node).fontFamily.includes('Poppins')), true);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await reportMeta.evaluate((node) => node.closest('header').scrollIntoView({ block: 'start' }));
        await screenshot('available');
        await panel.getByRole('link', { name: 'Metodologi parsial' }).click();
        assert.equal(await page.locator(':focus').innerText(), 'Keterbatasan');
        assert.equal(await page.locator('details:has(> summary:focus)').getAttribute('open'), '');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth && !location.hash), true);
        assert.equal(await page.evaluate(() => document.activeElement.getBoundingClientRect().top >= document.querySelector('.topbar').getBoundingClientRect().bottom - 1), true);
        await history.evaluate((node) => {
            node.scrollIntoView({ block: 'start' });
            window.scrollBy(0, -80);
        });
        await screenshot('history');
        await history.getByRole('button', { name: 'Berikutnya' }).click();
        await waitText(history, 'Halaman 2 dari 2');
        assert.equal(await history.getByRole('button', { name: /Lihat laporan/ }).count(), 3);
        await history.getByRole('button', { name: 'Sebelumnya' }).click();
        await waitText(history, 'Halaman 1 dari 2');
        await history.getByRole('button', { name: 'Lihat laporan evaluasi 13', exact: true }).click();
        await waitText(reportMeta, 'Evaluasi #13');
        assert.equal(await latest.isVisible(), false);
        assert.equal(await selection.getByRole('meter', { name: 'INS' }).getAttribute('value'), '0');
        assert.match(await selection.locator('dd').first().textContent(), /^23\s*\/\s*30$/);
        await panel.getByRole('link', { name: 'Metodologi parsial' }).click();
        assert.equal(await page.locator(':focus').getAttribute('id'), 'intelligence-selected-limitations');
        assert.equal(await latest.locator('#intelligence-latest-limitations').isVisible(), false);
        await selection.getByRole('button', { name: 'Kembali ke laporan terbaru' }).click();
        await waitText(reportMeta, 'Evaluasi #14');
        assert.equal(await page.locator(':focus').innerText(), 'Intelligence Report');

        if (viewport.width === 1440) {
            behavior.history = ({ respond }) => respond({ message: 'private history error' }, 500);
            await history.getByRole('button', { name: 'Berikutnya' }).click();
            await history.getByRole('alert').waitFor();
            assert.equal(await latest.isVisible(), true);
            delete behavior.history;
            await history.getByRole('button', { name: 'Coba lagi' }).click();
            await waitText(history, 'Halaman 2 dari 2');

            behavior.detail = ({ respond }) => respond({ message: 'private detail error' }, 404);
            await history.getByRole('button', { name: /Lihat laporan/ }).first().click();
            await waitText(selection, 'Data tidak ditemukan.');
            assert.equal(await selection.getByRole('button', { name: 'Coba lagi' }).count(), 0);
            assert.equal(await latest.isVisible(), true);
            await selection.getByRole('button', { name: 'Kembali ke laporan terbaru' }).click();

            behavior.detail = ({ respond }) => respond({ message: 'private detail error' }, 503);
            await history.getByRole('button', { name: 'Lihat laporan evaluasi 4' }).click();
            await waitText(selection, 'Gagal memuat laporan terpilih. Silakan coba lagi.');
            assert.equal(await latest.isVisible(), true);
            delete behavior.detail;
            await selection.getByRole('button', { name: 'Coba lagi' }).click();
            await waitText(reportMeta, 'Evaluasi #4');
            await selection.getByRole('button', { name: 'Kembali ke laporan terbaru' }).click();

            const pending = delayed();
            behavior.detail = async ({ respond, reportId, id }) => {
                await pending.promise;
                return respond({ data: reportFixture(reportId, id) }).catch(() => {});
            };
            const failed = page.waitForEvent('requestfailed', (req) => req.url().endsWith('/intelligence-reports/4'));
            await history.getByRole('button', { name: 'Lihat laporan evaluasi 4' }).click();
            await selection.locator('[aria-busy="true"]').waitFor();
            await profile.click();
            await failed;
            pending.resolve();
            await intelligence.click();
            assert.equal(await selection.isVisible(), false);
            await waitText(reportMeta, 'Evaluasi #14');
        }

        if (viewport.width === 390) {
            behavior.latest = ({ respond, id }) => {
                const payload = availableReport(14, id);
                payload.data.report = { three_dimension_analysis: { available: false } };
                return respond(payload);
            };
            await refresh.click();
            await waitText(latest, 'Ringkasan belum tersedia.');
            assert.equal(await latest.getByRole('meter').count(), 0);
            assert.equal(await latest.getByText('Skor rinci tidak tersedia.', { exact: true }).count(), 2);
            assert.equal(await latest.locator('.intelligence-metrics').count(), 0);
            await panel.getByRole('link', { name: 'Metodologi parsial' }).click();
            await waitText(latest, 'Rincian keterbatasan belum tersedia.');
            const historyBefore = reportCalls().filter((url) => url.includes('?page=')).length;
            behavior.latest = ({ respond }) => respond(awaitReport);
            await refresh.click();
            await waitText(latest, 'Laporan Intelligence belum tersedia');
            assert.doesNotMatch(await latest.innerText(), /sedang diproses/i);
            assert.equal(reportCalls().filter((url) => url.includes('?page=')).length, historyBefore);
            await screenshot('awaiting');
            behavior.latest = ({ respond }) => respond({ message: 'private report error' }, 503);
            await refresh.click();
            const alert = latest.getByRole('alert');
            await alert.waitFor();
            assert.doesNotMatch(await alert.innerText(), /private/);
            assert.equal(await panel.locator('[data-intelligence-status]').textContent(), '');
            await screenshot('error');
            delete behavior.latest;
            behavior.history = ({ respond, id }) => respond(historyPage(1, id, 0));
            await alert.getByRole('button', { name: 'Coba lagi' }).click();
            await waitText(history, 'Belum ada riwayat laporan');

            const routeStarted = delayed();
            const oldResponse = delayed();
            behavior.latest = async ({ respond, id }) => {
                if (id !== 5) return respond(availableReport(14, id));
                routeStarted.resolve();
                await oldResponse.promise;
                return respond(availableReport(14, id)).catch(() => {});
            };
            await refresh.click();
            await routeStarted.promise;
            const abandoned = page.waitForEvent('requestfailed', (req) => req.url().includes('/umkms/5/intelligence-reports/latest'));
            await navigateToOtherUmkm(page);
            await page.waitForURL('**/umkms/6');
            await abandoned;
            oldResponse.resolve();
            await page.getByRole('tab', { name: 'Intelligence' }).click();
            await waitText(latest, 'Ringkasan Eksekutif');
            assert.match(await latest.innerText(), /untuk usaha 6/);
            assert.doesNotMatch(await latest.innerText(), /untuk usaha 5/);

            behavior.latest = ({ respond }) => respond({ message: 'private report error' }, 404);
            await refresh.click();
            await waitText(latest, 'Data tidak ditemukan.');
            assert.doesNotMatch(await latest.innerText(), /private/);
            await page.evaluate(() => {
                const timeout = AbortSignal.timeout;
                AbortSignal.timeout = (delay) => timeout(delay === 20000 ? 120 : delay);
            });
            const timedOut = delayed();
            behavior.latest = async ({ respond }) => {
                await timedOut.promise;
                return respond(awaitReport).catch(() => {});
            };
            await refresh.click();
            await waitText(latest, 'Gagal memuat laporan terbaru. Silakan coba lagi.');
            timedOut.resolve();
            assert.equal(await page.locator('#app-shell').isVisible(), true);
            behavior.latest = ({ respond }) => respond({ message: 'private session error' }, 401);
            await latest.getByRole('button', { name: 'Coba lagi' }).click();
            await page.waitForURL('**/login');
            assert.equal(await page.evaluate(() => localStorage.getItem('joyumkm.token')), null);
        }

        assert.deepEqual(errors, []);
        await context.close();
        console.log(`PASS Chrome ${browser.version()} ${viewport.width}×${viewport.height}`);
    }
    await verifyMotion(sizes[0]);
    await verifyMotion(sizes[2]);
} finally {
    await browser.close();
}
