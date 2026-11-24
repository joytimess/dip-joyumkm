import { formatNumber } from '../ui.js';

const dateFormatter = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeStyle: 'short' });
const findingLabels = {
    fact: 'Fakta',
    interpretation: 'Interpretasi',
    data_gap: 'Kesenjangan data',
    assumption: 'Asumsi',
    strength: 'Kekuatan',
    risk: 'Risiko',
};
const perspectiveLabels = {
    business_development: 'Pengembangan Usaha',
    marketing_sales: 'Pemasaran dan Penjualan',
    finance: 'Keuangan',
    operations_people: 'Operasional dan SDM',
};
const levelLabels = { high: 'Tinggi', medium: 'Sedang', low: 'Rendah' };
const phases = { days_1_30: 'Hari 1–30', days_31_60: 'Hari 31–60', days_61_90: 'Hari 61–90' };
const sixFactorLabels = {
    sumber_daya: 'Sumber Daya',
    kepemimpinan: 'Kepemimpinan',
    lingkungan_kerja: 'Lingkungan Kerja',
    nilai_organisasi: 'Nilai Organisasi',
    stabilitas: 'Stabilitas',
    kinerja: 'Kinerja',
};
const dimensionLabels = { ins: 'INS', ect: 'ECT', ops: 'OPS' };

const text = (value) => typeof value === 'string' ? value.trim() : '';
const list = (value) => Array.isArray(value) ? value : [];
const texts = (value) => list(value).map(text).filter(Boolean);
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const isId = (value) => Number.isSafeInteger(value) && value > 0;
const labelFor = (labels, key) => Object.hasOwn(labels, text(key)) ? labels[key.trim()] : '';
const joinLabels = (...labels) => labels.filter(Boolean).join(' · ');
const textItems = (value) => texts(value).map((summary) => ({ summary }));

function metadata(data, umkmId) {
    if (!isObject(data) || data.umkm_id !== umkmId || !isId(data.id)) throw new Error('Invalid report metadata');
    const date = Date.parse(text(data.generated_at));
    return {
        id: data.id,
        evaluationId: isId(data.evaluation_id) ? data.evaluation_id : null,
        date: Number.isFinite(date) ? dateFormatter.format(date) : '',
        dateTime: Number.isFinite(date) ? data.generated_at : '',
        partial: data.methodology_status === 'partial',
    };
}

function findings(value) {
    return list(value).flatMap((item) => {
        const summary = text(item?.statement) || text(item);
        const score = Number.isFinite(item?.score) ? `Skor ${formatNumber(item.score)}` : '';
        const label = joinLabels(labelFor(findingLabels, item?.type), score);
        return summary || score ? [{ label, summary }] : [];
    });
}

function analysis(id, title, value, fallback = '') {
    return { id, title, summary: text(value?.summary) || text(value) || fallback, items: findings(value?.findings) };
}

function hasContent({ summary, items, groups }) {
    return Boolean(summary || items?.length || groups?.length);
}

function scoreValue(structured, facts, reference, prefix, min, max) {
    const valid = (value) => Number.isFinite(value) && value >= min && value <= max
        && (max === 100 || Number.isInteger(value));
    if (valid(structured)) return structured;

    // shortcut: only verified V4 fact sentences with matching evidence refs, prefer structured scores when supplied.
    const scale = ` dari skala ${min}-${max}`;
    const values = new Set(facts.flatMap(({ statement, score, evidence_refs }) => {
        const references = list(evidence_refs);
        if (!references.includes(reference)) return [];
        if (references.length === 1 && valid(score)) return [score];
        return text(statement).split(/\.(?:\s+|$)/).flatMap((sentence) => {
            if (!sentence.startsWith(prefix) || !sentence.endsWith(scale)) return [];
            const numeric = sentence.slice(prefix.length, -scale.length);
            if (!/^\d{1,3}(?:[,.]\d{1,4})?$/.test(numeric)) return [];
            const value = Number(numeric.replace(',', '.'));
            return valid(value) ? [value] : [];
        });
    }));
    return values.size === 1 ? [...values][0] : null;
}

function instrumentScores(source, instrument, labels, min, max, aggregateField, aggregateLabel, aggregateMin, aggregateMax) {
    const facts = list(source?.findings).filter((item) => item?.type === 'fact');
    const rows = Object.entries(labels).flatMap(([key, label]) => {
        const value = scoreValue(source?.scores?.[key], facts, `${instrument}.scores.${key}`, `${label}: skor `, min, max);
        return value === null ? [] : [{ label, value }];
    });
    const aggregate = scoreValue(source?.[aggregateField], facts, `${instrument}.${aggregateField}`,
        `${aggregateLabel}: `, aggregateMin, aggregateMax);
    return { rows, aggregate, min, max, count: Object.keys(labels).length };
}

