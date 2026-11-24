import { element, icon } from '../ui';

const card = 'card card-pad panel intelligence-stack';
const scoreFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const paragraph = (text, className) => text ? element('p', { class: className, text }) : null;

function reportItems(items = []) {
    return items.length ? element('ul', { class: 'intelligence-list' }, items.map((item) =>
        element('li', {}, [
            paragraph(item.label, 'intelligence-finding-label'),
            paragraph(item.summary, 'pre-line'),
            paragraph(item.note, 'muted pre-line'),
        ]))) : null;
}

function disclosure(label, content, reducedMotion, id) {
    const summary = element('summary', { id, class: 'intelligence-disclosure-head', 'aria-expanded': 'false' }, [label, icon('arrow')]);
    const body = element('div', { class: 'intelligence-disclosure-body intelligence-stack', inert: true }, content);
    const details = element('details', { class: 'intelligence-disclosure' }, [summary, body]);
    let expanded = false;
    let animation;
    let observer;
    let target;

    function cancel() {
        animation?.cancel();
        animation = undefined;
        observer?.disconnect();
        observer = undefined;
    }

    function settle() {
        cancel();
        details.open = expanded;
        details.style.removeProperty('overflow');
    }

    function animate(from, to) {
        if (Math.abs(from - to) < 1) {
            settle();
            return;
        }
        animation?.cancel();
        target = to;
        details.style.overflow = 'clip';
        const running = details.animate({ height: [`${from}px`, `${to}px`] }, {
            duration: 240, easing: 'ease-in-out', fill: 'both',
        });
        animation = running;
        running.onfinish = () => { if (animation === running) settle(); };
    }

    function setExpanded(open) {
        if (expanded === open) return;
        expanded = open;
        const from = details.getBoundingClientRect().height;
        cancel();
        if (!open && body.contains(document.activeElement)) summary.focus({ preventScroll: true });
        body.inert = !open;
        summary.setAttribute('aria-expanded', String(open));
        if (reducedMotion.matches) {
            settle();
            return;
        }

        if (open) details.open = true;
        const style = getComputedStyle(details);
        const borders = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
        const height = () => borders + summary.getBoundingClientRect().height + (expanded ? body.getBoundingClientRect().height : 0);
        animate(from, height());
        if (animation) {
            observer = new ResizeObserver(() => {
                const to = height();
                if (animation && Math.abs(to - target) >= 1) animate(details.getBoundingClientRect().height, to);
            });
            observer.observe(summary);
            if (open) observer.observe(body);
        }
    }

    summary.addEventListener('click', (event) => {
        event.preventDefault();
        setExpanded(!expanded);
    });
    return { details, summary, expand: () => setExpanded(true), settle };
}

function jumpLink(label, target, className, onJump) {
    return element('a', {
        href: `#${target.id}`, class: className,
        onclick(event) {
            event.preventDefault();
            onJump?.();
            target.focus({ preventScroll: true });
            target.scrollIntoView({ block: 'start' });
        },
    }, label);
}

function metrics({ six, three }, priorityCount) {
    const values = [
        ['Total Enam Faktor', six.aggregate, '/ 30'],
        ['Rata-rata Tiga Dimensi', three.aggregate, '/ 100'],
        ['Prioritas utama', priorityCount, ''],
    ].filter(([, value]) => value !== null);
    return values.length ? element('dl', { class: 'intelligence-metrics' }, values.map(([label, value, scale]) =>
        element('div', {}, [
            element('dt', { text: label }),
            element('dd', { class: 'num' }, [scoreFormat.format(value), scale ? element('small', { text: ` ${scale}` }) : null]),
        ]))) : null;
}

