@props(['name' => 'box'])
@php
$paths = [
    'grid' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    'box' => 'm12 3 9 5v8l-9 5-9-5V8z M3 8l9 5 9-5 M12 13v8 M7.5 5.5l9 5',
    'plus' => 'M12 5v14 M5 12h14',
    'chat' => 'M21 11a8 8 0 0 1-8 8H5l-3 3V11a8 8 0 0 1 8-8h3a8 8 0 0 1 8 8z M7 9h10 M7 13h6',
    'wallet' => 'M3 7V5a2 2 0 0 1 2-2h13v4 M3 7h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M21 11h-6v6h6 M17 14h1',
    'user' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2',
    'users' => 'M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M3 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2 M19 3a4 4 0 0 1 0 8 M21 21v-2a6 6 0 0 0-3-5',
    'settings' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z',
    'arrow' => 'M5 12h14 M13 6l6 6-6 6',
    'logout' => 'M9 3H4v18h5 M9 12h12 M16 7l5 5-5 5',
];
@endphp
<svg {{ $attributes->class(['size-5 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['box'] }}" /></svg>
