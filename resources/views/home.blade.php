@extends('layouts.app', ['page' => 'home', 'title' => 'Home'])
@section('content')
<div class="page">
    <div class="hero welcome-banner">
        <div>
            <span class="hello"><span class="dot"></span> Dashboard kesehatan usaha</span>
            <h2 id="welcome-title">Selamat datang, <span data-user-name></span>! <span aria-hidden="true">✦</span></h2>
            <p>Pantau seluruh portofolio UMKM, kenali performa kesehatan usaha, dan tentukan langkah pendampingan berikutnya.</p>
        </div>
        <a href="{{ route('umkms') }}" class="hero-btn btn">Kelola UMKM <x-icon name="arrow"/></a>
    </div>

    <div class="section-block">
        <div class="section-top summary-heading">
            <div>
                <h2 class="section-title">Ringkasan portofolio</h2>
                <p class="section-desc muted">Gambaran kondisi UMKM berdasarkan penilaian terbaru.</p>
            </div>
            <span id="summary-state" class="muted small" role="status">Memuat ringkasan…</span>
        </div>
        @include('components.summary')
    </div>

    <div class="section-block two-col-6-4 home-grid">
        <section class="card card-pad panel chart-panel" aria-labelledby="health-dist-title">
            <div class="section-top section-heading">
                <div>
                    <h2 id="health-dist-title" class="section-title">Distribusi kesehatan</h2>
                    <p class="section-desc muted">Komposisi kategori seluruh responden.</p>
                </div>
                <a href="{{ route('distribution') }}" class="link text-link">Lihat distribusi <x-icon name="arrow"/></a>
            </div>
            <div id="home-health" aria-live="polite" aria-busy="true"><div class="skeleton h-64"></div></div>
        </section>

        <section class="card card-pad panel" aria-labelledby="next-steps-title">
            <div class="section-top section-heading">
                <div>
                    <h2 id="next-steps-title" class="section-title">Prioritas pendampingan</h2>
                    <p class="section-desc muted">Langkah praktis berdasarkan data terbaru.</p>
                </div>
            </div>
            <div id="home-work" aria-live="polite"></div>
            <div class="action-row">
                <div class="hstack">
                    <span class="action-icon" style="background:#FFF1EA;color:var(--brand-accent)"><x-icon name="file"/></span>
                    <div>
                        <h4>Selesaikan draft evaluasi</h4>
                        <p id="home-draft-caption">Evaluasi yang berstatus sementara</p>
                    </div>
                </div>
                <a href="{{ route('umkms', ['status' => 'draft']) }}" class="btn btn-light btn-sm button button-outline">Buka</a>
            </div>
            <div class="action-row">
                <div class="hstack">
                    <span class="action-icon" style="background:#EAF7FF;color:var(--brand-secondary)"><x-icon name="trend"/></span>
                    <div>
                        <h4>Bandingkan faktor kinerja</h4>
                        <p>Identifikasi keunggulan dan gap INS · ECT · OPS</p>
                    </div>
                </div>
                <a href="{{ route('comparison') }}" class="btn btn-light btn-sm button button-outline">Lihat</a>
            </div>
            <div class="action-row">
                <div class="hstack">
                    <span class="action-icon" style="background:#EEF1FF;color:var(--brand-primary)"><x-icon name="business"/></span>
                    <div>
                        <h4>Tinjau semua data UMKM</h4>
                        <p>Kelola profil usaha dan rekomendasi secara terpusat</p>
                    </div>
                </div>
                <a href="{{ route('umkms') }}" class="btn btn-light btn-sm button button-outline">Kelola</a>
            </div>
        </section>
    </div>

    <section class="card card-pad panel priority-panel section-block" aria-labelledby="snapshot-title">
        <div class="section-top section-heading">
            <div>
                <h2 id="snapshot-title" class="section-title">Snapshot UMKM</h2>
                <p class="section-desc muted">Urutan performa berdasarkan skor kesehatan usaha.</p>
            </div>
            <a href="{{ route('umkms') }}" class="link text-link">Semua UMKM <x-icon name="arrow"/></a>
        </div>
        <div id="home-priorities" aria-live="polite" aria-busy="true"><div class="skeleton h-32"></div></div>
    </section>
</div>
@endsection
