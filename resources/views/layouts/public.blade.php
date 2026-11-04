<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <meta name="theme-color" content="#0207CA">
    <meta name="description" content="JoyUMKM membantu pendamping memahami kesehatan bisnis UMKM melalui kuesioner dan visualisasi.">
    <title>{{ $title }} — JoyUMKM</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-page="{{ $page }}" class="public-body">
    <a href="#main-content" class="skip-link">Lewati ke konten utama</a>
    <x-public-icons />
    @yield('content')
    <div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="false"></div>
</body>
</html>
