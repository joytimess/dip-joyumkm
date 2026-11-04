@props(['light' => false])
<a href="{{ route('welcome') }}" {{ $attributes->class(['brand', 'brand-light' => $light]) }} aria-label="JoyUMKM, beranda">
    <span class="brand-mark" aria-hidden="true">J</span>
    <span><strong class="brand-name">JoyUMKM</strong><span class="brand-sub">UMKM Health Dashboard</span></span>
</a>
