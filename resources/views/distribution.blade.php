@extends('layouts.app', ['page' => 'distribution', 'title' => 'Distribution'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <h1>Distribusi Kesehatan</h1>
            <p class="muted">Lihat proporsi kategori kesehatan dan persebaran skor berdasarkan sektor usaha.</p>
        </div>
        <div class="heading-actions">
            <a href="{{ route('umkms') }}" class="btn btn-light button button-outline">Lihat data UMKM <x-icon name="arrow" /></a>
        </div>
    </div>
    <div id="page-data" aria-live="polite" aria-busy="true">
        <div aria-hidden="true">
            <div class="grid-4 metric-strip mb-4">
                @foreach (['Total UMKM', 'Memiliki skor', 'Evaluasi sementara', 'Sektor terwakili'] as $metric)
                    <div class="card metric panel"><p class="metric-label">{{ $metric }}</p><div class="skeleton h-10 w-1/2 mt-4"></div><div class="skeleton h-3 w-3/4 mt-3"></div></div>
                @endforeach
            </div>
            <div class="two-col distribution-grid">
                <x-chart-skeleton />
                <x-chart-skeleton />
            </div>
        </div>
    </div>
</div>
@endsection
