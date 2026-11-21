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

    <section class="card home-three section-block" id="three-dimension-section" aria-labelledby="home-three-title">
        <div class="home-three-head">
            <div>
                <h2 id="home-three-title">Kesehatan UMKM — Tiga Dimensi</h2>
                <p>Institutional Resources · Economic Connectivity · Operational Stability</p>
            </div>
        </div>

        <div id="three-kpis" class="home-three-kpis" aria-live="polite" aria-busy="true"></div>

        <div class="home-three-columns">
            <div class="home-three-left">
                <section id="three-profile" class="home-three-card" aria-live="polite" aria-busy="true"></section>
                <section id="three-selected" class="home-three-card" aria-live="polite" aria-busy="true"></section>
            </div>

            <section class="home-three-card home-three-list" aria-labelledby="three-list-title">
                <div class="home-three-list-head">
                    <div>
                        <h3 id="three-list-title">Daftar Portofolio UMKM</h3>
                        <p class="home-three-sub">Memantau evaluasi kesehatan berkala untuk seluruh UMKM binaan</p>
                    </div>
                    <span id="three-total" class="home-three-badge home-three-badge-blue" hidden></span>
                </div>
                <div class="home-three-tools">
                    <input id="three-search" class="home-three-input" type="search" maxlength="120" placeholder="Cari nama UMKM..." aria-label="Cari nama UMKM">
                    <div class="field form-field">
                        <label for="three-status-filter" class="sr-only">Filter status</label>
                        <select id="three-status-filter" class="control"></select>
                    </div>
                </div>
                <div id="three-list" aria-live="polite" aria-busy="true"></div>
                <div id="three-pager" class="home-three-pager" hidden></div>
            </section>
        </div>
    </section>

    <section class="card home-six section-block" id="six-factor-section" aria-labelledby="home-six-title">
        <div class="home-six-head">
            <div>
                <h2 id="home-six-title">Penilaian Enam Faktor</h2>
                <p>Ringkasan hasil Final terbaru dari seluruh UMKM. Setiap UMKM ditampilkan dalam satu kartu.</p>
            </div>
            <a id="home-six-open" class="btn button button-outline" href="{{ route('umkms', ['mode' => 'six-factor']) }}">Buka halaman penilaian ↗</a>
        </div>
        <div id="six-kpis" class="home-six-kpis" aria-live="polite"></div>
        <div class="home-six-tools" role="search">
            <input id="six-search" class="home-six-input" type="search" maxlength="120" placeholder="Cari nama UMKM atau sektor…" aria-label="Cari UMKM">
            <div class="field form-field">
                <label for="six-status" class="sr-only">Filter status</label>
                <select id="six-status" class="control">
                    <option value="all">Semua status</option>
                    <option value="final">Sudah Final</option>
                    <option value="none">Belum ada Final</option>
                </select>
            </div>
            <div class="field form-field">
                <label for="six-sort" class="sr-only">Urutkan</label>
                <select id="six-sort" class="control">
                    <option value="name_asc">Nama A–Z</option>
                    <option value="score_desc">Skor tertinggi</option>
                    <option value="score_asc">Skor terendah</option>
                </select>
            </div>
        </div>
        <div id="six-cards" class="home-six-cards" aria-live="polite"></div>
        <div id="six-pager" class="home-six-pager" hidden>
            <span id="six-count" class="muted"></span>
            <div>
                <button id="six-prev" type="button" class="button button-outline">← Sebelumnya</button>
                <button id="six-next" type="button" class="button button-outline">Berikutnya →</button>
            </div>
        </div>
        <p class="home-six-bottom-note">Hanya hasil Final resmi. Nilai Tiga Dimensi tidak digabung dengan Enam Faktor.</p>
    </section>

    <dialog id="six-factor-detail" class="modal dialog six-factor-detail-dialog" aria-labelledby="six-factor-detail-title" aria-describedby="six-factor-detail-place">
        <div class="dialog-head">
            <div class="min-w-0">
                <h2 id="six-factor-detail-title" class="section-title break-words"></h2>
                <p id="six-factor-detail-place" class="muted small"></p>
            </div>
            <button type="button" class="icon-button" data-close-dialog aria-label="Tutup detail Final"><x-icon name="close"/></button>
        </div>
        <div id="six-factor-detail-content" class="dialog-body"></div>
        <div class="dialog-footer wrap">
            <a id="six-factor-detail-link" class="btn button button-outline" href="/umkms">Buka halaman penilaian ↗</a>
        </div>
    </dialog>

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
