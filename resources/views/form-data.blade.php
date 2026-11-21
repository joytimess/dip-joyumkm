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
                <button type="button" id="previous-factor" class="btn btn-light button button-outline">Dimensi sebelumnya</button>
                <button type="button" id="next-factor" class="btn btn-primary button button-primary">Dimensi berikutnya</button>
            </div>
        </form>

        <aside class="evaluation-sidebar sticky" hidden>
            <section class="card card-pad panel live-panel" aria-labelledby="live-title">
                <div class="live-head">
                    <h2 id="live-title" class="section-title">Progres dan skor</h2>
                    <span id="evaluation-badge" class="live-badge" hidden></span>
                </div>

                <div class="live-progress">
                    <div class="live-progress-meta">
                        <p id="evaluation-completeness" class="muted">0 dari 0 soal terjawab</p>
                        <strong id="evaluation-percent" class="num">0%</strong>
                    </div>
                    <progress id="evaluation-progress" class="live-meter" max="100" value="0" aria-label="Kelengkapan jawaban"></progress>
                </div>

                <div class="live-score">
                    <div>
                        <span class="live-label">Skor rata-rata (3 faktor)</span>
                        <div class="live-score-value">
                            <strong id="live-average" class="num">—</strong>
                            <small id="live-score-unit" class="muted" hidden>/100</small>
                        </div>
                    </div>
                    <div class="live-category-block">
                        <span class="live-label">Kategori saat ini</span>
                        <span id="live-category" class="live-category">—</span>
                    </div>
                </div>

                <div class="live-factors">
                    <h3 class="live-label">Rincian per faktor</h3>
                    <div id="factor-progress"></div>
                </div>

                <p id="evaluation-status" class="evaluation-status" role="status"></p>
                <p id="evaluation-server-result" class="server-result" role="status" hidden></p>

                <div class="live-actions">
                    <button id="save-evaluation" type="submit" form="evaluation-form" class="btn btn-primary btn-full button button-primary" disabled>
                        <x-icon name="check"/><span data-button-label>Simpan sebagai Final</span>
                    </button>
                    <button id="preview-draft" type="button" class="btn btn-light btn-full button button-outline" disabled>
                        <x-icon name="eye"/><span>Pratinjau &amp; Simpan Draft</span>
                    </button>
                    <button id="reset-evaluation" type="button" class="btn btn-light btn-full button button-ghost" disabled>
                        <x-icon name="reset"/><span>Reset Form</span>
                    </button>
                </div>
            </section>

            <details class="card card-pad panel evaluation-guide" open>
                <summary>
                    <x-icon name="info"/><span id="guide-title">Panduan pengisian</span>
                </summary>
                <ul>
                    <li><x-icon name="save"/><span>Simpan sebagai <strong>Draft</strong> untuk melanjutkan pengisian nanti.</span></li>
                    <li><x-icon name="check"/><span>Setelah semua soal dijawab, pilih <strong>Simpan sebagai Final</strong> untuk menetapkan hasil.</span></li>
                    <li><x-icon name="reset"/><span><strong>Reset Form</strong> mengosongkan isian saat ini. Evaluasi yang tersimpan tetap tersedia.</span></li>
                </ul>
            </details>
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
