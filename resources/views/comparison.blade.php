@extends('layouts.app', ['page' => 'comparison', 'title' => 'Comparison'])
@section('content')
<div class="page-heading"><div><h1>Perbandingan UMKM</h1><p class="muted">Bandingkan hingga lima UMKM dengan rerata faktor seluruh portofolio Anda.</p></div></div>
<section class="panel picker-panel" aria-labelledby="picker-title">
    <h2 id="picker-title">Pilih UMKM untuk dibandingkan</h2>
    <div id="umkm-picker"><div class="skeleton h-10"></div></div>
    <div class="comparison-actions"><button type="button" id="comparison-add" class="button button-primary" disabled>Tambahkan ke perbandingan</button><button type="button" id="comparison-default" class="button button-outline">Pilih otomatis</button><button type="button" id="comparison-clear" class="button button-ghost" disabled>Kosongkan pilihan</button></div>
    <p id="comparison-count" class="muted" aria-live="polite">Maksimal 5 UMKM dipilih</p>
    <ul id="comparison-selected" class="comparison-selected" aria-label="UMKM yang dibandingkan"></ul>
</section>
<div id="page-data" aria-live="polite" aria-busy="true"><div class="panel"><div class="skeleton h-64"></div></div></div>
@endsection
