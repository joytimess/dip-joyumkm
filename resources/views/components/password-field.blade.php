@props(['name' => 'password', 'label' => 'Kata sandi', 'register' => false, 'strength' => false])
<div class="field">
    <div class="field-top"><label for="{{ $name }}">{{ $label }}</label></div>
    <div class="input-shell has-trailing">
        <input id="{{ $name }}" name="{{ $name }}" type="password" autocomplete="{{ $register ? 'new-password' : 'current-password' }}" required @if($register) minlength="8" @endif aria-describedby="error-{{ $name }}{{ $strength ? ' password-help password-strength-label' : '' }}" placeholder="{{ $name === 'password_confirmation' ? 'Ulangi kata sandi' : ($register ? 'Minimal 8 karakter' : 'Masukkan kata sandi') }}">
        <button type="button" class="eye-toggle" data-password-toggle aria-controls="{{ $name }}" aria-label="Tampilkan {{ strtolower($label) }}" aria-pressed="false"><svg aria-hidden="true"><use href="#joy-i-eye" /></svg></button>
    </div>
    @if($strength)
        <small id="password-help" class="input-hint">Minimal 8 karakter, maksimal 72 byte.</small>
        <div class="strength-row" data-password-strength data-level="0" aria-hidden="true"><span class="strength-segment" data-strength-segment></span><span class="strength-segment" data-strength-segment></span><span class="strength-segment" data-strength-segment></span></div>
        <p id="password-strength-label" class="input-hint" data-strength-label role="status">Gunakan kata sandi yang unik.</p>
    @endif
    <p class="field-error" data-error="{{ $name }}" id="error-{{ $name }}"></p>
</div>
