@props(['label' => 'Caricamento in corso…'])
<div role="status" {{ $attributes->class(['flex items-center gap-3 text-sm text-muted']) }}><span class="size-5 rounded-full border-2 border-line border-t-brand-600 motion-safe:animate-spin" aria-hidden="true"></span>{{ $label }}</div>
