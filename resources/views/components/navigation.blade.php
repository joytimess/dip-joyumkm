<a href="{{ route('home') }}" class="brand sidebar-brand"><span class="brand-mark">J</span><span>JoyUMKM<small>UMKM Health Dashboard</small></span></a>
<p class="nav-section-label">RUANG KERJA</p>
<nav class="workspace-navigation" aria-label="Ruang kerja">
    @foreach ([['home', 'Home', 'home'], ['factor-profile', 'Factor Profile', 'radar'], ['distribution', 'Distribution', 'distribution'], ['comparison', 'Comparison', 'comparison'], ['umkms', 'UMKM & Recommendations', 'business'], ['form-data', 'Form Data', 'form']] as [$routeName, $label, $iconName])
        <a href="{{ route($routeName) }}" class="nav-link {{ $page === $routeName ? 'is-active' : '' }}" @if($page === $routeName) aria-current="page" @endif><x-icon :name="$iconName"/><span>{{ $label }}</span></a>
    @endforeach
</nav>
<div class="sidebar-bottom">
    <button type="button" class="nav-link unavailable-link" disabled title="Profil Saya belum tersedia"><x-icon name="user"/><span>Profil Saya<small>Belum tersedia</small></span></button>
    <button type="button" class="nav-link unavailable-link" disabled title="Help belum tersedia"><x-icon name="help"/><span>Help<small>Belum tersedia</small></span></button>
    <button type="button" class="nav-link logout-link" data-logout><x-icon name="logout"/><span>Logout</span></button>
</div>
