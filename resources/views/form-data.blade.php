@extends('layouts.app', ['page' => 'form-data', 'title' => 'Form Data'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Evaluasi usaha</div>
            <h1>Form Data</h1>
            <p class="muted">Kenali kondisi usaha melalui tiga faktor evaluasi dan pantau kalkulasi skor secara langsung.</p>
        </div>
        <div class="heading-actions">
            <a href="{{ route('umkms') }}" class="btn btn-light button button-outline">Kembali ke UMKM</a>
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
            <div id="factor-tabs" class="factor-tabs" role="tablist" aria-label="Faktor evaluasi" style="margin-bottom:18px;"></div>
            <div id="questionnaire-factors"></div>
            <div id="factor-navigation" class="factor-navigation between" style="margin-top:20px;display:flex;justify-content:space-between;gap:12px;">
                <button type="button" id="previous-factor" class="btn btn-light button button-outline">Faktor sebelumnya</button>
                <button type="button" id="next-factor" class="btn btn-primary button button-primary">Faktor berikutnya</button>
            </div>
        </form>

        <aside class="evaluation-sidebar sticky">
            <section class="card card-pad panel live-panel" style="margin-bottom:18px;">
                <div class="section-top" style="margin-bottom:14px;">
                    <div>
                        <h2 class="section-title">Live Calculation</h2>
                        <p id="evaluation-completeness" class="section-desc muted">0 soal terjawab</p>
                    </div>
                </div>
                <progress id="evaluation-progress" max="100" value="0" aria-label="Kelengkapan jawaban" style="width:100%;height:6px;accent-color:var(--brand-secondary);margin-bottom:16px;"></progress>
                <div id="factor-progress" style="display:grid;gap:10px;margin-bottom:18px;"></div>
                <div class="card-inset" style="padding:16px;background:var(--canvas);border-radius:12px;margin-bottom:16px;">
                    <div class="tiny uppercase muted" style="font-weight:750;letter-spacing:.5px;">Skor rata-rata (3 faktor)</div>
                    <div class="form-score" style="display:flex;align-items:baseline;gap:4px;margin:6px 0;">
                        <strong id="live-average" style="font:800 34px var(--font-sans);color:var(--brand-primary);">—</strong>
                        <small class="muted" style="font-size:13px;">/100</small>
                    </div>
                    <div style="margin-top:6px;">
                        <span class="tiny muted" style="display:block;margin-bottom:2px;">Kategori saat ini:</span>
                        <strong id="live-category" style="font-size:12px;">—</strong>
                    </div>
                </div>
                <p id="evaluation-status" class="evaluation-status muted tiny" style="line-height:1.6;margin-bottom:14px;">Pilih UMKM untuk mulai mengisi.</p>
                <p id="evaluation-server-result" class="server-result note-card" role="status" style="margin-bottom:14px;" hidden></p>
                <div style="display:grid;gap:8px;">
                    <button id="save-evaluation" type="submit" form="evaluation-form" class="btn btn-primary btn-full button button-primary w-full" disabled>Simpan sebagai Final</button>
                    <button id="preview-draft" type="button" class="btn btn-light btn-full button button-outline w-full" disabled>Pratinjau & Simpan Draft</button>
                    <button id="reset-evaluation" type="button" class="btn btn-light btn-full button button-ghost w-full" disabled>Reset Form</button>
                </div>
            </section>

            <section class="card card-pad guide guide-panel panel">
                <h3 style="font:800 16px var(--font-sans);margin:0 0 8px;">Panduan pengisian</h3>
                <p style="font-size:11px;line-height:1.65;margin:0 0 12px;color:#E0EBFF;">Jawab kuesioner sesuai kondisi usaha nyata. Anda dapat menyimpan draft dan melanjutkannya kapan saja.</p>
                <div class="guide-tip" style="background:rgba(255,255,255,.14);padding:12px;border-radius:11px;font-size:11px;line-height:1.6;margin-bottom:12px;">
                    <strong>Penting:</strong> Seluruh soal terisi tidak otomatis menjadikan status evaluasi Final. Evaluasi tetap Sementara sampai Anda menyimpannya sebagai Final.
                </div>
                <p class="tiny" style="font-size:10px;line-height:1.6;margin:0;color:#CAD8FF;">Reset form hanya mengosongkan isian di tampilan ini dan tidak menghapus evaluasi tersimpan di server.</p>
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
