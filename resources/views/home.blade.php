@extends('layouts.app', ['page' => 'home', 'title' => 'Home'])
@section('content')
<div class="page">
    <div class="page-heading home-welcome">
        <div>
            <h1 id="welcome-title">Selamat datang, <span data-user-name></span>!</h1>
            <p class="muted">Pantau kondisi usaha dan tentukan fokus pendampingan.</p>
        </div>
        <a href="{{ route('umkms') }}" class="btn button button-primary">Kelola UMKM <x-icon name="arrow"/></a>
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
                    <p class="section-desc muted">Kategori seluruh UMKM, termasuk yang belum dinilai.</p>
                </div>
                <a href="{{ route('distribution') }}" class="link text-link">Lihat distribusi <x-icon name="arrow"/></a>
            </div>
            <div id="home-health" aria-live="polite" aria-busy="true"><div class="grid gap-3" aria-hidden="true"><div class="skeleton h-56"></div><div class="skeleton h-3 w-1/3"></div><div class="skeleton h-3 w-1/4"></div></div></div>
        </section>

        <section class="card card-pad panel" aria-labelledby="next-steps-title">
            <div class="section-top section-heading">
                <div>
                    <h2 id="next-steps-title" class="section-title">Prioritas pendampingan</h2>
                    <p class="section-desc muted">Langkah praktis berdasarkan data terbaru.</p>
                </div>
            </div>
            <div class="action-row">
                <div class="hstack">
                    <span class="action-icon"><x-icon name="form"/></span>
                    <div>
                        <h3>Selesaikan draft evaluasi</h3>
                        <p id="home-draft-caption">Evaluasi yang berstatus sementara</p>
                    </div>
                </div>
                <a href="{{ route('umkms', ['status' => 'draft']) }}" class="btn btn-light btn-sm button button-outline" aria-label="Buka daftar UMKM dengan Draft">Buka</a>
            </div>
            <div class="action-row">
                <div class="hstack">
                    <span class="action-icon"><x-icon name="trend"/></span>
                    <div>
                        <h3>Bandingkan faktor kinerja</h3>
                        <p>Tinjau skor tiap dimensi dan rerata portofolio.</p>
                    </div>
                </div>
                <a href="{{ route('comparison') }}" class="btn btn-light btn-sm button button-outline" aria-label="Lihat perbandingan faktor">Lihat</a>
            </div>
            <div class="action-row">
                <div class="hstack">
                    <span class="action-icon"><x-icon name="business"/></span>
                    <div>
                        <h3>Tinjau semua data UMKM</h3>
                        <p>Kelola profil usaha dan rekomendasi secara terpusat</p>
                    </div>
                </div>
                <a href="{{ route('umkms') }}" class="btn btn-light btn-sm button button-outline" aria-label="Kelola semua data UMKM">Kelola</a>
            </div>
        </section>
    </div>

    <section class="card home-six section-block" id="six-factor-section" aria-labelledby="home-six-title">
        <div class="home-six-head">
            <div><h2 id="home-six-title">Penilaian Enam Faktor</h2><p>Ringkasan penilaian per UMKM dari Sumber Daya, Kepemimpinan, Lingkungan Kerja, Nilai Organisasi, Stabilitas, dan Kinerja.</p><span class="home-six-note">Skala 1–5 per faktor · Total Final 6–30 · Terpisah dari skor 0–100</span></div>
            <a id="home-six-open" class="btn button button-outline" href="{{ route('umkms', ['mode' => 'six-factor']) }}">Buka halaman penilaian ↗</a>
        </div>
        <div class="home-six-controls">
            <div class="home-six-label">UMKM yang ingin ditinjau</div>
            <div id="home-six-picker"></div>
            <div class="home-six-tabs" role="group" aria-label="Jenis hasil enam faktor">
                <button type="button" data-six-tab="final" aria-pressed="true">Final terbaru</button>
                <button type="button" data-six-tab="draft" aria-pressed="false">Draft aktif</button>
                <button type="button" data-six-tab="history" aria-pressed="false">Histori Final</button>
            </div>
        </div>
        <div class="home-six-body" id="home-six-body" aria-live="polite"><p class="muted">Memuat pilihan UMKM…</p></div>
        <div class="home-six-bottom"><p id="home-six-note">Hasil Final terpisah dari skor kesehatan tiga dimensi.</p><button class="text-link" type="button" id="home-six-history">Lihat histori Final →</button></div>
    </section>

    <section class="card card-pad panel priority-panel section-block" aria-labelledby="snapshot-title">
        <div class="section-top section-heading">
            <div>
                <h2 id="snapshot-title" class="section-title">Tinjauan usaha</h2>
                <p class="section-desc muted">Skor kesehatan terendah ditampilkan lebih dulu.</p>
            </div>
            <a href="{{ route('umkms') }}" class="link text-link">Semua UMKM <x-icon name="arrow"/></a>
        </div>
        <div id="home-priorities" aria-live="polite" aria-busy="true">
            <div class="grid gap-3" aria-hidden="true">
                @foreach (range(1, 4) as $row)
                    <div class="grid grid-cols-4 gap-4"><div class="skeleton h-6"></div><div class="skeleton h-6 w-3/4"></div><div class="skeleton h-6 w-1/2"></div><div class="skeleton h-6 w-2/3"></div></div>
                @endforeach
            </div>
        </div>
    </section>
</div>
@endsection
