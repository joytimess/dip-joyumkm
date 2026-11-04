@extends('layouts.public', ['page' => 'register', 'title' => 'Daftar Akun'])
@section('content')
<main id="main-content" class="auth-page" tabindex="-1">
    <x-auth-brand-panel register />
    <section class="auth-main" aria-labelledby="auth-heading">
        <div class="auth-top"><a class="top-home" href="{{ route('welcome') }}"><svg aria-hidden="true"><use href="#joy-i-back" /></svg>Kembali ke beranda</a></div>
        <div id="auth-status" class="auth-check" role="status"></div>
        <div class="auth-content">
            <a class="auth-back" href="{{ route('welcome') }}"><svg aria-hidden="true"><use href="#joy-i-back" /></svg>Kembali ke beranda</a>
            <p class="auth-kicker">Bergabung dengan JoyUMKM</p>
            <h1 id="auth-heading">Mulai pendampingan.</h1>
            <p class="auth-description">Buat akun pendamping untuk mengelola data dan evaluasi UMKM dalam satu tempat.</p>
            <form id="auth-form" method="post" data-auth-action="register" novalidate>
                <div id="form-message" class="form-message" role="alert" tabindex="-1" hidden></div>
                <div class="field"><div class="field-top"><label for="name">Nama lengkap</label></div><div class="input-shell"><input id="name" name="name" type="text" autocomplete="name" required maxlength="120" placeholder="Nama pendamping" aria-describedby="error-name"></div><p class="field-error" data-error="name" id="error-name"></p></div>
                <div class="field"><div class="field-top"><label for="email">Alamat email</label></div><div class="input-shell"><input id="email" name="email" type="email" inputmode="email" autocomplete="email" required maxlength="254" placeholder="nama@contoh.id" aria-describedby="error-email"></div><p class="field-error" data-error="email" id="error-email"></p></div>
                <x-password-field register strength />
                <x-password-field name="password_confirmation" label="Konfirmasi kata sandi" register />
                <button type="submit" class="btn btn-primary auth-submit">Daftar dan Mulai</button>
            </form>
            <p class="auth-switch">Sudah punya akun? <a href="{{ route('login') }}">Masuk</a></p>
        </div>
        <div class="auth-bottom">© 2026 JoyUMKM</div>
    </section>
</main>
@endsection
