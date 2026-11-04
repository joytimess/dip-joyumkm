@extends('layouts.app', ['page' => 'factor-profile', 'title' => 'Factor Profile'])
@section('content')
<div class="page-heading"><div><h1>Profil Faktor</h1><p class="muted">Bandingkan kondisi satu UMKM dengan rerata seluruh UMKM Anda.</p></div></div>
<section class="panel picker-panel" aria-labelledby="picker-title"><h2 id="picker-title">Pilih UMKM</h2><div id="umkm-picker"><div class="skeleton h-10"></div></div></section>
<div id="page-data" aria-live="polite" aria-busy="true"><div class="panel"><div class="skeleton h-64"></div></div></div>
@endsection
