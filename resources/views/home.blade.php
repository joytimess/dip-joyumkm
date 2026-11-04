@extends('layouts.app', ['page' => 'home', 'title' => 'Home'])
@section('content')
<h1 class="sr-only">Home JoyUMKM</h1>
@include('components.banner')
@include('components.summary')
<section class="panel home-intro"><div><h2>Ruang pendampingan Anda</h2><p class="muted">Kelola data UMKM dan lanjutkan evaluasi kondisi usahanya.</p></div><a href="{{ route('umkms') }}" class="button button-primary">Kelola UMKM <x-icon name="arrow" /></a></section>
@endsection
