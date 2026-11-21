@extends('layouts.app', [
    'page' => 'umkms',
    'navigationPage' => request()->query('mode') === 'six-factor' ? 'six-factor-evaluation' : 'umkms',
    'title' => request()->query('mode') === 'six-factor' ? 'Pilih UMKM untuk Penilaian Enam Faktor' : 'Data UMKM',
])
@section('content')
<div class="page {{ request()->query('mode') === 'six-factor' ? 'six-factor-picker-page' : '' }}">
    <div class="page-heading">
        <div>
            @if (request()->query('mode') === 'six-factor')
                <h1>Penilaian Enam Faktor</h1>
                <p class="muted">Pilih UMKM untuk mulai menilai, melanjutkan Draft, atau membuka hasil Final sebelumnya.</p>
            @else
                <h1>Data UMKM</h1>
                <p class="muted">Kelola data usaha dan lihat hasil penilaian terbaru.</p>
            @endif
        </div>
        <div class="heading-actions">
            <button type="button" id="add-umkm" class="btn btn-primary button button-primary" disabled><x-icon name="plus"/> Tambah Data</button>
        </div>
    </div>

    <div class="section-block">
        <div class="section-top summary-heading">
            <div>
                <h2 class="section-title">Ringkasan portofolio</h2>
                <p class="section-desc muted">Gambaran kondisi UMKM berdasarkan penilaian terbaru.</p>
            </div>
        </div>
        @include('components.summary')
    </div>

    <section class="section-block" aria-labelledby="filter-title">
        <form id="umkm-filters" class="card filter-panel panel">
            <div class="section-top section-heading">
                <h2 id="filter-title" class="section-title">Filter UMKM</h2>
            </div>
            <div id="options-state" role="status" aria-label="Memuat filter UMKM" aria-busy="true">
                <div aria-hidden="true">
                    <div class="filter-row filter-fields">
                        @foreach (range(1, 4) as $field)
                            <div class="field form-field"><div class="skeleton h-3 w-1/3 mb-3"></div><div class="skeleton h-11"></div></div>
                        @endforeach
                    </div>
                    <div class="flex justify-end gap-3 mt-4"><div class="skeleton h-11 w-24"></div><div class="skeleton h-11 w-32"></div></div>
                </div>
            </div>
            <fieldset class="min-w-0" disabled hidden>
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
                    @if (request()->query('mode') === 'six-factor')
                        <div class="field form-field six-factor-sort-field">
                            <label for="umkm-sort">Urutkan</label>
                            <select id="umkm-sort" class="control">
                                <option value="score:desc">Skor tertinggi</option>
                                <option value="score:asc">Skor terendah</option>
                                <option value="name:asc">Nama A–Z</option>
                                <option value="name:desc">Nama Z–A</option>
                                <option value="omzet:desc">Omzet tertinggi</option>
                                <option value="omzet:asc">Omzet terendah</option>
                            </select>
                        </div>
                    @endif
                </div>
                <div class="filter-actions">
                    <button type="button" id="reset-filters" class="btn btn-light button button-ghost">Reset Filter</button>
                    <button type="submit" class="btn btn-primary button button-primary">Terapkan Filter</button>
                </div>
            </fieldset>
        </form>
    </section>

    <section class="card card-pad panel data-panel" aria-label="Daftar UMKM" style="margin-bottom:24px;">
        <div class="section-top section-heading table-heading">
            <div>
                <h2 class="section-title">{{ request()->query('mode') === 'six-factor' ? 'Pilih usaha untuk dinilai' : 'Portofolio UMKM' }}</h2>
                <p id="umkm-range" class="section-desc muted" aria-live="polite">Memuat data UMKM…</p>
            </div>
        </div>

        @if (request()->query('mode') !== 'six-factor')
        <div class="umkm-card-controls">
            <label for="umkm-sort">Urutkan</label>
            <select id="umkm-sort" class="control">
                <option value="score:desc">Skor tertinggi</option>
                <option value="score:asc">Skor terendah</option>
                <option value="name:asc">Nama A–Z</option>
                <option value="name:desc">Nama Z–A</option>
                <option value="omzet:desc">Omzet tertinggi</option>
                <option value="omzet:asc">Omzet terendah</option>
            </select>
        </div>
        @endif
        <div id="umkm-list-state" aria-live="polite" aria-busy="true">
            <div aria-hidden="true">
                <div class="umkm-table-skeleton grid gap-4">
                    @foreach ([true, false, false, false] as $header)
                        <div class="grid grid-cols-4 gap-4">
                            <div class="skeleton {{ $header ? 'h-3' : 'h-6' }} w-3/4"></div>
                            <div class="skeleton {{ $header ? 'h-3' : 'h-6' }}"></div>
                            <div class="skeleton {{ $header ? 'h-3' : 'h-6' }} w-1/2"></div>
                            <div class="skeleton {{ $header ? 'h-3' : 'h-6' }} w-2/3"></div>
                        </div>
                    @endforeach
                </div>
                <div class="umkm-card-skeleton">
                    @foreach (range(1, 3) as $card)
                        <div class="umkm-card grid gap-3">
                            <div class="skeleton h-5 w-2/3"></div>
                            <div class="skeleton h-4 w-1/2"></div>
                            <div class="skeleton h-6 w-3/4"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <template id="umkm-table-icons">
            <x-icon name="dots-vertical" data-icon="actions"/>
            <x-icon name="arrow-left" data-icon="previous"/>
            <x-icon name="arrow" data-icon="next"/>
        </template>

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
                            <th scope="col">STATUS</th>
                            <th scope="col">REKOMENDASI</th>
                            <th scope="col">AKSI</th>
                        </tr>
                    </thead>
                    <tbody id="umkm-rows" data-detail-url="{{ route('umkms.show', ['id' => '__id__'], false) }}"></tbody>
                </table>
            </div>
            <ul id="umkm-cards" class="umkm-card-list" role="list" aria-label="Daftar usaha"></ul>

            <div class="table-footer">
                <p id="umkm-page-info" class="muted small"></p>
                <div class="pagination-controls hstack">
                    <label for="umkm-per-page" class="sr-only">Data per halaman</label>
                    <select id="umkm-per-page" class="control">
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
</div>

<dialog id="umkm-modal" class="modal dialog" aria-labelledby="umkm-modal-title">
    <div class="dialog-head modal-heading">
        <h2 id="umkm-modal-title" class="section-title">Tambah Data UMKM</h2>
        <button type="button" class="icon-button" data-close-dialog aria-label="Tutup formulir"><x-icon name="close"/></button>
    </div>
    <form id="umkm-form" method="post" novalidate>
        <div class="dialog-body">
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
