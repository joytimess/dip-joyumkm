@extends('layouts.public', ['page' => 'welcome', 'title' => 'Kenali Kondisi Usaha'])
@section('content')
<main id="main-content" tabindex="-1">
    <header class="header"><div class="container header-inner">
      <x-public-brand />
      <nav class="header-links" aria-label="Navigasi utama"><a href="#cara-kerja">Cara Kerja</a><a href="#dimensi">Tiga Dimensi</a></nav>
      <div class="header-actions"><a class="login-link" href="{{ route('login') }}" style="color:var(--primary);font-size:13px;font-weight:750;padding:11px">Masuk</a><a class="btn btn-primary" href="{{ route('register') }}">Daftar Akun <svg aria-hidden="true"><use href="#joy-i-arrow"/></svg></a></div>
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
    <section class="cta-section"><div class="container"><div class="cta-banner"><div><h2>Mulai pahami usaha yang Anda dampingi hari ini.</h2><p>Buat akun JoyUMKM, catat UMKM, dan mulai evaluasi secara terstruktur.</p></div><a href="{{ route('register') }}" class="btn btn-white">Daftar Sekarang <svg aria-hidden="true"><use href="#joy-i-arrow"/></svg></a></div></div></section>
    <footer class="footer"><div class="container footer-inner"><span>© 2026 JoyUMKM · UMKM Health Dashboard</span><div class="footer-links"><span>Indikator evaluasi berbasis jawaban kuesioner, bukan diagnosis finansial tervalidasi.</span></div></div></footer>
  </main>
@endsection
