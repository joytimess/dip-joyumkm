<section id="umkm-summary" class="summary-section" aria-label="Ringkasan metrik portofolio" aria-busy="true">
    <div id="summary-cards" class="grid-4 summary-cards">@foreach (['Total UMKM', 'Rata-rata skor', 'Sektor prioritas', 'Draft aktif'] as $metric)<div class="card metric panel"><p class="metric-label">{{ $metric }}</p><div class="skeleton h-8 w-1/2 mt-2" aria-hidden="true"></div><div class="skeleton h-3 w-3/4 mt-2" aria-hidden="true"></div></div>@endforeach</div>
</section>
