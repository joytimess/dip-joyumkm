@extends('layouts.public', ['page' => 'welcome', 'title' => 'Kenali Kondisi Usaha'])
@section('content')
<main id="main-content" tabindex="-1">
    <header class="header"><div class="container header-inner">
      <x-public-brand />
      <nav class="header-links" aria-label="Navigasi utama"><a href="#cara-kerja">Cara Kerja</a><a href="#dimensi">Tiga Dimensi</a></nav>
      <div class="header-actions"><a class="login-link" href="{{ route('login') }}">Masuk</a><a class="btn btn-primary" href="{{ route('register') }}">Daftar Akun <svg aria-hidden="true"><use href="#joy-i-arrow"/></svg></a></div>
    </div></header>
    <section class="hero"><div class="container hero-grid">
      <div class="hero-text">
        <div class="eyebrow"><i></i> Pendampingan UMKM lebih terarah</div>
        <h1>Kenali kondisi usaha. <em>Tentukan langkah</em> berikutnya.</h1>
        <p class="hero-copy">Kelola UMKM yang Anda dampingi, pahami kesehatan bisnis dari tiga sudut pandang, dan ubah hasil evaluasi menjadi prioritas yang lebih jelas.</p>
        <div class="hero-actions"><a href="{{ route('register') }}" class="btn btn-primary">Mulai Mendampingi <svg aria-hidden="true"><use href="#joy-i-arrow"/></svg></a><a href="{{ route('login') }}" class="btn btn-outline">Sudah punya akun? Masuk</a></div>
        <div class="hero-proof"><span class="proof-icon"><svg aria-hidden="true"><use href="#joy-i-shield"/></svg></span><span>Data pendampingan tersimpan di akun masing-masing.<br />Visualisasi dibuat untuk membantu memahami, bukan menggantikan penilaian pendamping.</span></div>
      </div>
      <div class="preview-stage" data-preview-motion aria-label="Ilustrasi dashboard kesehatan UMKM">
        <div class="dashboard-preview">
          <div class="dash-top"><div><h2 class="dash-title">Ringkasan kesehatan usaha</h2><p class="dash-note">Tiga dimensi, satu pandangan</p></div><span class="tag-demo">Data ilustrasi</span></div>
          <div class="dash-main">
            <div class="score-block"><div class="score-outer"><div class="score-inner"><strong>74</strong><span>Skor contoh</span></div></div><div class="score-label">Gambaran keseluruhan</div></div>
            <div class="metrics"><div class="metric"><div class="metric-head"><span>Sumber Daya · INS</span><span>78</span></div><div class="track"><div class="fill" style="width:78%"></div></div></div><div class="metric"><div class="metric-head"><span>Eksternal · ECT</span><span>62</span></div><div class="track"><div class="fill secondary" style="width:62%"></div></div></div><div class="metric"><div class="metric-head"><span>Operasional · OPS</span><span>86</span></div><div class="track"><div class="fill accent" style="width:86%"></div></div></div></div>
          </div>
          <div class="dash-bottom"><svg aria-hidden="true"><use href="#joy-i-spark"/></svg><div><b>Lebih mudah melihat prioritas</b><span>Mulai dari dimensi yang membutuhkan perhatian.</span></div></div>
        </div>
        <div class="float-label float-left"><span class="bubble"><svg aria-hidden="true"><use href="#joy-i-chart"/></svg></span> Hasil mudah dipahami</div>
        <div class="float-label float-right"><span class="bubble"><svg aria-hidden="true"><use href="#joy-i-check"/></svg></span> Terstruktur</div>
      </div>
    </div></section>
    
    <section id="cara-kerja" class="section editorial-section workflow-section" aria-labelledby="workflow-heading">
      <div class="container">
        <div class="editorial-heading">
          <div class="editorial-heading-main">
            <p class="kicker">Alur pendampingan <span class="kicker-rule" aria-hidden="true"></span> 01 / 03</p>
            <h2 id="workflow-heading">Pendampingan dimulai<br />dari <span class="heading-accent">pemahaman.</span></h2>
          </div>
          <p class="editorial-intro">Tidak perlu berpindah-pindah alat. Dari pencatatan UMKM sampai membaca hasil evaluasi, semuanya dalam satu alur kerja.</p>
        </div>
        <div class="editorial-grid steps">
          <article class="step editorial-card workflow-card tone-peach">
            <div class="card-grain" aria-hidden="true"></div>
            <div class="card-top"><span class="card-index">01 <span class="index-separator">/</span> PENCATATAN</span><span class="card-top-star" aria-hidden="true">✳</span></div>
            <div class="art art-planet" aria-hidden="true"><div class="orbit orbit-a"></div><div class="orbit orbit-b"></div><div class="planet-core"></div><span class="planet-star planet-star-a">✦</span><span class="planet-star planet-star-b">✧</span></div>
            <div class="card-copy"><span class="card-overline">LANGKAH PERTAMA</span><h3>Catat UMKM<span class="copy-dot">.</span></h3><p>Buat data usaha yang Anda dampingi agar informasi dan riwayatnya tersusun rapi.</p></div>
            <a class="round-link" href="{{ route('register') }}" aria-label="Mulai pendampingan dengan mencatat UMKM"><svg aria-hidden="true"><use href="#joy-i-arrow" /></svg></a>
          </article>
          <article class="step editorial-card workflow-card tone-blue">
            <div class="card-grain" aria-hidden="true"></div>
            <div class="card-top"><span class="card-index">02 <span class="index-separator">/</span> PENILAIAN</span><span class="card-top-star" aria-hidden="true">✦</span></div>
            <div class="art art-evaluation" aria-hidden="true"><div class="evaluation-glow"></div><div class="eval-tile tile-back"></div><div class="eval-tile tile-front"><div class="eval-icon"><svg><use href="#joy-i-chart" /></svg></div><span class="eval-line long"></span><span class="eval-line short"></span><div class="eval-mini-bars"><i></i><i></i><i></i><i></i></div></div><span class="eval-spark">✳</span></div>
            <div class="card-copy"><span class="card-overline">LANGKAH KEDUA</span><h3>Isi evaluasi<span class="copy-dot">.</span></h3><p>Jawab kuesioner berdasarkan kondisi nyata bisnis dari tiga dimensi penilaian.</p></div>
            <a class="round-link" href="{{ route('register') }}" aria-label="Mulai pendampingan dengan mengisi evaluasi"><svg aria-hidden="true"><use href="#joy-i-arrow" /></svg></a>
          </article>
          <article class="step editorial-card workflow-card tone-royal">
            <div class="card-grain" aria-hidden="true"></div>
            <div class="card-top"><span class="card-index">03 <span class="index-separator">/</span> PEMAHAMAN</span><span class="card-top-star" aria-hidden="true">✳</span></div>
            <div class="art art-insight" aria-hidden="true"><div class="insight-aura"></div><div class="insight-disc disc-back"></div><div class="insight-disc disc-mid"></div><div class="insight-disc disc-front"><svg viewBox="0 0 110 110"><path d="M20 72 42 49 60 60 89 26" fill="none" stroke="white" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/><path d="M73 26h16v16" fill="none" stroke="white" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/></svg></div><span class="insight-twink">✦</span></div>
            <div class="card-copy"><span class="card-overline">LANGKAH KETIGA</span><h3>Pahami prioritas<span class="copy-dot">.</span></h3><p>Baca ringkasan dan visualisasi untuk menentukan fokus pendampingan berikutnya.</p></div>
            <a class="round-link" href="{{ route('register') }}" aria-label="Daftar untuk memahami prioritas pendampingan"><svg aria-hidden="true"><use href="#joy-i-arrow" /></svg></a>
          </article>
        </div>
        <div class="editorial-endnote"><span class="small-spark" aria-hidden="true">✳</span> Satu alur yang membantu Anda bergerak dari data menuju keputusan pendampingan.</div>
      </div>
    </section>
    
    <section id="dimensi" class="section editorial-section dimensions-section" aria-labelledby="dimensions-heading">
      <div class="container">
        <div class="editorial-heading">
          <div class="editorial-heading-main">
            <p class="kicker">Tiga sudut pandang <span class="kicker-rule" aria-hidden="true"></span> INS / ECT / OPS</p>
            <h2 id="dimensions-heading">Satu usaha, dilihat<br /><span class="heading-accent">lebih menyeluruh.</span></h2>
          </div>
          <p class="editorial-intro">Ketiga dimensi memberi konteks yang saling melengkapi, bukan sekadar angka tanpa penjelasan.</p>
        </div>
        <div class="editorial-grid dimension-grid">
          <article class="dimension editorial-card dimension-card dimension-ins">
            <div class="card-grain" aria-hidden="true"></div>
            <div class="card-top"><span class="card-index">INS <span class="index-separator">/</span> 01</span><span class="card-top-symbol"><svg aria-hidden="true"><use href="#joy-i-users" /></svg></span></div>
            <div class="art art-resources" aria-hidden="true"><div class="res-ring ring-one"></div><div class="res-ring ring-two"></div><div class="res-ring ring-three"></div><div class="res-center"><svg><use href="#joy-i-users" /></svg></div><span class="res-spark">✦</span></div>
            <div class="card-copy"><span class="card-overline">INSTITUTIONAL RESOURCES</span><h3>Sumber Daya<span class="copy-dot">.</span></h3><p>Melihat sumber daya dan kapasitas internal usaha yang mendukung keberlanjutan bisnis.</p></div>
            <a class="round-link" href="{{ route('register') }}" aria-label="Daftar untuk mengevaluasi dimensi Sumber Daya"><svg aria-hidden="true"><use href="#joy-i-arrow" /></svg></a>
          </article>
          <article class="dimension editorial-card dimension-card dimension-ect">
            <div class="card-grain" aria-hidden="true"></div>
            <div class="card-top"><span class="card-index">ECT <span class="index-separator">/</span> 02</span><span class="card-top-symbol"><svg aria-hidden="true"><use href="#joy-i-globe" /></svg></span></div>
            <div class="art art-connect" aria-hidden="true"><div class="connect-orbit"></div><div class="connect-globe"><svg viewBox="0 0 160 160"><circle cx="80" cy="80" r="60" fill="none" stroke="currentColor" stroke-width="2"/><ellipse cx="80" cy="80" rx="28" ry="60" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 80h120M31 50h98M31 110h98" fill="none" stroke="currentColor" stroke-width="2"/></svg></div><span class="connect-point point-1"></span><span class="connect-point point-2"></span><span class="connect-point point-3"></span></div>
            <div class="card-copy"><span class="card-overline">ECONOMIC CONNECTIVITY</span><h3>Eksternal<span class="copy-dot">.</span></h3><p>Memahami hubungan usaha dengan pasar, pelanggan, dan lingkungan ekonominya.</p></div>
            <a class="round-link" href="{{ route('register') }}" aria-label="Daftar untuk mengevaluasi dimensi Eksternal"><svg aria-hidden="true"><use href="#joy-i-arrow" /></svg></a>
          </article>
          <article class="dimension editorial-card dimension-card dimension-ops">
            <div class="card-grain" aria-hidden="true"></div>
            <div class="card-top"><span class="card-index">OPS <span class="index-separator">/</span> 03</span><span class="card-top-symbol"><svg aria-hidden="true"><use href="#joy-i-chart" /></svg></span></div>
            <div class="art art-operation" aria-hidden="true"><div class="operation-loop"></div><div class="operation-loop loop-small"></div><div class="operation-bars"><i></i><i></i><i></i><i></i><i></i></div><span class="operation-star">✳</span></div>
            <div class="card-copy"><span class="card-overline">OPERATIONAL STABILITY</span><h3>Operasional<span class="copy-dot">.</span></h3><p>Melihat stabilitas kegiatan operasional dalam menjalankan usaha sehari-hari.</p></div>
            <a class="round-link" href="{{ route('register') }}" aria-label="Daftar untuk mengevaluasi dimensi Operasional"><svg aria-hidden="true"><use href="#joy-i-arrow" /></svg></a>
          </article>
        </div>
        <div class="editorial-endnote"><span class="small-spark" aria-hidden="true">✳</span> Tiga dimensi yang saling melengkapi untuk memahami konteks setiap usaha.</div>
      </div>
    </section>
