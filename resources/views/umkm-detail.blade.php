@extends('layouts.app', ['page' => 'umkm-detail', 'navigationPage' => 'umkms', 'title' => 'Detail UMKM'])

@section('content')
<div id="umkm-detail" class="page" data-umkm="{{ request()->route('id') }}">
    @include('components.umkm-breadcrumb', ['current' => 'Detail UMKM'])
    <div class="page-heading">
        <h1 class="break-words" id="detail-title">Detail UMKM</h1>
    </div>
    <div id="umkm-detail-state" class="card card-pad panel" role="status" aria-busy="true">
        <span class="sr-only">Memuat detail UMKM</span>
        <div class="skeleton h-5 w-1/3 mb-4" aria-hidden="true"></div>
        <div class="grid-3" aria-hidden="true">
            @foreach (range(1, 3) as $field)
                <div class="grid gap-3"><div class="skeleton h-3 w-1/3"></div><div class="skeleton h-5 w-2/3"></div></div>
            @endforeach
        </div>
    </div>
    <div id="umkm-detail-content" class="two-col-6-4 umkm-detail-grid" hidden>
        <section class="card card-pad panel" aria-labelledby="methods-title">
            <h2 id="methods-title">Penilaian usaha</h2>
            <div class="detail-method">
                <div><h3>Kuesioner Tiga Dimensi</h3><p class="muted">Jawab pertanyaan untuk melihat skor kesehatan usaha hingga 100.</p></div>
                <a data-carry-list href="{{ route('form-data', ['umkm' => request()->route('id')]) }}" class="btn button button-primary">Isi Kuesioner</a>
            </div>
            <div class="detail-method">
                <div><h3>Penilaian Enam Faktor</h3><p class="muted">Beri nilai 1–5 pada tiap faktor. Lihat hasil Final dan riwayat di halaman penilaian.</p></div>
                <a data-carry-list href="{{ route('umkms.six-factor-evaluations', ['id' => request()->route('id')]) }}" class="btn button button-outline">Penilaian Enam Faktor</a>
            </div>
        </section>
        <section class="card card-pad panel" aria-labelledby="business-title">
            <h2 id="business-title">Informasi usaha</h2>
            <dl class="detail-facts">
                <div><dt>Sektor</dt><dd id="detail-sector"></dd></div>
                <div><dt>Provinsi</dt><dd id="detail-province"></dd></div>
                <div><dt>Omzet</dt><dd id="detail-omzet"></dd></div>
            </dl>
            <div class="detail-assessment">
                <h3>Kuesioner terbaru</h3>
                <p id="detail-assessment-status" class="muted"></p>
                <p id="detail-assessment-score" class="num"></p>
            </div>
        </section>
    </div>
</div>
@endsection
