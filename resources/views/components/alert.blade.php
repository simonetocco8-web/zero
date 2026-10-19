@props(['variant' => 'info', 'title' => null])
@php($color = match($variant) { 'danger' => 'border-red-800 bg-red-950 text-red-200', 'success' => 'border-emerald-800 bg-emerald-950 text-emerald-200', default => 'border-line bg-raised text-muted' })
<div role="{{ $variant === 'danger' ? 'alert' : 'status' }}" {{ $attributes->class(['rounded-xl border p-4 text-sm leading-relaxed', $color]) }}>@if($title)<p class="mb-1 font-semibold">{{ $title }}</p>@endif{{ $slot }}</div>
