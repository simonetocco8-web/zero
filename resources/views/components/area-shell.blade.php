@props(['area', 'title'])
@php
$links = config('navigation.'.$area);
if ($area === 'retailer' && (!auth()->user()->retailer || !auth()->user()->can('operate', auth()->user()->retailer))) {
    $links = array_filter($links, fn($link) => in_array($link['route'], ['retailer.dashboard', 'retailer.profile']));
}
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><x-head :title="$title" /></head>
<body>
<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:bg-white focus:p-3">Vai al contenuto</a>
<div class="min-h-dvh lg:flex">
    <aside class="hidden lg:flex lg:w-64 lg:shrink-0 lg:flex-col bg-brand-950 text-white">
        <div class="sticky top-0 flex h-dvh flex-col p-5">
            <a href="{{ route($area.'.dashboard') }}" class="mb-10 min-h-11"><x-brand /></a>
            <p class="mb-3 px-3 text-xs font-semibold uppercase tracking-widest text-emerald-200">{{ $area === 'admin' ? 'Amministrazione' : 'Area rivenditore' }}</p>
            <nav aria-label="Navigazione principale" class="space-y-1">
                @foreach($links as $link)
                    <a href="{{ route($link['route']) }}" class="nav-item {{ request()->routeIs($link['route']) ? 'nav-active' : 'nav-idle' }}" @if(request()->routeIs($link['route'])) aria-current="page" @endif><x-icon :name="$link['icon']" />{{ $link['label'] }}</a>
                @endforeach
            </nav>
            <div class="mt-auto border-t border-white/10 pt-5"><p class="px-3 text-xs text-emerald-200">Un nuovo valore ai materiali.</p></div>
        </div>
    </aside>
    <div class="min-w-0 flex-1">
        <header class="border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-10">
            <div class="flex min-h-20 flex-wrap items-center justify-between gap-3 py-3">
                <a href="{{ route($area.'.dashboard') }}" class="lg:hidden"><x-brand class="text-sm" /></a>
                <span class="hidden text-sm text-slate-500 lg:block">{{ $area === 'admin' ? 'Area amministratore' : 'Area rivenditore' }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<x-button variant="secondary" type="submit"><x-icon name="logout" /><span>Esci</span></x-button></form>
            </div>
            @if($area === 'admin')
            <nav aria-label="Navigazione mobile" class="flex flex-wrap gap-1 pb-3 lg:hidden">
                @foreach($links as $link)<a href="{{ route($link['route']) }}" class="btn {{ request()->routeIs($link['route']) ? 'btn-primary' : 'btn-secondary' }}" @if(request()->routeIs($link['route'])) aria-current="page" @endif>{{ $link['label'] }}</a>@endforeach
            </nav>
            @endif
        </header>
        <main id="main-content" tabindex="-1" class="mx-auto max-w-7xl px-4 pt-6 {{ $area === 'retailer' ? 'pb-48 lg:pb-10' : 'pb-10' }} sm:px-6 lg:px-10 lg:pt-10">{{ $slot }}</main>
    </div>
</div>
@if($area === 'retailer')
<nav aria-label="Navigazione mobile" class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-3 gap-1 border-t border-slate-200 bg-white px-2 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] lg:hidden">
    @foreach($links as $link)
        <a href="{{ route($link['route']) }}" class="flex min-h-14 min-w-0 flex-col items-center justify-center gap-1 rounded-xl px-1 py-2 text-center text-[11px] font-medium {{ request()->routeIs($link['route']) ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100' }}" @if(request()->routeIs($link['route'])) aria-current="page" @endif><x-icon :name="$link['icon']" /><span>{{ $link['label'] }}</span></a>
    @endforeach
</nav>
@endif
<x-toast />
</body>
</html>
