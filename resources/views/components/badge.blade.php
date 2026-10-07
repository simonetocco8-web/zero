@props(['variant' => 'neutral'])
@php($color = match($variant) { 'success' => 'bg-emerald-50 text-emerald-800', 'warning' => 'bg-amber-50 text-amber-900', 'danger' => 'bg-red-50 text-red-800', default => 'bg-slate-100 text-slate-700' })
<span {{ $attributes->class(['inline-flex rounded-full px-3 py-1 text-xs font-semibold', $color]) }}>{{ $slot }}</span>
