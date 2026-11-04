@extends('layouts.public', ['page' => 'login', 'title' => 'Masuk'])
@section('content')
<main id="main-content" class="auth-page" tabindex="-1">
    <x-auth-brand-panel  />
    <section class="auth-main" aria-labelledby="auth-heading">
        <div class="auth-top"><a class="top-home" href="{{ route('welcome') }}"><svg aria-hidden="true"><use href="#joy-i-back" /></svg>Kembali ke beranda</a></div>
        <div id="auth-status" class="auth-check" role="status"></div>
        <div class="auth-content">
            <a class="auth-back" href="{{ route('welcome') }}"><svg aria-hidden="true"><use href="#joy-i-back" /></svg>Kembali ke beranda</a>
            <p class="auth-kicker">Selamat datang kembali</p>
            <h1 id="auth-heading">Masuk ke JoyUMKM.</h1>
            <p class="auth-description">Lanjutkan pendampingan dan pantau perkembangan UMKM Anda.</p>
            <form id="auth-form" method="post" data-auth-action="login" novalidate>
                <div id="form-message" class="form-message" role="alert" tabindex="-1" hidden></div>
                
                <div class="field"><div class="field-top"><label for="email">Alamat email</label></div><div class="input-shell"><input id="email" name="email" type="email" inputmode="email" autocomplete="email" required maxlength="254" placeholder="nama@contoh.id" aria-describedby="error-email"></div><p class="field-error" data-error="email" id="error-email"></p></div>
                <x-password-field  />
                
                <button type="submit" class="btn btn-primary auth-submit">Masuk</button>
            </form>
            <p class="auth-switch">Belum punya akun? <a href="{{ route('register') }}">Daftar sekarang</a></p>
        </div>
        <div class="auth-bottom">? 2026 JoyUMKM</div>
    </section>
</main>
@endsection
