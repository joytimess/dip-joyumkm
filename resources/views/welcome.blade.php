@extends('layouts.public', ['page' => 'welcome', 'title' => 'Kenali kondisi usaha'])
@section('content')
<header class="public-nav">
    <a href="{{ route('welcome') }}" class="brand"><span class="brand-mark">J</span><span>JoyUMKM<small>UMKM Health Dashboard</small></span></a>
    <nav aria-label="Navigasi publik" class="flex items-center gap-3"><a class="button button-ghost" href="{{ route('login') }}">Masuk</a><a class="button button-primary" href="{{ route('register') }}">Daftar Akun</a></nav>
</header>
<main>
    <section class="landing-hero">
        <div class="landing-copy">
            <h1>Kenali kondisi usaha.<br>Tentukan langkah berikutnya.</h1>
            <p>Ruang kerja pendamping untuk mengelola UMKM, menilai kesehatan bisnis, dan melihat prioritas pendampingan dalam satu tempat.</p>
            <div class="flex flex-wrap gap-3"><a class="button button-primary" href="{{ route('register') }}">Mulai Mendampingi <x-icon name="arrow" /></a><a class="button button-outline" href="{{ route('login') }}">Masuk ke JoyUMKM</a></div>
            <p class="landing-note">Data setiap pendamping tersimpan dalam akun masing-masing.</p>
        </div>
        <div class="instrument-panel">
            <div class="instrument-header"><span class="brand-mark">J</span><p>Kenali bisnis dari<br><strong>tiga sudut pandang.</strong></p></div>
            <div class="instrument-row"><span class="factor-dot factor-ins"></span><div><strong>Sumber Daya</strong><p>Institutional Resources</p></div><span>INS</span></div>
            <div class="instrument-row"><span class="factor-dot factor-ect"></span><div><strong>Eksternal</strong><p>Economic Connectivity</p></div><span>ECT</span></div>
            <div class="instrument-row"><span class="factor-dot factor-ops"></span><div><strong>Operasional</strong><p>Operational Stability</p></div><span>OPS</span></div>
            <p class="instrument-foot">Dari jawaban menjadi gambaran bisnis yang lebih jelas.</p>
        </div>
    </section>
    <section class="landing-explainer"><h2>Pendampingan dimulai<br>dari pemahaman.</h2><div><p>Catat UMKM yang Anda dampingi, isi kuesioner sesuai kondisi usaha, lalu lihat hasil evaluasi melalui ringkasan dan grafik.</p><p>Bandingkan sumber daya, konektivitas ekonomi, dan stabilitas operasional untuk membantu menentukan perhatian berikutnya.</p><p class="muted">Skor merupakan indikator berdasarkan kuesioner, bukan diagnosis finansial tervalidasi.</p></div></section>
</main>
@include('components.footer')
@endsection
