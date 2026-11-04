@extends('layouts.app', ['page' => 'comparison', 'title' => 'Comparison'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Analitik / perbandingan</div>
            <h1>Perbandingan UMKM</h1>
            <p class="muted">Temukan keunggulan dan ruang perbaikan hingga lima UMKM dalam satu tampilan.</p>
        </div>
    </div>
    <section class="card filter-panel panel picker-panel" aria-labelledby="picker-title" style="margin-bottom:20px;">
        <div class="section-top section-heading">
            <div>
                <h2 id="picker-title" class="section-title">Susun perbandingan Anda</h2>
                <p class="section-desc muted">Pilih maksimal lima UMKM. Baseline tetap memakai seluruh portofolio.</p>
            </div>
        </div>
        <div id="umkm-picker"><div class="skeleton h-10"></div></div>
        <div class="comparison-actions" style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0 12px;">
            <button type="button" id="comparison-add" class="btn btn-primary button button-primary" disabled><x-icon name="plus" /> Tambahkan</button>
            <button type="button" id="comparison-default" class="btn btn-light button button-outline">Pilih otomatis</button>
            <button type="button" id="comparison-clear" class="btn btn-light button button-ghost" disabled>Kosongkan pilihan</button>
        </div>
        <p id="comparison-count" class="muted small" aria-live="polite">Maksimal 5 UMKM dipilih</p>
        <ul id="comparison-selected" class="comparison-selected chip-list" aria-label="UMKM yang dibandingkan" style="margin-top:12px;list-style:none;padding:0;"></ul>
        <p class="picker-help muted tiny" style="margin-top:12px;">Pilihan otomatis mengikuti urutan skor kesehatan tertinggi pada portofolio Anda.</p>
    </section>
    <div id="page-data" aria-live="polite" aria-busy="true"><div class="panel card"><div class="skeleton h-64"></div></div></div>
</div>
@endsection
