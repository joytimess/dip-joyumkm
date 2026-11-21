@extends('layouts.app', ['page' => 'six-factor-evaluation', 'title' => 'Penilaian Enam Faktor'])

@section('content')
<div id="umkm-detail" class="page" data-umkm="{{ request()->route('id') }}">
    @include('components.umkm-breadcrumb', ['current' => 'Penilaian Enam Faktor', 'detailId' => request()->route('id')])
    <div class="page-heading">
        <div class="min-w-0">
            <h1>Penilaian Enam Faktor</h1>
            <p id="detail-title" class="muted break-words"></p>
        </div>
    </div>
    <div id="umkm-detail-state" class="mb-4" role="status" aria-busy="true"><span class="sr-only">Memuat profil UMKM</span><div class="skeleton h-4 w-1/3" aria-hidden="true"></div></div>

    <div id="six-factor-evaluation" hidden>
        <div class="six-factor-layout">
            <section class="min-w-0" aria-labelledby="draft-title">
                <div class="section-top">
                    <h2 id="draft-title" class="section-title" tabindex="-1">Draft</h2>
                </div>
                <div id="draft-load" aria-live="polite"></div>
                <p id="draft-notice" class="mb-4" role="status" hidden></p>
                <form id="six-factor-form" hidden>
                    <div class="card six-factor-progress">
                        <div class="between wrap">
                            <label for="draft-progress">Kemajuan pengisian</label>
                            <span id="draft-progress-text" class="pill pill-blue num"></span>
                        </div>
                        <progress id="draft-progress" value="0"></progress>
                        <p class="section-desc">Pilih nilai 1–5 untuk tiap faktor. Status di bawah daftar faktor menunjukkan apakah perubahan sudah tersimpan secara otomatis.</p>
                    </div>
                    <fieldset id="draft-scores" class="grid gap-4" disabled></fieldset>
                    <p class="form-message mt-4" role="alert" hidden></p>
                    <div class="card six-factor-footer mt-4">
                        <div class="hstack wrap six-factor-feedback">
                            <p id="draft-status" role="status" aria-atomic="true"></p>
                            <button id="retry-save" type="button" class="btn btn-light button button-outline" hidden>Coba lagi</button>
                        </div>
                        <div class="hstack wrap six-factor-actions">
                            <button id="cancel-draft" type="button" class="btn btn-light button button-outline" disabled>Batalkan Draft</button>
                            <button id="finalize-draft" type="button" class="btn btn-primary button button-primary" disabled>Finalisasi</button>
                        </div>
                    </div>
                </form>
            </section>
            <section class="card card-pad panel chart-panel" aria-labelledby="latest-title">
                <div class="section-top"><h2 id="latest-title" class="section-title">Final terbaru</h2></div>
                <div id="latest-result" aria-live="polite"></div>
            </section>
        </div>
        <section class="card card-pad panel section-block" aria-labelledby="history-title">
            <div class="section-top"><h2 id="history-title" class="section-title" tabindex="-1">Histori Final</h2></div>
            <div id="final-history" aria-live="polite"></div>
        </section>
    </div>
</div>

<dialog id="draft-confirmation" class="modal dialog" aria-labelledby="draft-confirmation-title" aria-describedby="draft-confirmation-description">
    <div class="dialog-head"><h2 id="draft-confirmation-title" class="section-title"></h2></div>
    <div class="dialog-body"><p id="draft-confirmation-description"></p></div>
    <div class="dialog-footer wrap">
        <button type="button" class="btn btn-light button button-outline" data-close-dialog autofocus>Kembali ke Draft</button>
        <button id="confirm-draft-action" type="button" class="btn btn-primary button button-primary"></button>
    </div>
</dialog>

<dialog id="historical-result" class="modal dialog" aria-labelledby="historical-title">
    <div class="dialog-head">
        <h2 id="historical-title" class="section-title"></h2>
        <button type="button" class="icon-button" data-close-dialog aria-label="Tutup hasil Final"><x-icon name="close"/></button>
    </div>
    <div id="historical-content" class="dialog-body"></div>
</dialog>
@endsection
