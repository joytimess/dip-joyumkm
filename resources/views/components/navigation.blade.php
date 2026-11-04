<a href="{{ route('home') }}" class="brand sidebar-brand"><span class="brand-mark">J</span><span class="brand-text"><strong>JoyUMKM</strong><small>UMKM Health Dashboard</small></span></a>
<div class="nav-section-label side-label">Ruang kerja</div>
<nav class="workspace-navigation nav-list" aria-label="Ruang kerja">
    @foreach ([['home', 'Home', 'home'], ['factor-profile', 'Factor Profile', 'radar'], ['distribution', 'Distribution', 'distribution'], ['comparison', 'Comparison', 'comparison'], ['umkms', 'UMKM & Recommendations', 'business'], ['form-data', 'Form Data', 'form']] as [$routeName, $label, $iconName])
        <a href="{{ route($routeName) }}" class="nav-link {{ $page === $routeName ? 'is-active active' : '' }}" @if($page === $routeName) aria-current="page" @endif><x-icon :name="$iconName"/><span>{{ $label }}</span></a>
    @endforeach
</nav>
<div class="sidebar-bottom">
    <div class="sidebar-divider"></div>
    <button type="button" class="nav-link side-nav-action" data-profile-btn><x-icon name="user"/><span>Profil Saya</span></button>
    <button type="button" class="nav-link side-nav-action" data-help-btn><x-icon name="help"/><span>Pusat Bantuan</span></button>
    <button type="button" class="nav-link logout-link logout" data-logout><x-icon name="logout"/><span>Logout</span></button>
    <div class="sidebar-note side-bottom-note">JoyUMKM Design Concept<br>Midnight Black · Electric Blue · Cantaloupe Orange</div>
</div>
