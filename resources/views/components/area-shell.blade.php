@props(['area', 'title'])
@php
$links=config('navigation.'.$area);
$retailer=$area==='retailer' && auth()->user()->role->value==='retailer' ? auth()->user()->retailer : null;
$operate=$retailer && auth()->user()->can('operate',$retailer);
$links=array_values(array_filter($links,fn($l)=>!($l['requires_retailer']??false)||$retailer));
foreach($links as &$link){$link['children']=array_values(array_filter($link['children']??[],fn($c)=>!($c['requires_operate']??false)||$operate));}unset($link);
$href=fn($l)=>route($l['route'],$l['parameters']??[]).(isset($l['fragment'])?'#'.$l['fragment']:'');
$isActive=fn($l)=>request()->routeIs($l['pattern']??$l['route']) && (!isset($l['parameters']['resource'])||request()->route('resource')===$l['parameters']['resource']);
$home=match($area){'admin'=>'admin.dashboard','retailer'=>'retailer.dashboard',default=>'customer.dashboard'};
@endphp
<!DOCTYPE html><html data-theme="light" lang="{{ str_replace('_','-',app()->getLocale()) }}"><head><x-head :title="$title" :canonical="url()->current()" robots="noindex,follow" /></head><body class="area-page"><x-theme-toggle />
<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:bg-surface focus:p-3">Vai al contenuto</a>
<div class="min-h-dvh lg:flex">
    <aside class="management-sidebar hidden border-r border-line bg-surface text-ink lg:flex lg:w-64 lg:shrink-0 lg:flex-col">
        <div class="sticky top-0 max-h-dvh overflow-y-auto p-5">
            <a href="{{ route($home) }}" class="mb-6 flex min-h-11 items-center"><x-brand /></a>
            <nav aria-label="Navigazione principale" class="space-y-2">
                @foreach($links as $link)
                    <a href="{{ $href($link) }}" class="nav-item {{ $isActive($link)?'nav-active':'nav-idle' }}" @if($isActive($link)) aria-current="page" @endif><x-icon :name="$link['icon']" />{{ $link['label'] }}</a>
                    @if($link['children'])
                        <ul class="ml-3 space-y-1 border-l border-white/20 pl-3">@foreach($link['children'] as $child)<li><a class="flex min-h-11 items-center break-words rounded-lg px-3 text-sm hover:bg-white/10" href="{{ $href($child) }}" @if($isActive($child)) aria-current="page" @endif>{{ $child['label'] }}</a></li>@endforeach</ul>
                    @endif
                @endforeach
            </nav>
        </div>
    </aside>
    <div class="min-w-0 flex-1">
        <header class="border-b border-line bg-surface px-4 sm:px-6 lg:px-10">
            <div class="flex min-h-20 flex-wrap items-center justify-between gap-3 py-3">
                <a href="{{ route($home) }}" class="inline-flex min-h-11 items-center"><x-brand class="text-sm" /></a>
                <div class="flex flex-wrap gap-2"><x-button variant="secondary" :href="route('notifications.index')">Notifiche</x-button><form method="POST" action="{{ route('logout') }}">@csrf<x-button variant="secondary" type="submit">Esci</x-button></form></div>
            </div>
            <details class="pb-3 lg:hidden"><summary class="flex min-h-11 cursor-pointer items-center font-semibold">Menu {{ $area==='admin'?'amministrazione':($area==='retailer'?'rivenditore':'cliente') }}</summary><nav aria-label="Sezioni riservate" class="grid gap-2 py-3 sm:grid-cols-2">
                @foreach($links as $link)
                    <div><a class="flex min-h-11 items-center font-semibold" href="{{ $href($link) }}">{{ $link['label'] }}</a>@foreach($link['children'] as $child)<a class="ml-3 flex min-h-11 items-center break-words text-sm underline" href="{{ $href($child) }}">{{ $child['label'] }}</a>@endforeach</div>
                @endforeach
            </nav></details>
        </header>
        <main id="main-content" tabindex="-1" class="mx-auto max-w-7xl px-4 pb-28 pt-6 sm:px-6 lg:px-10 lg:pb-10">{{ $slot }}</main>
    </div>
</div>
@if($area!=='admin')
    <nav aria-label="Navigazione mobile" class="fixed inset-x-0 bottom-0 z-30 grid {{ count($links)===5?'grid-cols-5':'grid-cols-4' }} gap-1 border-t border-line bg-surface px-2 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] lg:hidden">
        @foreach($links as $link)<a href="{{ $href($link) }}" class="flex min-h-14 min-w-0 flex-col items-center justify-center gap-1 rounded-xl px-1 py-2 text-center text-[10px] font-medium {{ $isActive($link)?'bg-brand-50 text-brand-700':'text-muted hover:bg-raised' }}" @if($isActive($link)) aria-current="page" @endif><x-icon :name="$link['icon']" /><span class="max-w-full break-words">{{ $link['label'] }}</span></a>@endforeach
    </nav>
@endif
<x-toast /></body></html>
