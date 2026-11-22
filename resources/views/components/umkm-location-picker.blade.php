    <p class="section-desc">Cari atau pilih titik usaha langsung di peta.</p>
    <label for="umkm-location-search">Cari lokasi usaha</label>
    <div class="umkm-location-search">
        <input id="umkm-location-search" type="search" maxlength="120" placeholder="Cari alamat / lokasi usaha" autocomplete="off">
        <button type="button" class="button button-outline" data-location-search>Cari</button>
    </div>
    <p class="section-desc" role="status" data-search-status></p>
    <ul class="umkm-location-results" aria-label="Hasil pencarian lokasi" hidden></ul>
    <div class="umkm-map-frame">
        <div id="umkm-map" class="umkm-map" role="region" aria-label="Peta lokasi usaha"
            aria-describedby="umkm-map-instructions umkm-error-latitude" tabindex="0"
            data-tile-url="{{ config('services.geocoding.tile_url') }}"></div>
        <div class="umkm-map-credit">
            © <a class="text-link" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors
        </div>
    </div>
    <div class="umkm-pin-status" role="status" aria-atomic="true"></div>
    <p class="section-desc" id="umkm-map-instructions">Klik peta atau geser pin. Gunakan tombol panah untuk menggeser peta dengan keyboard.</p>
    <div class="umkm-map-actions">
        <button type="button" class="button button-outline" data-location-center disabled>Pilih titik tengah peta</button>
        <button type="button" class="button button-outline" data-location-retry hidden>Coba lagi</button>
    </div>
    <p class="section-desc" role="status" data-map-status></p>
    <p class="field-error" data-error="latitude" id="umkm-error-latitude"></p>
    <h4 class="section-title umkm-location-detail">Detail lokasi</h4>
    <p class="section-desc">Periksa detail dan koreksi alamat bila perlu.</p>
