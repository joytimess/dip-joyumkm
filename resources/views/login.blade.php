@extends('layouts.public', ['page' => 'login', 'title' => 'Masuk'])
@section('content')
<main class="auth-layout">
    <section class="auth-story"><a href="{{ route('welcome') }}" class="brand"><span class="brand-mark">J</span><span>JoyUMKM<small>UMKM Health Dashboard</small></span></a><div><h2>Pahami usahanya.<br>Dampingi langkahnya.</h2><p>Kelola data dan evaluasi UMKM yang Anda dampingi dalam satu ruang kerja.</p></div><p class="auth-story-foot">Sumber daya · Eksternal · Operasional</p></section>
    <section class="auth-form-side">
        <div id="auth-status" class="auth-check" role="status"></div>
        <div class="auth-form-wrap"><a class="back-link" href="{{ route('welcome') }}">← Kembali ke beranda</a><h1>Masuk ke JoyUMKM</h1><p class="muted">Lanjutkan pendampingan UMKM Anda.</p>
            <form id="auth-form" method="post" data-auth-action="login" novalidate>
                <div id="form-message" class="form-message" role="alert" tabindex="-1" hidden></div>
                <div class="form-field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="254" placeholder="nama@contoh.id"><p class="field-error" data-error="email" id="error-email"></p></div>
                <div class="form-field"><label for="password">Kata sandi</label><input id="password" name="password" type="password" autocomplete="current-password" required><p class="field-error" data-error="password" id="error-password"></p></div>
                <button type="submit" class="button button-primary w-full">Masuk</button>
            </form>
            <p class="auth-alternative">Belum punya akun? <a href="{{ route('register') }}">Daftar sekarang</a></p>
        </div>
        <p class="auth-copyright">© 2026 JoyUMKM</p>
    </section>
</main>
@endsection
