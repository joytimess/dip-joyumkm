<section id="umkm-summary" class="summary-section" aria-label="Ringkasan metrik portofolio" aria-busy="true">
    <div id="summary-cards" class="grid-4 summary-cards">@foreach (['Total responden', 'Rata-rata skor', 'Sektor prioritas', 'Draft aktif'] as $metric)<div class="card metric panel"><p class="metric-label">{{ $metric }}</p><div class="skeleton h-10 mt-4"></div></div>@endforeach</div>
</section>
