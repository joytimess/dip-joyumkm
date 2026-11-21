<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0207CA"><title>{{ $title }} — JoyUMKM</title><link rel="icon" href="{{ asset('favicon.png') }}">@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body data-page="{{ $page }}" data-protected="true">
    <x-icon-sprite />
    <div id="app-shell" class="layout">
        <a href="#main-content" class="skip-link">Lewati ke konten utama</a>
        <aside id="desktop-sidebar" class="sidebar" aria-label="Navigasi dashboard">@include('components.navigation')</aside>
        <dialog id="navigation-drawer" class="navigation-drawer" aria-label="Menu navigasi"><button type="button" class="drawer-close icon-button" data-close-dialog aria-label="Tutup menu" autofocus><x-icon name="close" /></button>@include('components.navigation')</dialog>
        <div class="main-shell workspace">
            <header class="topbar">
                <button id="menu-button" class="icon-button mobile-only" type="button" aria-label="Buka menu navigasi" aria-controls="navigation-drawer" aria-expanded="false"><x-icon name="menu" /></button>
                <div id="inline-search">
                    <form id="global-search-form" role="search" class="global-search" action="{{ route('umkms') }}" inert>
                        <label class="sr-only" for="global-search">Cari UMKM</label>
                        <x-icon name="search" />
                        <input id="global-search" name="search" type="search" placeholder="Cari UMKM di portofolio" maxlength="120" autocomplete="off">
                        <button type="submit" class="btn btn-primary button button-primary search-submit">Cari</button>
                    </form>
                </div>
                <span class="compact-brand">JoyUMKM</span>
                <div class="topbar-spacer"></div>
                <button id="search-button" type="button" class="icon-button" aria-label="Buka pencarian UMKM" aria-controls="search-dialog" aria-expanded="false"><x-icon name="search" /></button>
                <div class="topbar-account top-profile"><div class="user-avatar avatar" data-user-initials aria-label="Inisial pengguna"></div><div class="user-details"><strong data-user-name></strong><small>Pendamping UMKM</small></div></div>
            </header>
            <dialog id="search-dialog" class="modal workspace-search-dialog" aria-labelledby="search-dialog-title">
                <div class="dialog-head">
                    <h2 id="search-dialog-title">Cari UMKM</h2>
                    <button type="button" class="icon-button" data-close-dialog aria-label="Tutup pencarian"><x-icon name="close" /></button>
                </div>
                <div class="dialog-body" id="dialog-search"></div>
            </dialog>
            <div id="auth-status" class="px-4 pt-4" role="status" hidden></div>
            <noscript><p class="notice">Aktifkan JavaScript untuk masuk dan mengakses data JoyUMKM.</p></noscript>
            <div id="navigation-status" class="sr-only" role="status"></div>
            <div id="navigation-error" class="px-4" hidden></div>
            <main id="main-content" class="main-content" data-navigation-page="{{ $navigationPage ?? $page }}" tabindex="-1" inert>@yield('content')</main>
            @include('components.footer')
        </div>
    </div>
    <div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="false"></div>
</body>
</html>
