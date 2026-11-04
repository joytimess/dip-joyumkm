@extends('layouts.app', ['page' => 'distribution', 'title' => 'Distribution'])
@section('content')
<div class="page-heading"><div><h1>Distribusi Kesehatan</h1><p class="muted">Kategori seluruh UMKM dan rerata skor tiap sektor usaha Anda.</p></div></div>
<div id="page-data" aria-live="polite" aria-busy="true"><div class="panel"><div class="skeleton h-64"></div></div></div>
@endsection
