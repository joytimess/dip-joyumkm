@extends('layouts.app', ['page' => 'umkms', 'title' => 'UMKM & Recommendations'])
@section('content')
@include('components.banner')
<div class="page-heading"><div><h1>Detail Data UMKM</h1><p id="umkm-range" class="muted" aria-live="polite">Memuat data UMKM…</p></div><div class="heading-actions"><button type="button" id="filter-toggle" class="button button-outline" aria-expanded="false" aria-controls="umkm-filters"><x-icon name="filter"/> Filter</button><button type="button" id="add-umkm" class="button button-primary" disabled><x-icon name="plus"/> Tambah Data</button></div></div>
<form id="umkm-filters" class="panel filter-panel" hidden>
    <div class="filter-fields"><div class="form-field"><label for="filter-sector">Sektor</label><select id="filter-sector" name="sector_id"><option value="">Semua sektor</option></select></div><div class="form-field"><label for="filter-province">Provinsi</label><select id="filter-province" name="province"><option value="">Semua provinsi</option></select></div><div class="form-field"><label for="filter-category">Rekomendasi</label><select id="filter-category" name="category"><option value="">Semua rekomendasi</option></select></div><div class="form-field"><label for="filter-status">Status evaluasi</label><select id="filter-status" name="status"><option value="">Semua status</option><option value="none">Belum dievaluasi</option><option value="draft">Draft / Sementara</option><option value="final">Final</option></select></div></div>
    <div class="filter-actions"><button type="button" id="reset-filters" class="button button-ghost">Reset Filter</button><button type="submit" class="button button-primary">Terapkan Filter</button></div>
</form>
<div id="options-state"></div>
<section class="panel data-panel" aria-label="Daftar UMKM">
    <div id="umkm-list-state" aria-live="polite" aria-busy="true"><div class="skeleton h-10"></div><div class="skeleton mt-4 h-40"></div></div>
    <div id="umkm-table-area" hidden>
        <div class="table-scroll" role="region" aria-label="Tabel UMKM, geser untuk melihat seluruh kolom" tabindex="0"><table class="data-table"><thead><tr><th scope="col" class="num">NO</th><th scope="col" data-sort="name"><button type="button">NAMA UMKM <span data-sort-direction></span></button></th><th scope="col">LOKASI</th><th scope="col">SEKTOR</th><th scope="col" class="num" data-sort="omzet"><button type="button">OMZET (RP) <span data-sort-direction></span></button></th><th scope="col" class="num" data-sort="score"><button type="button">SKOR KESEHATAN <span data-sort-direction></span></button></th><th scope="col">FAKTOR UTAMA</th><th scope="col">REKOMENDASI</th><th scope="col">AKSI</th></tr></thead><tbody id="umkm-rows"></tbody></table></div>
        <div class="table-footer"><p id="umkm-page-info" class="muted"></p><div class="pagination-controls"><label for="umkm-per-page" class="sr-only">Data per halaman</label><select id="umkm-per-page"><option value="10">10 per halaman</option><option value="25">25 per halaman</option><option value="50">50 per halaman</option><option value="100">100 per halaman</option></select><nav id="umkm-pagination" aria-label="Halaman data UMKM"></nav></div></div>
    </div>
</section>
@include('components.summary')
<dialog id="umkm-modal" class="modal" aria-labelledby="umkm-modal-title">
    <div class="modal-heading"><h2 id="umkm-modal-title">Tambah Data UMKM</h2><button type="button" class="icon-button" data-close-dialog aria-label="Tutup formulir"><x-icon name="close"/></button></div>
    <form id="umkm-form" method="post" novalidate>
        <div class="form-message" role="alert" tabindex="-1" hidden></div>
        <div class="form-field"><label for="umkm-name">Nama UMKM</label><input id="umkm-name" name="name" maxlength="120" required autofocus><p class="field-error" data-error="name" id="umkm-error-name"></p></div>
        <div class="form-field"><label for="umkm-province">Provinsi</label><select id="umkm-province" name="province" required><option value="">Pilih provinsi</option></select><p class="field-error" data-error="province" id="umkm-error-province"></p></div>
        <div class="form-field"><label for="umkm-sector">Sektor</label><select id="umkm-sector" name="sector_id" required><option value="">Pilih sektor</option></select><p class="field-error" data-error="sector_id" id="umkm-error-sector_id"></p></div>
        <div class="form-field"><label for="umkm-omzet">Omzet (Rp)</label><input id="umkm-omzet" name="omzet" type="text" inputmode="numeric" required placeholder="Contoh: 15.000.000" aria-describedby="umkm-omzet-help"><small id="umkm-omzet-help" class="muted">Nilai rupiah, tanpa periode bulanan atau tahunan.</small><p class="field-error" data-error="omzet" id="umkm-error-omzet"></p></div>
        <div class="modal-actions"><button type="button" class="button button-outline" data-close-dialog>Batal</button><button type="submit" id="save-umkm" class="button button-primary">Simpan Data</button></div>
    </form>
</dialog>
<dialog id="delete-umkm-modal" class="modal" aria-labelledby="delete-umkm-title"><div class="modal-heading"><h2 id="delete-umkm-title">Hapus UMKM?</h2><button type="button" class="icon-button" data-close-dialog aria-label="Tutup konfirmasi"><x-icon name="close"/></button></div><p>UMKM <strong id="delete-umkm-name"></strong> beserta seluruh evaluasi dan jawabannya akan dihapus.</p><p class="muted mt-3">Tindakan ini tidak dapat dibatalkan.</p><div id="delete-umkm-error" class="form-message mt-4" role="alert" hidden></div><div class="modal-actions"><button type="button" class="button button-outline" data-close-dialog>Batal</button><button type="button" id="confirm-delete-umkm" class="button button-danger">Hapus UMKM</button></div></dialog>
@endsection
