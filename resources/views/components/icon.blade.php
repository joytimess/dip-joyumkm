@props(['name'])
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" aria-hidden="true">
    <use href="#joy-i-{{ $name }}" />
</svg>
