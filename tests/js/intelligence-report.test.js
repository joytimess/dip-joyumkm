import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeHistory, normalizeReport } from '../../resources/js/pages/intelligence-report-data.js';
import { reportFixture, unsafeText } from './intelligence-fixture.mjs';

test('memetakan bagian V4, mempertahankan urutan prioritas, dan memakai judul prioritas pada rencana', () => {
    const report = normalizeReport(reportFixture(), 5);
    assert.equal(report.partial, true);
    assert.deepEqual(report.sections.map(({ id }) => id), [
        'summary', 'profile', 'six-factor', 'three-dimension', 'perspectives', 'insights', 'priorities', 'plan', 'limitations',
    ]);
    const sections = Object.fromEntries(report.sections.map((section) => [section.id, section]));
    assert.ok(sections.summary.summary.includes(unsafeText));
    assert.equal(sections['six-factor'].items[0].label, 'Fakta · Skor 0');
    assert.deepEqual(sections.priorities.items.map(({ title }) => title), [
        'Rapikan pencatatan harian', 'Kumpulkan catatan penjualan',
    ]);
    assert.equal(sections.plan.groups[0].items[0].label, 'Rapikan pencatatan harian');
    assert.equal(sections.plan.groups[0].items[0].metric, '30 hari tercatat.');
    assert.deepEqual(sections.plan.groups.map(({ title }) => title), ['Hari 1–30', 'Hari 31–60', 'Hari 61–90']);
    assert.deepEqual(sections.plan.groups[2].items, []);
    assert.equal(sections.perspectives.groups[1].summary, 'Data beban kerja belum tersedia.');
    assert.ok(!JSON.stringify(report).includes('internal.score'));
});

test('mengambil skor fakta V4 dengan referensi yang cocok tanpa mengubah laporan', () => {
    const data = reportFixture();
    const before = structuredClone(data);
    const { scores, keyFinding } = normalizeReport(data, 5);
    assert.equal(scores.six.aggregate, 25);
    assert.equal(scores.three.aggregate, 90.3);
    assert.equal(normalizeReport(data, 5).priorityCount, 2);
    assert.deepEqual(scores.six.rows.map(({ label, value }) => [label, value]), [
        ['Sumber Daya', 5], ['Lingkungan Kerja', 3], ['Kinerja', 5],
    ]);
    assert.deepEqual(scores.three.rows.map(({ label, value }) => [label, value]), [
        ['INS', 83.3333], ['ECT', 100], ['OPS', 87.5],
    ]);
    assert.equal(keyFinding.summary, data.report.cross_instrument_insights[0].observation);
    assert.equal(keyFinding.note, data.report.cross_instrument_insights[0].caveat);
    assert.deepEqual(data, before);
    data.report.cross_instrument_insights = [];
    assert.equal(normalizeReport(data, 5).keyFinding.summary, data.report.six_factor_analysis.findings[0].statement);
});

test('memakai angka terstruktur yang valid, menolak prosa ambigu dan mempertahankan nol Tiga Dimensi', () => {
    const data = reportFixture(13);
    const six = data.report.six_factor_analysis;
    six.total_score = 27;
    six.scores = { lingkungan_kerja: 4, nilai_organisasi: 0, kepemimpinan: '5', stabilitas: 8 };
    six.findings.push(
        { type: 'fact', statement: 'Sumber Daya: skor 2 dari skala 1-5.', evidence_refs: ['six_factor.scores.sumber_daya'] },
        { type: 'fact', statement: 'Stabilitas: skor 5 dari skala 1-5.', evidence_refs: ['six_factor.scores.nilai_organisasi'] },
        { type: 'fact', statement: 'Kepemimpinan: skor 5 dari skala 0-5.', evidence_refs: ['six_factor.scores.kepemimpinan'] },
        { type: 'fact', score: 3, statement: 'Skor kepemimpinan tercatat.', evidence_refs: ['six_factor.scores.kepemimpinan'] },
        { type: 'fact', score: 5, statement: 'Dua referensi tanpa pasangan angka.', evidence_refs: ['six_factor.scores.stabilitas', 'six_factor.scores.nilai_organisasi'] },
        { type: 'interpretation', statement: 'Nilai Organisasi: skor 5 dari skala 1-5.', evidence_refs: ['six_factor.scores.nilai_organisasi'] },
    );
    const { scores } = normalizeReport(data, 5);
    assert.equal(scores.six.aggregate, 27);
    assert.deepEqual(scores.six.rows.map(({ label, value }) => [label, value]), [
        ['Kepemimpinan', 3], ['Lingkungan Kerja', 4], ['Kinerja', 5],
    ]);
    assert.equal(scores.three.rows[0].value, 0);
    data.report.three_dimension_analysis.available = false;
    assert.deepEqual(normalizeReport(data, 5).scores.three.rows, []);
    assert.equal(normalizeReport(data, 5).scores.three.aggregate, null);
});

test('menangani field kosong, metodologi parsial tanpa daftar, dan skor yang tidak tersedia', () => {
    const data = reportFixture();
    data.generated_at = 'bukan tanggal';
    data.report = {
        executive_summary: '   ', business_profile: null, action_plan: [],
        six_factor_analysis: { findings: [null, {}, { statement: 'Tanpa skor.', score: null }] },
        three_dimension_analysis: { available: false, findings: [] },
        limitations: [],
    };
    const report = normalizeReport(data, 5);
    assert.equal(report.date, '');
    assert.equal(report.priorityCount, null);
    assert.deepEqual(report.sections.map(({ id }) => id), ['six-factor', 'three-dimension', 'limitations']);
    assert.equal(report.sections[0].items[0].label, '');
    assert.match(report.sections[1].summary, /belum tersedia/i);
    assert.equal(report.sections[2].summary, 'Rincian keterbatasan belum tersedia.');
    data.limitations = ['Batas dari metadata.'];
    data.report.top_priorities = [];
    assert.equal(normalizeReport(data, 5).priorityCount, 0);
    assert.equal(normalizeReport(data, 5).sections.at(-1).items[0].summary, 'Batas dari metadata.');
    assert.throws(() => normalizeReport(data, 8));
    assert.throws(() => normalizeReport(data, 5, 99));
    assert.throws(() => normalizeReport({ ...data, report: [] }, 5));
});

test('memvalidasi halaman histori dan hanya membawa metadata yang digunakan', () => {
    const meta = { current_page: 2, last_page: 3, per_page: 10 };
    const history = normalizeHistory({ data: [reportFixture()], meta }, 5, 2);
    assert.equal(history.page, 2);
    assert.equal(history.lastPage, 3);
    assert.equal(history.items[0].evaluationId, 14);
    assert.ok(!Object.hasOwn(history.items[0], 'report'));
    assert.deepEqual(normalizeHistory({ data: [], meta: { ...meta, current_page: 1, last_page: 1 } }, 5, 1).items, []);
    assert.throws(() => normalizeHistory({ data: [], meta }, 5, 1));
    assert.throws(() => normalizeHistory({ data: [reportFixture(14, 8)], meta }, 5, 2));
});
