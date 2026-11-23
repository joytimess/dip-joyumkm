@extends('layouts.app', ['page' => 'umkm-detail', 'navigationPage' => 'umkms', 'title' => 'Detail UMKM'])

@section('content')
<div id="umkm-detail" class="page" data-umkm="{{ request()->route('id') }}">
    @include('components.umkm-breadcrumb', ['current' => 'Detail UMKM'])
    <div class="page-heading">
        <div>
            <h1 class="break-words">Detail UMKM</h1>
            <p class="section-desc">Tinjau dan perbarui data usaha per bagian. Perubahan disimpan hanya untuk bagian yang sedang diedit.</p>
        </div>
    </div>
    <div id="umkm-detail-state" class="card card-pad panel" role="status" aria-busy="true">
        <span class="sr-only">Memuat detail UMKM</span>
        <div class="skeleton h-5 w-1/3 mb-4" aria-hidden="true"></div>
        <div class="grid-3" aria-hidden="true">
            @foreach (range(1, 3) as $field)
                <div class="grid gap-3"><div class="skeleton h-3 w-1/3"></div><div class="skeleton h-5 w-2/3"></div></div>
            @endforeach
        </div>
    </div>
    <div id="umkm-detail-content" hidden>
        <section class="card card-pad panel detail-hero" aria-labelledby="detail-hero-name">
            <p class="metric-label">UMKM</p>
            <h2 id="detail-title" class="break-words"></h2>
            <p id="detail-hero-meta" class="muted"></p>
        </section>

        <div class="detail-tabs" role="tablist" aria-label="Bagian detail UMKM">
            <button type="button" role="tab" id="tab-profil" class="detail-tab" data-tab="profil" aria-controls="panel-profil" aria-selected="true" tabindex="0">Profil Usaha</button>
            <button type="button" role="tab" id="tab-lokasi" class="detail-tab" data-tab="lokasi" aria-controls="panel-lokasi" aria-selected="false" tabindex="-1">Lokasi Usaha</button>
            <button type="button" role="tab" id="tab-kontak" class="detail-tab" data-tab="kontak" aria-controls="panel-kontak" aria-selected="false" tabindex="-1">Kontak Usaha</button>
            <button type="button" role="tab" id="tab-penilaian" class="detail-tab" data-tab="penilaian" aria-controls="panel-penilaian" aria-selected="false" tabindex="-1">Penilaian</button>
        </div>

        {{-- PROFIL --}}
        <section id="panel-profil" class="card card-pad panel detail-panel" role="tabpanel" aria-labelledby="tab-profil">
            <div class="detail-panel-head">
                <div>
                    <h2 class="section-title">Informasi Usaha</h2>
                    <p class="section-desc">Identitas, sektor, omzet, dan deskripsi usaha.</p>
                </div>
                <button type="button" class="button button-outline" data-edit="profil">Edit Profil</button>
            </div>
            <div id="view-profil">
                <dl class="detail-grid">
                    <div><dt>Nama UMKM</dt><dd data-field="name"></dd></div>
                    <div><dt>Sektor usaha</dt><dd data-field="sector"></dd></div>
                    <div><dt>Nomor Induk Berusaha (NIB)</dt><dd data-field="nib"></dd></div>
                    <div class="detail-grid-wide"><dt>Deskripsi usaha</dt><dd data-field="description" class="pre-line"></dd></div>
                </dl>
                <h3 class="form-group-title">Profil Ekonomi &amp; Operasional</h3>
                <dl class="detail-grid">
                    <div><dt>Modal Usaha</dt><dd data-field="business_capital"></dd></div>
                    <div><dt data-omzet-label>Omzet</dt><dd data-field="omzet"></dd></div>
                    <div><dt>Tahun Mulai Usaha</dt><dd data-field="year_founded"></dd></div>
                    <div><dt>Jumlah Tenaga Kerja</dt><dd data-field="employee_count"></dd></div>
                </dl>
            </div>
            <form id="form-profil" class="detail-form" data-editor="profil" hidden novalidate>
                <div class="form-message" role="alert" tabindex="-1" hidden></div>
                <fieldset class="modal-grid">
                    <div class="field form-field full">
                        <label for="umkm-name">Nama UMKM</label>
                        <input id="umkm-name" name="name" class="control" maxlength="120" required>
                        <p class="field-error" data-error="name" id="umkm-error-name"></p>
                    </div>
                    <div class="field form-field full">
                        <label for="umkm-sector">Sektor</label>
                        <select id="umkm-sector" name="sector_id" class="control" required><option value="">Pilih sektor</option></select>
                        <p class="field-error" data-error="sector_id" id="umkm-error-sector_id"></p>
                    </div>
                    <div class="field form-field full">
                        <label for="umkm-nib">Nomor Induk Berusaha (NIB)</label>
                        <input id="umkm-nib" name="nib" class="control" inputmode="numeric" pattern="[0-9]{13}" maxlength="13">
                        <p class="field-error" data-error="nib" id="umkm-error-nib"></p>
                    </div>
                    <div class="field form-field full">
                        <label for="umkm-description">Deskripsi</label>
                        <textarea id="umkm-description" name="description" class="control" rows="3" maxlength="2000"></textarea>
                        <p class="field-error" data-error="description" id="umkm-error-description"></p>
                    </div>
                    <h3 class="form-group-title full">Profil Ekonomi &amp; Operasional</h3>
                    <div class="field form-field">
                        <label for="umkm-business-capital">Modal Usaha (Rp)</label>
                        <input id="umkm-business-capital" name="business_capital" class="control" type="text" inputmode="numeric" placeholder="Contoh: 50.000.000" aria-describedby="umkm-business-capital-help">
                        <small id="umkm-business-capital-help" class="muted tiny">Tidak termasuk tanah dan bangunan tempat usaha</small>
                        <p class="field-error" data-error="business_capital" id="umkm-error-business_capital"></p>
                    </div>
                    <div class="field form-field">
                        <label for="umkm-omzet">Omzet (Rp)</label>
                        <input id="umkm-omzet" name="omzet" class="control" type="text" inputmode="numeric" required placeholder="Contoh: 15.000.000" aria-describedby="umkm-omzet-help">
                        <small id="umkm-omzet-help" class="muted tiny">Masukkan nominal dalam rupiah.</small>
                        <p class="field-error" data-error="omzet" id="umkm-error-omzet"></p>
                    </div>
                    <div class="field form-field">
                        <label for="umkm-omzet-period">Periode Omzet</label>
                        <select id="umkm-omzet-period" name="omzet_period" class="control" aria-describedby="umkm-omzet-period-help">
                            <option value="">Pilih periode</option>
                            <option value="monthly">Bulanan</option>
                            <option value="yearly">Tahunan</option>
                        </select>
                        <small id="umkm-omzet-period-help" class="muted">Periode untuk nominal omzet.</small>
                        <p class="field-error" data-error="omzet_period" id="umkm-error-omzet_period"></p>
                    </div>
                    <div class="field form-field">
                        <label for="umkm-year-founded">Tahun Mulai Usaha</label>
                        <input id="umkm-year-founded" name="year_founded" class="control" type="number" inputmode="numeric" min="1800" max="{{ now()->year }}">
                        <p class="field-error" data-error="year_founded" id="umkm-error-year_founded"></p>
                    </div>
                    <div class="field form-field">
                        <label for="umkm-employee-count">Jumlah Tenaga Kerja</label>
                        <input id="umkm-employee-count" name="employee_count" class="control" type="number" inputmode="numeric" min="0" max="4294967295">
                        <p class="field-error" data-error="employee_count" id="umkm-error-employee_count"></p>
                    </div>
                </fieldset>
                <div class="detail-form-actions">
                    <button type="button" class="button button-outline" data-cancel>Batal</button>
                    <button type="submit" class="button button-primary" data-save>Simpan Perubahan</button>
                </div>
            </form>
        </section>

        {{-- LOKASI --}}
        <section id="panel-lokasi" class="card card-pad panel detail-panel" role="tabpanel" aria-labelledby="tab-lokasi" hidden>
            <div class="detail-panel-head">
                <div>
                    <h2 class="section-title">Lokasi Usaha</h2>
                    <p class="section-desc">Alamat administratif dan titik koordinat di peta.</p>
                </div>
                <button type="button" class="button button-outline" data-edit="lokasi">Edit Lokasi</button>
            </div>
            <div id="view-lokasi">
                <div id="detail-location-frame" class="umkm-map-frame detail-location-frame">
                    <div id="detail-location-map" class="umkm-map" role="img" aria-label="Peta titik lokasi usaha tersimpan"
                        data-tile-url="{{ config('services.geocoding.tile_url') }}"></div>
                    <div class="umkm-map-credit">
                        © <a class="text-link" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors
                    </div>
                </div>
                <p id="detail-location-empty" class="empty-state detail-location-empty" hidden>Titik koordinat belum tersedia. Gunakan Edit Lokasi untuk memilih titik di peta.</p>
                <dl class="detail-grid detail-location-facts">
                    <div><dt>Provinsi</dt><dd data-field="province"></dd></div>
                    <div><dt>Kabupaten/kota</dt><dd data-field="city"></dd></div>
                    <div><dt>Kecamatan</dt><dd data-field="district"></dd></div>
                    <div><dt>Kelurahan/desa</dt><dd data-field="subdistrict"></dd></div>
                    <div><dt>Kode pos</dt><dd data-field="postal_code"></dd></div>
                    <div><dt>Koordinat</dt><dd data-field="coordinates"></dd></div>
                    <div class="detail-grid-wide"><dt>Alamat lengkap</dt><dd data-field="address" class="pre-line"></dd></div>
                </dl>
            </div>
            <form id="form-lokasi" class="detail-form" data-editor="lokasi" hidden novalidate>
                <div class="form-message" role="alert" tabindex="-1" hidden></div>
                <fieldset class="modal-grid">
                    <div class="field form-field full" id="umkm-location">
                        @include('components.umkm-location-picker')
                    </div>
                    <div class="field form-field">
                        <label for="umkm-province">Provinsi</label>
                        <select id="umkm-province" name="province" class="control" required><option value="">Pilih provinsi</option></select>
                        <p class="field-error" data-error="province" id="umkm-error-province"></p>
                    </div>
                    <div class="field form-field">
                        <label for="umkm-city">Kabupaten/kota</label>
                        <input id="umkm-city" name="city" class="control" maxlength="120" required>
                        <p class="field-error" data-error="city" id="umkm-error-city"></p>
                    </div>
                    <div class="field form-field full">
                        <label for="umkm-address">Alamat lengkap</label>
                        <textarea id="umkm-address" name="address" class="control" rows="3" maxlength="2000" required></textarea>
                        <p class="field-error" data-error="address" id="umkm-error-address"></p>
                    </div>
                    @foreach (['district' => 'Kecamatan', 'subdistrict' => 'Kelurahan/desa'] as $field => $label)
                        <div class="field form-field">
                            <label for="umkm-{{ $field }}">{{ $label }}</label>
                            <input id="umkm-{{ $field }}" name="{{ $field }}" class="control" maxlength="120">
                            <p class="field-error" data-error="{{ $field }}" id="umkm-error-{{ $field }}"></p>
                        </div>
                    @endforeach
                    <div class="field form-field">
                        <label for="umkm-postal-code">Kode pos</label>
                        <input id="umkm-postal-code" name="postal_code" class="control" inputmode="numeric" pattern="[0-9]{5}" maxlength="5">
                        <p class="field-error" data-error="postal_code" id="umkm-error-postal_code"></p>
                    </div>
                    @foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit)
                        <div class="field form-field">
                            <label for="umkm-{{ $field }}-value">{{ ucfirst($field) }}</label>
                            <input id="umkm-{{ $field }}-value" name="{{ $field }}" class="control" type="number" step="any" min="{{ -$limit }}" max="{{ $limit }}" readonly aria-describedby="umkm-coordinates-help">
                            @if ($field === 'longitude')
                                <p class="field-error" data-error="longitude" id="umkm-error-longitude"></p>
                            @endif
                        </div>
                    @endforeach
                    <p id="umkm-coordinates-help" class="section-desc full">Koordinat mengikuti titik yang dipilih di peta.</p>
                </fieldset>
                <div class="detail-form-actions">
                    <button type="button" class="button button-outline" data-cancel>Batal</button>
                    <button type="submit" class="button button-primary" data-save>Simpan Perubahan</button>
                </div>
            </form>
        </section>

        {{-- KONTAK --}}
        <section id="panel-kontak" class="card card-pad panel detail-panel" role="tabpanel" aria-labelledby="tab-kontak" hidden>
            <div class="detail-panel-head">
                <div>
                    <h2 class="section-title">Kontak Usaha</h2>
                    <p class="section-desc">Pemilik, PIC, WhatsApp, email, dan tautan usaha.</p>
                </div>
                <button type="button" class="button button-outline" data-edit="kontak">Edit Kontak</button>
            </div>
            <dl id="view-kontak" class="detail-grid">
                <div><dt>Nama pemilik</dt><dd data-field="owner_name"></dd></div>
                <div><dt>Email usaha</dt><dd data-field="business_email"></dd></div>
                <div>
                    <dt>WhatsApp</dt>
                    <dd>
                        <span data-field="whatsapp"></span>
                        <p id="detail-whatsapp-status" class="umkm-whatsapp-status" role="status" aria-live="polite"></p>
                        <button type="button" class="button button-outline button-sm" id="detail-whatsapp-recheck" hidden>Periksa ulang WhatsApp</button>
                    </dd>
                </div>
                <div><dt>Nama PIC</dt><dd data-field="pic_name"></dd></div>
                <div><dt>Website</dt><dd data-field="website_url"></dd></div>
                <div><dt>Media sosial</dt><dd data-field="social_media_url"></dd></div>
            </dl>
            <form id="form-kontak" class="detail-form" data-editor="kontak" hidden novalidate>
                <div class="form-message" role="alert" tabindex="-1" hidden></div>
                <fieldset class="modal-grid">
                    <div class="field form-field">
                        <label for="umkm-owner-name">Nama pemilik</label>
                        <input id="umkm-owner-name" name="owner_name" class="control" maxlength="120" required>
                        <p class="field-error" data-error="owner_name" id="umkm-error-owner_name"></p>
                    </div>
                    <div class="field form-field">
                        <label for="umkm-business-email">Email usaha</label>
                        <input id="umkm-business-email" name="business_email" class="control" type="email" maxlength="254" required>
                        <p class="field-error" data-error="business_email" id="umkm-error-business_email"></p>
                    </div>
                    <div class="field form-field full">
                        <label for="umkm-whatsapp-number">Nomor WhatsApp</label>
                        <div class="umkm-phone-field">
                            <span aria-hidden="true">+62</span>
                            <input id="umkm-whatsapp-number" name="whatsapp_number" class="control" type="tel" inputmode="numeric"
                                placeholder="81234567890" required aria-describedby="umkm-whatsapp-help">
                        </div>
                        <small id="umkm-whatsapp-help" class="muted">Kode negara tetap +62. Isi nomor HP tanpa angka 0 di depan.</small>
                        <p class="field-error" data-error="whatsapp_number" id="umkm-error-whatsapp_number"></p>
                        <p id="umkm-whatsapp-status" class="umkm-whatsapp-status" role="status" aria-live="polite" aria-atomic="true"></p>
                    </div>
                    <div class="field form-field full">
                        <label for="umkm-pic-name">Nama PIC</label>
                        <input id="umkm-pic-name" name="pic_name" class="control" maxlength="120">
                        <p class="field-error" data-error="pic_name" id="umkm-error-pic_name"></p>
                    </div>
                    @foreach (['website_url' => 'Website', 'social_media_url' => 'Media sosial'] as $field => $label)
                        <div class="field form-field">
                            <label for="umkm-{{ str_replace('_', '-', $field) }}">{{ $label }}</label>
                            <input id="umkm-{{ str_replace('_', '-', $field) }}" name="{{ $field }}" class="control" type="url" maxlength="2048" placeholder="https://">
                            <p class="field-error" data-error="{{ $field }}" id="umkm-error-{{ $field }}"></p>
                        </div>
                    @endforeach
                </fieldset>
                <div class="detail-form-actions">
                    <button type="button" class="button button-outline" data-cancel>Batal</button>
                    <button type="submit" class="button button-primary" data-save>Simpan Perubahan</button>
                </div>
            </form>
        </section>

        {{-- PENILAIAN: dua section terpisah --}}
        <section id="panel-penilaian" class="detail-panel" role="tabpanel" aria-labelledby="tab-penilaian" hidden>
            <div class="section-top">
                <div>
                    <h2 class="section-title">Penilaian Usaha</h2>
                    <p class="section-desc">Dua metode penilaian memiliki hasil dan riwayat yang terpisah.</p>
                </div>
            </div>
            <div class="detail-assessment-grid">
                <article class="card card-pad panel detail-assessment-card" aria-labelledby="assessment-3d-title">
                    <h3 id="assessment-3d-title">Kuesioner Tiga Dimensi</h3>
                    <p class="section-desc">Jawab kuesioner untuk melihat skor kesehatan usaha hingga 100.</p>
                    <dl class="detail-assessment-facts">
                        <div><dt>Status</dt><dd id="assessment-3d-status">Memuat…</dd></div>
                        <div><dt>Skor terbaru</dt><dd id="assessment-3d-score">—</dd></div>
                    </dl>
                    <div class="detail-assessment-actions">
                        <a data-carry-list id="assessment-3d-cta" href="{{ route('form-data', ['umkm' => request()->route('id')]) }}" class="button button-primary">Isi Kuesioner</a>
                    </div>
                </article>
                <article class="card card-pad panel detail-assessment-card" aria-labelledby="assessment-6f-title">
                    <h3 id="assessment-6f-title">Penilaian Enam Faktor</h3>
                    <p class="section-desc">Beri nilai 1–5 pada tiap faktor. Hasil final dinilai dalam skala 30.</p>
                    <div id="assessment-6f-body" aria-busy="true">
                        <p id="assessment-6f-status" class="muted">Memuat penilaian…</p>
                        <p id="assessment-6f-result" class="num"></p>
                    </div>
                    <div class="detail-assessment-actions">
                        <a data-carry-list id="assessment-6f-cta" href="{{ route('umkms.six-factor-evaluations', ['id' => request()->route('id')]) }}" class="button button-outline">Mulai Penilaian</a>
                        <a data-carry-list class="text-link" href="{{ route('umkms.six-factor-evaluations', ['id' => request()->route('id')]) }}">Lihat riwayat penilaian</a>
                    </div>
                </article>
            </div>
        </section>
    </div>
</div>
@endsection