<section id="enam-faktor" class="section editorial-section factors-section" aria-labelledby="factors-heading">
<div class="container">
  <div class="editorial-heading">
    <div class="editorial-heading-main">
      <p class="kicker">Enam faktor penilaian <span class="kicker-rule" aria-hidden="true"></span> 01 — 06</p>
      <h2 id="factors-heading">Kenali usaha dari<br><span class="heading-accent">enam faktor utama.</span></h2>
    </div>
    <p class="editorial-intro">Pahami kondisi UMKM melalui enam faktor penilaian yang membantu pendamping melihat kekuatan, tantangan, dan area yang perlu dikembangkan.</p>
  </div>
  <div class="factor-grid">
    <article class="editorial-card factor-card resources">
      <div class="card-grain" aria-hidden="true"></div><div class="card-top"><span class="card-index">01 <span class="index-separator">/</span> FAKTOR</span><span class="card-top-star" aria-hidden="true">✳</span></div>
      <div class="factor-art" aria-hidden="true">
        <svg focusable="false" viewBox="0 0 440 240" fill="none" xmlns="http://www.w3.org/2000/svg">
          <defs><linearGradient id="joy-factor-glass" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#F5FCFF" stop-opacity=".96"/><stop offset="1" stop-color="#71C9FC" stop-opacity=".5"/></linearGradient><linearGradient id="joy-factor-base" x1="0" y1="0" x2=".7" y2="1"><stop stop-color="#A2E4FF"/><stop offset="1" stop-color="#2B6BEC"/></linearGradient><linearGradient id="joy-factor-warm" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#FFE0C8"/><stop offset="1" stop-color="#FC734D"/></linearGradient></defs>
          <ellipse cx="220" cy="121" rx="180" ry="90" stroke="#B6DFFF" stroke-opacity=".28" stroke-width="1.5" transform="rotate(-13 220 121)"/><ellipse cx="220" cy="121" rx="146" ry="74" stroke="#DDF6FF" stroke-opacity=".22" stroke-dasharray="5 7" transform="rotate(18 220 121)"/>
          <path d="M85 159 214 104 356 154 223 220Z" fill="#D8F3FF" fill-opacity=".28" stroke="#D5F4FF" stroke-opacity=".5"/><path d="M85 159 223 220v11L85 170Z" fill="#78B9FC" fill-opacity=".55"/><path d="M223 220 356 154v11l-133 66Z" fill="#1749C9"/>
          <g transform="translate(112 75) rotate(-8 55 55)"><rect x="7" y="7" width="108" height="115" rx="22" fill="#70B9FF" fill-opacity=".5"/><rect width="108" height="115" rx="22" fill="url(#joy-factor-glass)" stroke="#ECFAFF" stroke-width="1.5"/><rect x="14" y="14" width="80" height="59" rx="14" fill="#F2FAFF" fill-opacity=".82"/><g stroke="#0207CA" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="46" cy="35" r="8"/><path d="M28 60v-3a14 14 0 0 1 14-14h8a14 14 0 0 1 14 14v3m5-32a7 7 0 0 1 0 14m8 17v-4a11 11 0 0 0-8-10"/></g><rect x="15" y="84" width="62" height="7" rx="3.5" fill="#F1F9FF"/><rect x="15" y="97" width="42" height="6" rx="3" fill="#E7F7FF" fill-opacity=".8"/></g>
          <g transform="translate(221 45) rotate(10 57 57)"><rect x="8" y="8" width="118" height="128" rx="23" fill="#04118A" fill-opacity=".38"/><rect width="118" height="128" rx="23" fill="url(#joy-factor-glass)" stroke="#ECFAFF" stroke-width="1.5"/><rect x="16" y="16" width="86" height="38" rx="10" fill="#F3FBFF" fill-opacity=".88"/><path d="M29 29h31m-31 11h49" stroke="#6CA5E5" stroke-width="5" stroke-linecap="round"/><rect x="20" y="84" width="16" height="25" rx="5" fill="#0297DE"/><rect x="43" y="69" width="16" height="40" rx="5" fill="#0207CA"/><rect x="66" y="61" width="16" height="48" rx="5" fill="#0207CA"/><rect x="89" y="78" width="12" height="31" rx="5" fill="#FC734D"/></g>
          <g transform="translate(197 129)"><rect x="3" y="8" width="61" height="62" rx="17" fill="#1625A8" fill-opacity=".5"/><rect width="61" height="62" rx="17" fill="url(#joy-factor-warm)" stroke="#FFF0E7" stroke-width="1.5"/><path d="M17 28h27v20H17zm5 0v-7h17v7m-12 9h8" stroke="#8D4131" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></g>
          <path d="M62 60l5 12 12 5-12 5-5 12-5-12-12-5 12-5Z" fill="#FFCFB9"/><path d="M361 39l3.5 9 9.5 3.5-9.5 3.5-3.5 9-3.5-9-9.5-3.5 9.5-3.5Z" fill="#FFF"/><circle cx="373" cy="119" r="5" fill="#FC734D" stroke="#FFF" stroke-width="2"/><circle cx="90" cy="122" r="3.5" fill="#A5E6FF"/>
        </svg>
      </div>
      <div class="card-copy"><span class="card-overline">KAPASITAS INTERNAL USAHA</span><h3>Sumber Daya<span class="copy-dot">.</span></h3><p>Melihat kesiapan sumber daya manusia, modal, peralatan, dan kemampuan yang dimiliki usaha untuk menjalankan kegiatan bisnis serta mendukung pengembangannya.</p></div>
    </article>
    <article class="editorial-card factor-card compact leadership">
      <div class="card-grain" aria-hidden="true"></div><div class="card-top"><span class="card-index">02 <span class="index-separator">/</span> FAKTOR</span><span class="card-top-star" aria-hidden="true">✦</span></div>
      <div class="factor-art" aria-hidden="true"><svg focusable="false" viewBox="0 0 150 150" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="joy-factor-lead" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#FFF7EE"/><stop offset="1" stop-color="#F28C75"/></linearGradient></defs><circle cx="75" cy="76" r="62" fill="#FFF4EC" fill-opacity=".22" stroke="#FFF4EC" stroke-opacity=".7"/><circle cx="75" cy="76" r="48" fill="none" stroke="#FFF" stroke-opacity=".7" stroke-dasharray="4 5"/><circle cx="75" cy="76" r="38" fill="url(#joy-factor-lead)" stroke="#FFF0E5" stroke-width="2"/><path d="m75 47 12 21 23 5-19 15 2 24-18-13-18 13 2-24-19-15 23-5z" fill="#FC734D" stroke="#FFF2E8" stroke-width="2" stroke-linejoin="round"/><path d="m63 79 8 8 17-19" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="121" cy="33" r="5" fill="#fff"/><path d="M28 106v13m-6.5-6.5h13" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg></div>
      <div class="card-copy"><span class="card-overline">ARAH & KEPUTUSAN</span><h3>Kepemimpinan<span class="copy-dot">.</span></h3><p>Melihat kemampuan mengarahkan, mengambil keputusan, dan membimbing usaha.</p></div>
    </article>
    <article class="editorial-card factor-card compact workplace">
      <div class="card-grain" aria-hidden="true"></div><div class="card-top"><span class="card-index">03 <span class="index-separator">/</span> FAKTOR</span><span class="card-top-star" aria-hidden="true">✳</span></div>
      <div class="factor-art" aria-hidden="true"><svg focusable="false" viewBox="0 0 150 150" fill="none" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="joy-factor-work" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#F7FDFF"/><stop offset="1" stop-color="#79C7F7"/></linearGradient></defs><ellipse cx="75" cy="88" rx="65" ry="37" transform="rotate(-28 75 88)" stroke="#fff" stroke-width="2" stroke-opacity=".7"/><rect x="27" y="28" width="92" height="100" rx="22" fill="#1B78BF" fill-opacity=".2" transform="rotate(10 73 78)"/><rect x="20" y="23" width="92" height="100" rx="22" fill="url(#joy-factor-work)" stroke="#fff" stroke-width="1.5" transform="rotate(-8 66 73)"/><g stroke="#0758A7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M43 93V53l24-16 24 16v40H43zm-8 0h64M59 93V75h16v18M53 60h6m16 0h6"/></g><circle cx="115" cy="109" r="17" fill="#FC734D" stroke="#fff" stroke-width="2"/><path d="m108 109 5 5 9-10" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><path d="M123 23v14m-7-7h14" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg></div>
      <div class="card-copy"><span class="card-overline">KONDISI KERJA</span><h3>Lingkungan Kerja<span class="copy-dot">.</span></h3><p>Memahami suasana, kenyamanan, dan kondisi kerja sehari-hari.</p></div>
    </article>
    <article class="editorial-card factor-card bottom-card values">
      <div class="card-grain" aria-hidden="true"></div><div class="card-top"><span class="card-index">04 <span class="index-separator">/</span> FAKTOR</span><span class="card-top-star" aria-hidden="true">✦</span></div>
      <div class="factor-art" aria-hidden="true"><svg focusable="false" viewBox="0 0 180 140" fill="none" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="joy-factor-val" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#F6FCFF"/><stop offset="1" stop-color="#64BDE9"/></linearGradient></defs><ellipse cx="90" cy="71" rx="77" ry="47" stroke="#fff" stroke-width="2" transform="rotate(-22 90 71)"/><circle cx="90" cy="70" r="55" stroke="#fff" stroke-opacity=".8" stroke-dasharray="4 5"/><path d="M90 9 142 48 122 108 90 132 58 108 38 48Z" fill="#1776CC" fill-opacity=".22" transform="translate(5 4)"/><path d="M90 10 140 48 120 106 90 127 60 106 40 48Z" fill="url(#joy-factor-val)" stroke="#fff" stroke-width="1.8"/><path d="m90 10 0 117M40 48l100 0M60 106l30-58 30 58M40 48l50 79 50-79" stroke="#0297DE" stroke-opacity=".55" stroke-width="1.6"/><path d="m90 42 7 17 18 3-13 12 4 18-16-9-16 9 4-18-13-12 18-3Z" fill="#0207CA" stroke="#D8F5FF" stroke-width="1.5" stroke-linejoin="round"/><path d="M151 17v14m-7-7h14" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="28" cy="104" r="5" fill="#FC734D" stroke="#fff" stroke-width="2"/></svg></div>
      <div class="card-copy"><span class="card-overline">PRINSIP & BUDAYA</span><h3>Nilai Organisasi<span class="copy-dot">.</span></h3><p>Melihat prinsip, kebiasaan, dan nilai yang membentuk cara usaha dijalankan.</p></div>
    </article>
    <article class="editorial-card factor-card bottom-card stability">
      <div class="card-grain" aria-hidden="true"></div><div class="card-top"><span class="card-index">05 <span class="index-separator">/</span> FAKTOR</span><span class="card-top-star" aria-hidden="true">✳</span></div>
      <div class="factor-art" aria-hidden="true"><svg focusable="false" viewBox="0 0 180 140" fill="none" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="joy-factor-stable" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#D8F8FF"/><stop offset="1" stop-color="#58ACFA"/></linearGradient></defs><circle cx="90" cy="71" r="58" fill="#DDF6FF" fill-opacity=".1" stroke="#BDE9FF" stroke-opacity=".5"/><circle cx="90" cy="71" r="47" stroke="#fff" stroke-opacity=".5" stroke-dasharray="5 5"/><path d="M90 16c16 12 33 17 48 18v40c0 28-25 47-48 57-23-10-48-29-48-57V34c15-1 32-6 48-18Z" fill="#0015A8" fill-opacity=".5" transform="translate(5 2)"/><path d="M90 10c16 12 33 17 48 18v40c0 28-25 47-48 57-23-10-48-29-48-57V28c15-1 32-6 48-18Z" fill="url(#joy-factor-stable)" stroke="#E7F9FF" stroke-width="2"/><path d="M90 23c13 8 25 12 35 14v30c0 21-17 37-35 46-18-9-35-25-35-46V37c10-2 22-6 35-14Z" fill="#1649DB" stroke="#B3EBFF" stroke-width="1.5"/><path d="m72 69 13 13 24-27" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="143" cy="106" r="5" fill="#FC734D" stroke="#fff" stroke-width="2"/><path d="M29 22v16m-8-8h16" stroke="#D9F8FF" stroke-width="2" stroke-linecap="round"/></svg></div>
      <div class="card-copy"><span class="card-overline">KETAHANAN USAHA</span><h3>Stabilitas<span class="copy-dot">.</span></h3><p>Menilai konsistensi dan kemampuan usaha menjaga kelangsungan kegiatan bisnis.</p></div>
    </article>
    <article class="editorial-card factor-card bottom-card performance">
      <div class="card-grain" aria-hidden="true"></div><div class="card-top"><span class="card-index">06 <span class="index-separator">/</span> FAKTOR</span><span class="card-top-star" aria-hidden="true">✦</span></div>
      <div class="factor-art" aria-hidden="true"><svg focusable="false" viewBox="0 0 180 140" fill="none" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="joy-factor-perf" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#F2FCFF"/><stop offset="1" stop-color="#70B7F9"/></linearGradient></defs><ellipse cx="90" cy="75" rx="79" ry="46" stroke="#DDF7FF" stroke-opacity=".6" transform="rotate(-24 90 75)"/><rect x="40" y="20" width="110" height="106" rx="22" fill="#94D5FF" fill-opacity=".25" transform="rotate(10 95 73)"/><rect x="30" y="16" width="120" height="106" rx="22" fill="url(#joy-factor-perf)" stroke="#F5FCFF" stroke-width="1.8" transform="rotate(-7 90 69)"/><g transform="rotate(-7 90 69)"><path d="M48 103h85" stroke="#7EA7DE" stroke-width="2" stroke-linecap="round"/><rect x="50" y="77" width="16" height="26" rx="4" fill="#0297DE"/><rect x="73" y="65" width="16" height="38" rx="4" fill="#2257E5"/><rect x="96" y="49" width="16" height="54" rx="4" fill="#0207CA"/><rect x="119" y="38" width="16" height="65" rx="4" fill="#FC734D"/><path d="m49 62 22-15 20 2 29-23m-13 0h13v13" stroke="#0207CA" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></g><path d="M153 14v16m-8-8h16" stroke="#FFCCB9" stroke-width="2.5" stroke-linecap="round"/><circle cx="22" cy="111" r="4" fill="#DDF8FF"/></svg></div>
      <div class="card-copy"><span class="card-overline">HASIL & PENCAPAIAN</span><h3>Kinerja<span class="copy-dot">.</span></h3><p>Memahami hasil kegiatan usaha dan pencapaian terhadap tujuan yang ditetapkan.</p></div>
    </article>
  </div>
  <div class="editorial-endnote"><span class="small-spark" aria-hidden="true">✳</span> Enam faktor membantu pendamping memahami kondisi usaha dan menentukan fokus evaluasi berikutnya.</div>
</div>
</section>
    <section class="cta-section"><div class="container"><div class="cta-banner"><div><h2>Mulai pahami usaha yang Anda dampingi hari ini.</h2><p>Buat akun JoyUMKM, catat UMKM, dan mulai evaluasi secara terstruktur.</p></div><a href="{{ route('register') }}" class="btn btn-white">Daftar Sekarang <svg aria-hidden="true"><use href="#joy-i-arrow"/></svg></a></div></div></section>
    <footer class="footer"><div class="container footer-inner"><span>© 2026 JoyUMKM · UMKM Health Dashboard</span><div class="footer-links"><span>Indikator evaluasi berbasis jawaban kuesioner, bukan diagnosis finansial tervalidasi.</span></div></div></footer>
  </main>
@endsection
