@props(['name' => 'box'])
@php
$paths = [
    'building' => 'M4 21V7l8-4 8 4v14 M2 21h20 M8 9h1 M15 9h1 M8 13h1 M15 13h1 M10 21v-4h4v4',
    'bricks' => 'M3 4h18v16H3z M3 9h18 M3 15h18 M12 4v5 M8 9v6 M16 9v6 M12 15v5',
    'wrench' => 'M14 6a6 6 0 0 0-7 8l-4 4a2 2 0 0 0 3 3l4-4a6 6 0 0 0 8-7l-4 3-3-3z',
    'droplet' => 'M12 3C9 7 5 11 5 15a7 7 0 0 0 14 0c0-4-4-8-7-12z M8 15a4 4 0 0 0 4 4',
    'paint' => 'm8 3 11 11-7 7a3 3 0 0 1-4 0l-5-5a3 3 0 0 1 0-4l7-7 M4 13h14 M8 3v8 M21 16s-2 3-2 4a2 2 0 0 0 4 0c0-1-2-4-2-4',
    'construction' => 'M3 21h18 M6 21V3h3v18 M9 4h11v3H9 M14 7v7 M12 14h4v3h-4z M3 7h3',
    'drill' => 'M3 5h12v8H3z M15 7h4v4h-4 M19 9h3 M6 13v7h5l-1-7 M3 8h5 M5 20h7',
    'chevron-down' => 'm6 9 6 6 6-6',
    'chevron-left' => 'm15 6-6 6 6 6',
    'chevron-right' => 'm9 6 6 6-6 6',
    'sun' => 'M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M12 2v2 M12 20v2 M2 12h2 M20 12h2 M5 5l1.5 1.5 M17.5 17.5 19 19 M5 19l1.5-1.5 M17.5 6.5 19 5',
    'moon' => 'M20.5 13A8.5 8.5 0 0 1 11 3.5 8.5 8.5 0 1 0 20.5 13z',
    'grid' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    'box' => 'm12 3 9 5v8l-9 5-9-5V8z M3 8l9 5 9-5 M12 13v8 M7.5 5.5l9 5',
    'plus' => 'M12 5v14 M5 12h14',
    'chat' => 'M21 11a8 8 0 0 1-8 8H5l-3 3V11a8 8 0 0 1 8-8h3a8 8 0 0 1 8 8z M7 9h10 M7 13h6',
    'wallet' => 'M3 7V5a2 2 0 0 1 2-2h13v4 M3 7h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M21 11h-6v6h6 M17 14h1',
    'user' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2',
    'users' => 'M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M3 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2 M19 3a4 4 0 0 1 0 8 M21 21v-2a6 6 0 0 0-3-5',
    'settings' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z',
    'arrow' => 'M5 12h14 M13 6l6 6-6 6',
    'exchange' => 'M4 7h16 M16 3l4 4-4 4 M20 17H4 M8 13l-4 4 4 4',
    'logout' => 'M9 3H4v18h5 M9 12h12 M16 7l5 5-5 5',
];
@endphp
<svg {{ $attributes->class(['size-5 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['box'] }}" /></svg>
