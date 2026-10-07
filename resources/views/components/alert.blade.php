@props(['variant' => 'info', 'title' => null])
@php($color = match($variant) { 'danger' => 'border-red-200 bg-red-50 text-red-900', 'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900', default => 'border-slate-200 bg-slate-100 text-slate-700' })
<div role="{{ $variant === 'danger' ? 'alert' : 'status' }}" {{ $attributes->class(['rounded-xl border p-4 text-sm leading-relaxed', $color]) }}>@if($title)<p class="mb-1 font-semibold">{{ $title }}</p>@endif{{ $slot }}</div>
