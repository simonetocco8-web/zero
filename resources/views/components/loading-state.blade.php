@props(['label' => 'Caricamento in corso…'])
<div role="status" {{ $attributes->class(['flex items-center gap-3 text-sm text-slate-600']) }}><span class="size-5 rounded-full border-2 border-slate-200 border-t-brand-600 motion-safe:animate-spin" aria-hidden="true"></span>{{ $label }}</div>
