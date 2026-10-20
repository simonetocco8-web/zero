@if(is_file(public_path('brand/zero-magazzino-logo.png')))
<span {{ $attributes->class(['inline-flex min-w-0 items-center brand-logo-wrap']) }}><img class="brand-logo" src="{{ asset('brand/zero-magazzino-logo.png') }}" alt="ZeroMagazzino.it" width="220" height="70"></span>
@else
<span {{ $attributes->class(['inline-flex items-center gap-2.5 font-bold tracking-tight']) }}><span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-lg text-brand-950" aria-hidden="true">Z</span><span>Zero<span class="font-normal">Magazzino</span></span></span>
@endif
