import { pageApi } from '../api';
import { formatNumber, formatScore, showError } from '../ui';
import { withListContext } from '../umkm-navigation';

export async function init(signal) {
    const api = pageApi(signal);
    const root = document.getElementById('umkm-detail');
    const state = document.getElementById('umkm-detail-state');
    state.setAttribute('aria-busy', 'true');
    const path = '/umkms/' + root.dataset.umkm;
    let data;
    try {
        ({ data } = await api(path));
    } catch (error) {
        if (!signal.aborted && error.name !== 'AbortError' && error.status !== 401) {
            showError(state, {
                status: error.status,
                message: error.status === 404 ? 'Data tidak ditemukan.' : 'Detail UMKM belum dapat dimuat.',
            }, error.status === 404 ? undefined : () => init(signal));
        }
        return;
    }

    document.getElementById('detail-title').textContent = data.name;
    const parent = root.querySelector('[data-context-umkm]');
    if (parent) parent.textContent = data.name;
    state.hidden = true;
    state.setAttribute('aria-busy', 'false');
    const detail = document.getElementById('umkm-detail-content');
    if (detail) {
        detail.querySelectorAll('[data-carry-list]').forEach((link) => { link.href = withListContext(link.href); });
        document.getElementById('detail-sector').textContent = data.sector.name;
        document.getElementById('detail-province').textContent = data.province;
        document.getElementById('detail-omzet').textContent = 'Rp ' + formatNumber(data.omzet);
        document.getElementById('detail-assessment-status').textContent = {
            draft: 'Draft tersimpan', final: 'Final', none: 'Belum ada kuesioner tersimpan',
        }[data.evaluation_status];
        document.getElementById('detail-assessment-score').textContent = data.latest_score === null
            ? 'Skor belum tersedia' : `${formatScore(data.latest_score)}/100 · ${data.category}`;
        detail.hidden = false;
    } else {
        const { initEvaluation } = await import('./six-factor-evaluation');
        if (!signal.aborted) initEvaluation(path + '/six-factor-evaluations', signal);
    }
}
