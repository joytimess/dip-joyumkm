import { formatScore } from './ui.js';

export const summaryPageSize = 4;

export function buildSummaryQuery({ page, perPage = summaryPageSize, search = '', status = 'all', sort = 'name_asc' }) {
    const params = new URLSearchParams({ page: String(page), per_page: String(perPage), status, sort });
    const term = search.trim();
    if (term) params.set('search', term);
    return params.toString();
}

export function pageRange(page, perPage, total) {
    if (!total) return { from: 0, to: 0 };
    return { from: (page - 1) * perPage + 1, to: Math.min(page * perPage, total) };
}

export const categoryTone = (category) => (category === 'Sehat' || category === 'Sangat Sehat' ? 'pill-green' : 'pill-amber');

export function formatAverage(value) {
    return value === null || value === undefined ? '—' : `${formatScore(value)} / 30`;
}
