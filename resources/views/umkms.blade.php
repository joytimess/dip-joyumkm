@extends('layouts.app', ['page' => 'umkms', 'title' => 'UMKM & Recommendations'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Manajemen data</div>
            <h1>UMKM & Recommendations</h1>
            <p class="muted">Kelola usaha, pahami kondisi, dan tentukan tindak lanjut.</p>
        </div>
        <div class="heading-actions">
            <button type="button" id="filter-toggle" class="btn btn-light button button-outline" aria-expanded="true" aria-controls="umkm-filters"><x-icon name="filter"/> Filter</button>
            <button type="button" id="add-umkm" class="btn btn-primary button button-primary" disabled><x-icon name="plus"/> Tambah Data</button>
        </div>
    </div>

    <form id="umkm-filters" class="card filter-panel panel" style="margin-bottom:20px;">
        <div class="filter-row filter-fields">
            <div class="field form-field">
                <label for="filter-sector">Sektor</label>
                <select id="filter-sector" name="sector_id" class="control"><option value="">Semua sektor</option></select>
            </div>
            <div class="field form-field">
                <label for="filter-province">Provinsi</label>
                <select id="filter-province" name="province" class="control"><option value="">Semua provinsi</option></select>
            </div>
            <div class="field form-field">
                <label for="filter-category">Rekomendasi</label>
                <select id="filter-category" name="category" class="control"><option value="">Semua rekomendasi</option></select>
            </div>
            <div class="field form-field">
                <label for="filter-status">Status evaluasi</label>
                <select id="filter-status" name="status" class="control">
                    <option value="">Semua status</option>
                    <option value="none">Belum dievaluasi</option>
                    <option value="draft">Draft / Sementara</option>
                    <option value="final">Final</option>
                </select>
            </div>
        </div>
        <div class="filter-actions" style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
            <button type="button" id="reset-filters" class="btn btn-light button button-ghost">Reset Filter</button>
            <button type="submit" class="btn btn-primary button button-primary">Terapkan Filter</button>
        </div>
    </form>

    <div id="options-state"></div>

    <section class="card card-pad panel data-panel" aria-label="Daftar UMKM" style="margin-bottom:24px;">
        <div class="section-top section-heading table-heading">
            <div>
                <h2 class="section-title">Portofolio UMKM</h2>
                <p id="umkm-range" class="section-desc muted" aria-live="polite">Memuat data UMKM…</p>
            </div>
            <span class="table-hint tiny muted">Geser tabel untuk melihat seluruh data dan aksi →</span>
        </div>

        <div id="umkm-list-state" aria-live="polite" aria-busy="true"><div class="skeleton h-10"></div><div class="skeleton mt-4 h-40"></div></div>

        <div id="umkm-table-area" hidden>
            <div class="data-table-wrap table-scroll" role="region" aria-label="Tabel UMKM, geser untuk melihat seluruh kolom" tabindex="0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col" class="num">NO</th>
                            <th scope="col" data-sort="name"><button type="button" class="sort-button">NAMA UMKM <span data-sort-direction></span></button></th>
                            <th scope="col">LOKASI</th>
                            <th scope="col">SEKTOR</th>
                            <th scope="col" class="num" data-sort="omzet"><button type="button" class="sort-button">OMZET (RP) <span data-sort-direction></span></button></th>
                            <th scope="col" class="num" data-sort="score"><button type="button" class="sort-button">SKOR KESEHATAN <span data-sort-direction></span></button></th>
                            <th scope="col">FAKTOR UTAMA</th>
                            <th scope="col">REKOMENDASI</th>
                            <th scope="col">AKSI</th>
                        </tr>
                    </thead>
                    <tbody id="umkm-rows"></tbody>
                </table>
            </div>

            <div class="table-footer">
                <p id="umkm-page-info" class="muted small"></p>
                <div class="pagination-controls hstack">
                    <label for="umkm-per-page" class="sr-only">Data per halaman</label>
                    <select id="umkm-per-page" class="control" style="width:auto;min-height:36px;padding:6px 12px;font-size:11px;">
                        <option value="10">10 per halaman</option>
                        <option value="25">25 per halaman</option>
                        <option value="50">50 per halaman</option>
                        <option value="100">100 per halaman</option>
                    </select>
                    <nav id="umkm-pagination" class="pagination" aria-label="Halaman data UMKM"></nav>
                </div>
            </div>
        </div>
    </section>

    <div class="section-block">
        <div class="section-top summary-heading">
            <div>
                <h2 class="section-title">Ringkasan portofolio</h2>
                <p class="section-desc muted">Gambaran kondisi UMKM berdasarkan penilaian terbaru.</p>
            </div>
        </div>
        @include('components.summary')
    </div>
</div>

<dialog id="umkm-modal" class="modal dialog" aria-labelledby="umkm-modal-title">
    <div class="dialog-head modal-heading">
        <h2 id="umkm-modal-title" class="section-title">Tambah Data UMKM</h2>
        <button type="button" class="icon-button" data-close-dialog aria-label="Tutup formulir"><x-icon name="close"/></button>
    </div>
    <form id="umkm-form" method="post" novalidate class="dialog-body">
        <div class="form-message" role="alert" tabindex="-1" hidden></div>
        <div class="modal-grid">
            <div class="field form-field full">
                <label for="umkm-name">Nama UMKM</label>
                <input id="umkm-name" name="name" class="control" maxlength="120" required autofocus>
                <p class="field-error" data-error="name" id="umkm-error-name"></p>
            </div>
            <div class="field form-field">
                <label for="umkm-province">Provinsi</label>
                <select id="umkm-province" name="province" class="control" required><option value="">Pilih provinsi</option></select>
                <p class="field-error" data-error="province" id="umkm-error-province"></p>
            </div>
            <div class="field form-field">
                <label for="umkm-sector">Sektor</label>
                <select id="umkm-sector" name="sector_id" class="control" required><option value="">Pilih sektor</option></select>
                <p class="field-error" data-error="sector_id" id="umkm-error-sector_id"></p>
            </div>
            <div class="field form-field full">
                <label for="umkm-omzet">Omzet (Rp)</label>
                <input id="umkm-omzet" name="omzet" class="control" type="text" inputmode="numeric" required placeholder="Contoh: 15.000.000" aria-describedby="umkm-omzet-help">
                <small id="umkm-omzet-help" class="muted tiny">Nilai rupiah, tanpa periode bulanan atau tahunan.</small>
                <p class="field-error" data-error="omzet" id="umkm-error-omzet"></p>
            </div>
        </div>
        <div class="dialog-footer modal-actions">
            <button type="button" class="btn btn-light button button-outline" data-close-dialog>Batal</button>
            <button type="submit" id="save-umkm" class="btn btn-primary button button-primary">Simpan Data</button>
        </div>
    </form>
</dialog>

<dialog id="delete-umkm-modal" class="modal dialog" aria-labelledby="delete-umkm-title">
    <div class="dialog-head modal-heading">
        <h2 id="delete-umkm-title" class="section-title">Hapus UMKM?</h2>
        <button type="button" class="icon-button" data-close-dialog aria-label="Tutup konfirmasi"><x-icon name="close"/></button>
    </div>
    <div class="dialog-body">
        <p>UMKM <strong id="delete-umkm-name"></strong> beserta seluruh evaluasi dan jawabannya akan dihapus.</p>
        <p class="muted tiny" style="margin-top:8px;">Tindakan ini tidak dapat dibatalkan.</p>
        <div id="delete-umkm-error" class="form-message mt-4" role="alert" hidden></div>
    </div>
    <div class="dialog-footer modal-actions">
        <button type="button" class="btn btn-light button button-outline" data-close-dialog>Batal</button>
        <button type="button" id="confirm-delete-umkm" class="btn btn-orange button button-danger">Hapus UMKM</button>
    </div>
</dialog>
@endsection
