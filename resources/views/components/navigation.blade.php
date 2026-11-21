<a href="{{ route('home') }}" class="brand sidebar-brand"><span class="brand-mark">J</span><span class="brand-text"><strong>JoyUMKM</strong><small>Ruang Pendamping UMKM</small></span></a>
<nav class="workspace-navigation nav-list" aria-label="Ruang kerja">
    @foreach ([
        'Beranda' => [['home', 'home', [], 'Beranda', 'home']],
        'Manajemen' => [['umkms', 'umkms', [], 'Data UMKM', 'business']],
        'Penilaian' => [
            ['form-data', 'form-data', [], 'Kuesioner Tiga Dimensi', 'form'],
            ['six-factor-evaluation', 'umkms', ['mode' => 'six-factor'], 'Penilaian Enam Faktor', 'form'],
        ],
        'Analitik' => [
            ['factor-profile', 'factor-profile', [], 'Profil Faktor', 'radar'],
            ['distribution', 'distribution', [], 'Distribusi Kesehatan', 'distribution'],
            ['comparison', 'comparison', [], 'Perbandingan UMKM', 'comparison'],
        ],
    ] as $group => $items)
        <div class="nav-group" role="group" aria-label="{{ $group }}">
            @if ($group !== 'Beranda')<p class="nav-section-label">{{ $group }}</p>@endif
            @foreach ($items as [$key, $routeName, $parameters, $label, $iconName])
                <a href="{{ route($routeName, $parameters) }}" data-workspace-page="{{ $key }}" class="nav-link {{ ($navigationPage ?? $page) === $key ? 'is-active active' : '' }}" @if(($navigationPage ?? $page) === $key) aria-current="{{ $page === $routeName ? 'page' : 'location' }}" @endif><x-icon :name="$iconName"/><span>{{ $label }}</span></a>
            @endforeach
        </div>
    @endforeach
</nav>
<div class="sidebar-bottom">
    <div class="sidebar-divider"></div>
    <button type="button" class="nav-link logout-link logout" data-logout><x-icon name="logout"/><span>Keluar</span></button>
</div>
