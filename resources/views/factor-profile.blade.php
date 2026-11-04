@extends('layouts.app', ['page' => 'factor-profile', 'title' => 'Factor Profile'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Analitik / faktor</div>
            <h1>Profil Faktor</h1>
            <p class="muted">Bandingkan profil tiga faktor pada satu UMKM dengan rata-rata seluruh portofolio.</p>
        </div>
        <div class="heading-actions">
            <a href="{{ route('comparison') }}" class="btn btn-light button button-outline"><x-icon name="comparison" /> Bandingkan UMKM</a>
        </div>
    </div>
    <section class="card filter-panel panel picker-panel" aria-labelledby="picker-title" style="margin-bottom:20px">
        <div class="section-top section-heading">
            <div>
                <h2 id="picker-title" class="section-title">Pilih UMKM</h2>
                <p class="section-desc muted">Cari usaha untuk melihat profil dan evaluasi terbarunya.</p>
            </div>
        </div>
        <div id="umkm-picker"><div class="skeleton h-10"></div></div>
    </section>
    <div id="page-data" aria-live="polite" aria-busy="true"><div class="panel card"><div class="skeleton h-64"></div></div></div>
</div>
@endsection
