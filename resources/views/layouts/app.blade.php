<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0207CA"><title>{{ $title }} — JoyUMKM</title><link rel="icon" href="{{ asset('favicon.png') }}">@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body data-page="{{ $page }}" data-protected="true">
    <div id="auth-status" class="guard-status" role="status"><span class="loading-dot"></span><p>Memeriksa sesi Anda…</p></div>
    <div id="app-shell" class="layout" hidden>
        <a href="#main-content" class="skip-link">Lewati ke konten utama</a>
        <aside id="desktop-sidebar" class="sidebar" aria-label="Navigasi dashboard">@include('components.navigation')</aside>
        <dialog id="navigation-drawer" class="navigation-drawer" aria-label="Menu navigasi"><button type="button" class="drawer-close icon-button" data-close-dialog aria-label="Tutup menu"><x-icon name="close" /></button>@include('components.navigation')</dialog>
        <div class="main-shell workspace">
            <header class="topbar">
                <button id="menu-button" class="icon-button mobile-only" type="button" aria-label="Buka menu navigasi" aria-controls="navigation-drawer" aria-expanded="false"><x-icon name="menu" /></button>
                <form id="global-search-form" role="search" class="global-search top-search"><label class="sr-only" for="global-search">Cari UMKM</label><x-icon name="search" /><input id="global-search" name="search" type="search" placeholder="Cari UMKM di portofolio..." maxlength="120" autocomplete="off" aria-label="Cari UMKM"></form>
                <div class="topbar-spacer"></div>
                <span class="workspace-context workspace-tag">Ruang Pendamping</span>
                <button type="button" class="icon-button notification-button" aria-label="Notifikasi" data-notification-btn><x-icon name="bell" /></button>
                <div class="topbar-account top-profile"><div class="user-avatar avatar" data-user-initials aria-label="Inisial pengguna"></div><div class="user-details"><strong data-user-name></strong><small>Pendamping UMKM</small></div></div>
            </header>
            <main id="main-content" class="main-content" tabindex="-1">@yield('content')</main>
            @include('components.footer')
        </div>
    </div>
    <noscript><p class="notice">Aktifkan JavaScript untuk masuk dan mengakses data JoyUMKM.</p></noscript>
    <div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="false"></div>
</body>
</html>
