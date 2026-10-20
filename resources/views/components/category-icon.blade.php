@props(['category'])
@php
    $icons = [
        'edilizia' => 'building',
        'ferramenta' => 'wrench',
        'idraulica' => 'droplet',
        'colore' => 'paint',
        'edilizia-2' => 'construction',
        'edilizia-pesante' => 'construction',
        'elettroutensili' => 'drill',
    ];
    $icon = $icons[\Illuminate\Support\Str::slug($category->slug ?? '')]
        ?? $icons[\Illuminate\Support\Str::slug($category->name ?? '')]
        ?? 'box';
@endphp
<x-icon :name="$icon" {{ $attributes->merge(['data-category-icon' => $icon]) }} />
