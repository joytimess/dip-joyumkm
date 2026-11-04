@extends('layouts.app', ['page' => 'distribution', 'title' => 'Distribution'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Analitik / distribusi</div>
            <h1>Distribusi Kesehatan</h1>
            <p class="muted">Lihat proporsi kategori kesehatan dan persebaran skor berdasarkan sektor usaha.</p>
        </div>
        <div class="heading-actions">
            <a href="{{ route('umkms') }}" class="btn btn-light button button-outline">Lihat data UMKM <x-icon name="arrow" /></a>
        </div>
    </div>
    <div id="page-data" aria-live="polite" aria-busy="true"><div class="panel card"><div class="skeleton h-64"></div></div></div>
</div>
@endsection
