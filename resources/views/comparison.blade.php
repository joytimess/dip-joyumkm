@extends('layouts.app', ['page' => 'comparison', 'title' => 'Comparison'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <h1>Perbandingan UMKM</h1>
            <p class="muted">Temukan keunggulan dan ruang perbaikan hingga lima UMKM dalam satu tampilan.</p>
        </div>
    </div>
    <section class="card filter-panel panel picker-panel comparison-picker" aria-labelledby="picker-title">
        <div class="section-top section-heading">
            <div>
                <h2 id="picker-title" class="section-title">Pilih usaha yang dibandingkan</h2>
                <p class="section-desc muted">Pilih maksimal lima UMKM. Baseline tetap memakai seluruh portofolio.</p>
            </div>
            <span id="comparison-capacity" class="pill pill-muted" aria-live="polite">0 / 5 terisi</span>
        </div>
        <div class="comparison-picker-row">
            <div id="umkm-picker"><div class="skeleton h-10"></div></div>
            <div class="comparison-actions">
                <button type="button" id="comparison-add" class="btn btn-primary button button-primary" disabled><x-icon name="plus" /> Tambahkan</button>
                <button type="button" id="comparison-default" class="btn btn-light button button-outline"><x-icon name="spark" /> Pilih otomatis</button>
                <button type="button" id="comparison-clear" class="btn btn-light button button-ghost" disabled><x-icon name="trash" /> Kosongkan pilihan</button>
            </div>
        </div>
        <p id="comparison-count" class="muted small" aria-live="polite">Maksimal 5 UMKM dipilih</p>
        <ul id="comparison-selected" class="comparison-selected chip-list" aria-label="UMKM yang dibandingkan"></ul>
        <p class="picker-help muted tiny"><x-icon name="info" /><span>Pilihan otomatis mengikuti urutan skor kesehatan tertinggi pada portofolio Anda.</span></p>
    </section>
    <div id="page-data" aria-live="polite" aria-busy="true"><x-chart-skeleton /></div>
</div>
@endsection
