@props(['name'])
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('home')<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>@break
        @case('radar')<path d="m12 3 9 7-4 11H7L3 10zM12 3v9m9-2-9 2m5 9-5-9m-5 9 5-9m-9-2 9 2"/>@break
        @case('distribution')<path d="M21 12a9 9 0 1 1-9-9v9zM15 3.5A9 9 0 0 1 20.5 9H15z"/>@break
        @case('comparison')<path d="M4 20V9h4v11m4 0V4h4v16m4 0V12h2v8M2 20h20"/>@break
        @case('business')<path d="M3 9h18l-2-5H5zM4 9v11h16V9M9 20v-7h6v7M2 9v2a3 3 0 0 0 5 2 3 3 0 0 0 5 0 3 3 0 0 0 5-2V9"/>@break
        @case('form')<path d="M9 4H5v17h14V4h-4M9 3h6v4H9zM8 11h8M8 15h8"/>@break
        @case('user')<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>@break
        @case('help')<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 4 2c-1 .5-1.5 1-1.5 2M12 17h.01"/>@break
        @case('logout')<path d="M9 3H4v18h5M13 7l5 5-5 5M8 12h13"/>@break
        @case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
        @case('close')<path d="m6 6 12 12M18 6 6 18"/>@break
        @case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>@break
        @case('bell')<path d="M18 8a6 6 0 0 0-12 0v5l-2 4h16l-2-4zM10 21h4"/>@break
        @case('arrow')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
        @case('plus')<path d="M12 5v14M5 12h14"/>@break
        @case('filter')<path d="M3 5h18l-7 8v6l-4 2v-8z"/>@break
    @endswitch
</svg>