export function normalizeReport(data, umkmId, reportId) {
    const meta = metadata(data, umkmId);
    if (!isObject(data.report) || (reportId !== undefined && meta.id !== reportId)) throw new Error('Invalid report');
    const report = data.report;
    const profile = report.business_profile;
    const three = report.three_dimension_analysis;
    const priorities = list(report.top_priorities).filter((item) => text(item?.title));
    const priorityTitles = new Map(priorities.filter((item) => text(item.id)).map((item) => [text(item.id), text(item.title)]));
    const limitations = textItems(report.limitations);
    if (!limitations.length) limitations.push(...textItems(data.limitations));
    const insights = list(report.cross_instrument_insights).map((item) => ({
        summary: text(item?.observation) || text(item),
        note: text(item?.caveat),
    })).filter(({ summary, note }) => summary || note);
    const firstFact = [...list(report.six_factor_analysis?.findings), ...list(three?.findings)]
        .find((item) => item?.type === 'fact' && text(item.statement));
    const plan = Object.entries(phases).map(([key, title]) => ({
        title,
        items: list(report.action_plan?.[key]).map((item) => ({
            summary: text(item?.action) || text(item),
            label: priorityTitles.get(text(item?.priority_id)) || '',
            metric: text(item?.metric),
        })).filter(({ summary, metric }) => summary || metric),
    }));

    const sections = [
        { id: 'summary', title: 'Ringkasan Eksekutif', summary: text(report.executive_summary) },
        {
            id: 'profile', title: 'Profil Usaha',
            summary: joinLabels(text(profile?.name), text(profile?.sector)),
            items: textItems(profile?.data_gaps),
        },
        analysis('six-factor', 'Analisis Enam Faktor', report.six_factor_analysis),
        analysis('three-dimension', 'Analisis Tiga Dimensi', three,
            three?.available === false ? 'Data penilaian tiga dimensi belum tersedia.' : ''),
        {
            id: 'perspectives', title: 'Perspektif Usaha',
            groups: Object.entries(perspectiveLabels).map(([key, title]) => {
                const perspective = report.perspectives?.[key];
                return { title, summary: text(perspective?.abstain_reason), items: findings(perspective?.findings) };
            }).filter(hasContent),
        },
        {
            id: 'insights', title: 'Temuan Lintas Penilaian',
            items: insights,
        },
        {
            id: 'priorities', title: 'Prioritas Utama',
            items: priorities.map((item) => {
                const priority = labelFor(levelLabels, item.priority);
                const confidence = labelFor(levelLabels, item.confidence);
                return {
                    title: text(item.title),
                    priority: priority && `Prioritas ${priority.toLowerCase()}`,
                    label: joinLabels(labelFor(perspectiveLabels, item.perspective),
                        confidence && `Keyakinan analisis ${confidence.toLowerCase()}`),
                    summary: text(item.rationale),
                    lists: [
                        ['Langkah yang disarankan', texts(item.actions)],
                        ['Indikator keberhasilan', texts(item.success_metrics)],
                        ['Asumsi', texts(item.assumptions)],
                    ],
                };
            }),
        },
        {
            id: 'plan', title: 'Rencana Aksi 90 Hari',
            groups: plan.some(hasContent) ? plan : [],
        },
        {
            id: 'limitations', title: 'Keterbatasan',
            summary: meta.partial && !limitations.length ? 'Rincian keterbatasan belum tersedia.' : '',
            items: limitations,
        },
    ].filter(hasContent);

    return {
        ...meta,
        sections,
        priorityCount: priorities.length || (Array.isArray(report.top_priorities) && !report.top_priorities.length ? 0 : null),
        keyFinding: insights.find(({ summary }) => summary) ?? (firstFact && { summary: text(firstFact.statement) }),
        scores: {
            six: instrumentScores(report.six_factor_analysis, 'six_factor', sixFactorLabels, 1, 5,
                'total_score', 'Total skor Enam Faktor', 6, 30),
            three: instrumentScores(three?.available === false ? null : three, 'three_dimension', dimensionLabels, 0, 100,
                'average_score', 'Rata-rata Tiga Dimensi', 0, 100),
        },
    };
}

export function normalizeHistory(payload, umkmId, requestedPage) {
    const { data, meta } = payload ?? {};
    if (!Array.isArray(data) || !isId(meta?.current_page) || meta.current_page !== requestedPage || !isId(meta.last_page)) {
        throw new Error('Invalid report history');
    }
    return { items: data.map((item) => metadata(item, umkmId)), page: meta.current_page, lastPage: meta.last_page };
}