function scoreChart(title, { rows, min, max, count }, partialLabel, variant) {
    const scale = `${min}–${max}`;
    return element('div', { class: `intelligence-chart intelligence-stack ${variant}` }, [
        element('h4', { class: 'section-title', text: title }),
        paragraph(`Skala ${scale}`, 'section-desc'),
        rows.length ? element('ul', { class: 'intelligence-bars', role: 'list' }, rows.map(({ label, value }) => {
            const formatted = scoreFormat.format(value);
            return element('li', {}, [
                element('div', { class: 'intelligence-bar-label', 'aria-hidden': 'true' }, [
                    element('span', { text: label }), element('strong', { class: 'num', text: `${formatted} / ${max}` }),
                ]),
                element('meter', { min, max, value, 'aria-label': label, 'aria-valuetext': `${formatted} dari skala ${scale}` }),
            ]);
        })) : paragraph('Skor rinci tidak tersedia.', 'muted'),
        rows.length && rows.length < count ? paragraph(`${rows.length} dari ${count} skor ${partialLabel} tersedia untuk visualisasi.`, 'section-desc') : null,
    ]);
}

function actionPlan(groups, prefix, heading) {
    const stage = element('span', { class: 'pill intelligence-plan-stage' });
    const highlight = (selected) => {
        stage.textContent = `Tahap ${selected + 1} dari ${groups.length}`;
        tabs.forEach((tab, index) => {
            tab.setAttribute('aria-selected', String(index === selected));
            tab.tabIndex = index === selected ? 0 : -1;
            panels[index].hidden = index !== selected;
        });
    };
    const tabs = groups.map(({ title }, index) => element('button', {
        type: 'button', class: 'detail-tab', role: 'tab', id: `${prefix}-phase-tab-${index}`,
        'aria-controls': `${prefix}-phase-${index}`, text: title,
        onclick: () => highlight(index),
        onkeydown(event) {
            const next = { ArrowLeft: index - 1, ArrowRight: index + 1, Home: 0, End: groups.length - 1 }[event.key];
            if (next === undefined) return;
            event.preventDefault();
            const selected = (next + groups.length) % groups.length;
            highlight(selected);
            tabs[selected].focus();
        },
    }));
    const panels = groups.map(({ items }, index) => element('div', {
        id: `${prefix}-phase-${index}`, role: 'tabpanel', tabindex: 0, 'aria-labelledby': tabs[index].id,
    }, items.length ? element('ol', { class: 'intelligence-phase-cards', role: 'list' }, items.map(({ label, summary, metric }, itemIndex) =>
        element('li', { class: 'intelligence-phase-card intelligence-stack' }, [
            element('h4', { class: 'intelligence-phase-title' }, [
                element('span', { 'aria-hidden': 'true', text: `${String(itemIndex + 1).padStart(2, '0')} · ` }),
                label || 'Kegiatan',
            ]),
            paragraph(summary, 'pre-line'),
            metric ? element('p', { class: 'intelligence-phase-metric pre-line' }, [
                element('strong', { text: 'Target:' }), ` ${metric}`,
            ]) : null,
        ]))) : paragraph('Belum ada kegiatan untuk tahap ini.', 'muted')));
    highlight(0);
    return [
        element('header', { class: 'detail-panel-head intelligence-plan-head' }, [
            element('div', {}, [heading, paragraph('Pilih periode untuk melihat kegiatan dan targetnya.', 'section-desc')]),
            stage,
        ]),
        element('div', { class: 'intelligence-phase-tabs', role: 'tablist', 'aria-label': 'Tahapan rencana aksi' }, tabs),
        ...panels,
        paragraph('Rencana ini berisi rekomendasi dari laporan dan bersifat baca saja.', 'section-desc'),
    ];
}

