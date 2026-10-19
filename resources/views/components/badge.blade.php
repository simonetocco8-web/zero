@props(['variant' => 'neutral'])
@php($color = match($variant) { 'success' => 'bg-emerald-950 text-emerald-200', 'warning' => 'bg-amber-950 text-amber-200', 'danger' => 'bg-red-950 text-red-200', default => 'bg-raised text-muted' })
<span {{ $attributes->class(['inline-flex rounded-full px-3 py-1 text-xs font-semibold', $color]) }}>{{ $slot }}</span>
