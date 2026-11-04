@extends('layouts.public', ['page' => 'register', 'title' => 'Daftar Akun'])
@section('content')
<main class="auth-layout">
    <section class="auth-story"><a href="{{ route('welcome') }}" class="brand"><span class="brand-mark">J</span><span>JoyUMKM<small>UMKM Health Dashboard</small></span></a><div><h2>Langkah kecil.<br>Pemahaman lebih baik.</h2><p>Mulai mengenali kondisi bisnis untuk menentukan prioritas pendampingan berikutnya.</p></div><p class="auth-story-foot">Data UMKM Anda, dalam akun Anda.</p></section>
    <section class="auth-form-side">
        <div id="auth-status" class="auth-check" role="status"></div>
        <div class="auth-form-wrap"><a class="back-link" href="{{ route('welcome') }}">← Kembali ke beranda</a><h1>Buat akun JoyUMKM</h1><p class="muted">Satu akun untuk seluruh UMKM dampingan Anda.</p>
            <form id="auth-form" method="post" data-auth-action="register" novalidate>
                <div id="form-message" class="form-message" role="alert" tabindex="-1" hidden></div>
                <div class="form-field"><label for="name">Nama lengkap</label><input id="name" name="name" type="text" autocomplete="name" required maxlength="120" placeholder="Nama pendamping"><p class="field-error" data-error="name" id="error-name"></p></div>
                <div class="form-field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="254" placeholder="nama@contoh.id"><p class="field-error" data-error="email" id="error-email"></p></div>
                <div class="form-field"><label for="password">Kata sandi</label><input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" aria-describedby="password-help"><small id="password-help" class="muted">Minimal 8 karakter, maksimal 72 byte.</small><p class="field-error" data-error="password" id="error-password"></p></div>
                <div class="form-field"><label for="password_confirmation">Konfirmasi kata sandi</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="8"><p class="field-error" data-error="password_confirmation" id="error-password_confirmation"></p></div>
                <button type="submit" class="button button-primary w-full">Daftar dan Mulai</button>
            </form>
            <p class="auth-alternative">Sudah punya akun? <a href="{{ route('login') }}">Masuk</a></p>
        </div>
        <p class="auth-copyright">© 2026 JoyUMKM</p>
    </section>
</main>
@endsection
