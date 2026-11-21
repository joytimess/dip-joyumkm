@extends('layouts.app', ['page' => 'form-data', 'title' => 'Kuesioner Tiga Dimensi'])
@section('content')
<div class="page">
    @include('components.umkm-breadcrumb', ['current' => 'Kuesioner Tiga Dimensi', 'dynamic' => true])
    <div class="page-heading">
        <div>
            <h1>Kuesioner Tiga Dimensi</h1>
            <p class="muted">Kenali kondisi usaha melalui tiga faktor evaluasi dan pantau kalkulasi skor secara langsung.</p>
        </div>
    </div>

    <section class="card filter-panel panel picker-panel" aria-labelledby="picker-title" style="margin-bottom:20px;">
        <div class="section-top section-heading">
            <div>
                <h2 id="picker-title" class="section-title">Pilih UMKM</h2>
                <p class="section-desc muted">Pilih UMKM untuk mengisi kuesioner atau melanjutkan draft evaluasi.</p>
            </div>
        </div>
        <fieldset id="umkm-picker" style="border:0;padding:0;margin:0;"><div class="skeleton h-10"></div></fieldset>
    </section>

    <div id="questionnaire-state" aria-live="polite"><div class="skeleton h-32"></div></div>

    <div id="evaluation-layout" class="form-layout evaluation-layout" hidden>
        <form id="evaluation-form" method="post" novalidate>
            <div id="evaluation-message" class="form-message" role="alert" tabindex="-1" hidden></div>
            <div id="evaluation-empty" class="card card-pad panel empty-state">
                <h2 class="section-title">Pilih UMKM terlebih dahulu</h2>
                <p class="muted section-desc">Kuesioner dan evaluasi terbaru akan dimuat setelah UMKM dipilih.</p>
            </div>
            <div id="factor-tabs" class="factor-tabs mb-4" role="group" aria-label="Dimensi evaluasi"></div>
            <div id="questionnaire-factors"></div>
            <div id="factor-navigation" class="factor-navigation between wrap mt-4 gap-3">
                <button type="button" id="previous-factor" class="btn btn-light button button-outline">Faktor sebelumnya</button>
                <button type="button" id="next-factor" class="btn btn-primary button button-primary">Faktor berikutnya</button>
            </div>
        </form>

        <aside class="evaluation-sidebar sticky" hidden>
            <section class="card card-pad panel live-panel" style="margin-bottom:18px;">
                <div class="section-top" style="margin-bottom:14px;">
                    <div>
                        <h2 class="section-title">Progres dan skor</h2>
                        <p id="evaluation-completeness" class="section-desc muted">0 soal terjawab</p>
                    </div>
                </div>
                <progress id="evaluation-progress" max="100" value="0" aria-label="Kelengkapan jawaban"></progress>
                <div id="factor-progress" style="display:grid;gap:10px;margin-bottom:18px;"></div>
                <div class="card-inset" style="padding:16px;background:var(--canvas);border-radius:12px;margin-bottom:16px;">
                    <div class="tiny uppercase muted" style="font-weight:700;letter-spacing:.5px;">Skor rata-rata (3 faktor)</div>
                    <div class="form-score" style="display:flex;align-items:baseline;gap:4px;margin:6px 0;">
                        <strong id="live-average" style="font:700 34px var(--font-sans);color:var(--brand-primary);">—</strong>
                        <small id="live-score-unit" class="muted" hidden>/100</small>
                    </div>
                    <div style="margin-top:6px;">
                        <span class="tiny muted" style="display:block;margin-bottom:2px;">Kategori saat ini:</span>
                        <strong id="live-category" style="font-size:12px;">—</strong>
                    </div>
                </div>
                <p id="evaluation-status" class="evaluation-status muted mb-4" role="status"></p>
                <p id="evaluation-server-result" class="server-result note-card" role="status" style="margin-bottom:14px;" hidden></p>
                <div style="display:grid;gap:8px;">
                    <button id="save-evaluation" type="submit" form="evaluation-form" class="btn btn-primary btn-full button button-primary w-full" disabled>Simpan sebagai Final</button>
                    <button id="preview-draft" type="button" class="btn btn-light btn-full button button-outline w-full" disabled>Pratinjau & Simpan Draft</button>
                    <button id="reset-evaluation" type="button" class="btn btn-light btn-full button button-ghost w-full" disabled>Reset Form</button>
                </div>
            </section>

            <section class="card card-pad panel evaluation-guide" aria-labelledby="guide-title">
                <h3 id="guide-title">Panduan pengisian</h3>
                <ul>
                    <li>Simpan sebagai Draft untuk melanjutkan pengisian nanti.</li>
                    <li>Setelah semua soal dijawab, pilih Simpan sebagai Final untuk menetapkan hasil.</li>
                    <li>Reset Form mengosongkan isian saat ini. Evaluasi yang tersimpan tetap tersedia.</li>
                </ul>
            </section>
        </aside>
    </div>
</div>

<dialog id="draft-preview-modal" class="modal dialog preview-modal" aria-labelledby="draft-preview-title">
    <div class="dialog-head modal-heading">
        <h2 id="draft-preview-title" class="section-title">Pratinjau Draft Evaluasi</h2>
        <button type="button" class="icon-button" data-close-dialog aria-label="Tutup pratinjau"><x-icon name="close"/></button>
    </div>
    <div class="dialog-body">
        <p class="muted tiny">Pratinjau evaluasi belum disimpan ke server sebelum Anda menekan Simpan.</p>
        <div id="draft-preview-content" style="margin-top:16px;"></div>
    </div>
    <div class="dialog-footer modal-actions">
        <button type="button" class="btn btn-light button button-outline" data-close-dialog>Kembali Mengisi</button>
        <button type="button" id="save-draft" class="btn btn-primary button button-primary">Simpan sebagai Draft</button>
    </div>
</dialog>
@endsection