export function renderIntelligenceReport(report, prefix, historyTitle, reducedMotion) {
    const byId = Object.fromEntries(report.sections.map((section) => [section.id, section]));
    const disclosures = [];
    const entries = [];
    const heading = (id, text) => element('h3', { id: `${prefix}-${id}`, class: 'section-title', tabindex: -1, text });
    const section = (id, title, link, content) => {
        const label = heading(id, title);
        entries.push([link, label]);
        return element('section', { class: card, 'aria-labelledby': label.id },
            typeof content === 'function' ? content(label) : [label, ...content]);
    };
    const addDisclosure = (label, content, id) => {
        const item = disclosure(label, content, reducedMotion, id);
        disclosures.push(item);
        return item;
    };

    const summary = section('summary', 'Ringkasan Eksekutif', 'Ringkasan', [
        paragraph(byId.summary?.summary ?? 'Ringkasan belum tersedia.', 'pre-line'),
        report.keyFinding ? element('aside', { class: 'intelligence-key-finding', 'aria-label': 'Temuan utama' }, [
            element('strong', { text: 'Temuan utama' }),
            paragraph(report.keyFinding.summary, 'pre-line intelligence-prose'),
            paragraph(report.keyFinding.note, 'muted pre-line intelligence-prose'),
        ]) : null,
    ]);
    const priorities = byId.priorities?.items ?? [];
    const kpis = metrics(report.scores, report.priorityCount);
    const scores = section('scores', 'Analisis Skor', 'Analisis skor', [
        element('div', { class: 'intelligence-score-grid' }, [
            scoreChart('Enam Faktor', report.scores.six, 'faktor', 'intelligence-chart-six'),
            scoreChart('Tiga Dimensi', report.scores.three, 'dimensi', 'intelligence-chart-three'),
        ]),
    ]);
    const priorityCards = priorities.length ? section('priorities', 'Prioritas Utama', 'Prioritas', [
        element('ol', { class: 'intelligence-priorities intelligence-stack', role: 'list' }, priorities.map((item, index) => {
            const label = element('span', { class: 'intelligence-priority-head' }, [
                element('span', { class: 'num intelligence-priority-rank', 'aria-hidden': 'true', text: String(index + 1).padStart(2, '0') }),
                element('span', { class: 'intelligence-priority-copy' }, [
                    element('span', { class: 'intelligence-item-title', text: item.title }),
                    item.summary ? element('span', { class: 'muted pre-line', text: item.summary }) : null,
                    item.priority ? element('span', { class: 'pill intelligence-priority-level', text: item.priority }) : null,
                ]),
            ]);
            const lists = item.lists.filter(([, values]) => values.length).map(([name, values]) => element('div', {}, [
                element('strong', { text: name }), reportItems(values.map((value) => ({ summary: value }))),
            ]));
            const content = [paragraph(item.label, 'intelligence-finding-label'), ...lists].filter(Boolean);
            return element('li', {}, addDisclosure(label, content.length ? content : [paragraph('Rincian prioritas belum tersedia.', 'muted')]).details);
        })),
    ]) : null;
    const plan = byId.plan ? section('plan', byId.plan.title, 'Rencana aksi', (label) => actionPlan(byId.plan.groups, prefix, label)) : null;
    const analysis = ['profile', 'six-factor', 'three-dimension', 'perspectives', 'insights'].flatMap((id) => {
        const part = byId[id];
        if (!part) return [];
        const parts = (part.groups ?? [part]).map(({ title, summary, items }) =>
            addDisclosure(element('span', { class: 'intelligence-item-title', text: title }), [
                paragraph(summary, 'pre-line'), reportItems(items),
            ]).details);
        return part.groups ? [element('h4', { class: 'section-title', text: part.title }), ...parts] : parts;
    });
    const deep = analysis.length ? section('analysis', 'Analisis Mendalam', 'Analisis mendalam', [
        element('div', { class: 'intelligence-analysis-list' }, analysis),
    ]) : null;
    const limitations = byId.limitations ? addDisclosure(
        element('span', { class: 'section-title', text: 'Keterbatasan' }),
        [paragraph(byId.limitations.summary, 'pre-line'), reportItems(byId.limitations.items)], `${prefix}-limitations`,
    ) : null;
    const badge = report.partial ? jumpLink(element('span', { class: 'pill pill-amber', text: 'Metodologi parsial' }),
        limitations.summary, 'intelligence-methodology', limitations.expand) : null;
    const navigation = element('nav', { class: 'intelligence-jumps', 'aria-label': 'Bagian laporan' }, [
        ...entries.map(([label, target]) => jumpLink(label, target, 'intelligence-jump')),
        jumpLink('Riwayat', historyTitle, 'intelligence-jump'),
    ]);
    return {
        badge,
        sections: [navigation, summary, kpis, scores, priorityCards, plan, deep,
            limitations ? element('section', { class: card, 'aria-labelledby': limitations.summary.id }, limitations.details) : null].filter(Boolean),
        settle: () => disclosures.forEach((item) => item.settle()),
    };
}
