@extends('layouts.app', ['page' => 'admin-analysts', 'title' => 'Manajemen Analyst'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <h1>Manajemen Analyst</h1>
            <p class="muted">Kelola akun Analyst. Penonaktifan mencabut akses masuk, sedangkan UMKM dan riwayatnya tetap tersimpan.</p>
        </div>
        <button type="button" id="analyst-add" class="btn button button-primary" hidden>Tambah Analyst</button>
    </div>
    <section class="card card-pad panel" aria-labelledby="analyst-list-title">
        <h2 id="analyst-list-title" class="sr-only">Daftar Analyst</h2>
        <div class="home-six-tools" role="search">
            <input id="analyst-search" class="home-six-input" type="search" maxlength="120" placeholder="Cari nama atau email Analyst…" aria-label="Cari Analyst">
            <div class="field form-field">
                <label for="analyst-status" class="sr-only">Filter status</label>
                <select id="analyst-status" class="control">
                    <option value="">Semua status</option>
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                </select>
            </div>
        </div>
        <div id="analyst-state" aria-live="polite" aria-busy="true"></div>
        <div id="analyst-pager" class="home-six-pager" hidden>
            <span id="analyst-count" class="muted"></span>
            <div>
                <button id="analyst-prev" type="button" class="button button-outline">← Sebelumnya</button>
                <button id="analyst-next" type="button" class="button button-outline">Berikutnya →</button>
            </div>
        </div>
    </section>
</div>
@endsection
