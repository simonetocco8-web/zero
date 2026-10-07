<x-dynamic-component :component="'layouts.'.$area" :title="$title">
    <div class="mb-7 flex flex-wrap items-start justify-between gap-4">
        <div><p class="mb-2 text-xs font-semibold uppercase tracking-widest text-brand-600">{{ $area === 'admin' ? 'Gestione piattaforma' : 'Il tuo spazio' }}</p><h1>{{ $title }}</h1><p class="mt-2 text-sm text-slate-500 sm:text-base">{{ $description }}</p></div>
        <x-badge>In preparazione</x-badge>
    </div>
    <x-card><x-empty-state :icon="$icon" title="Questo spazio sta prendendo forma" description="Qui troverai gli strumenti per gestire questa sezione. Le funzionalità saranno disponibili nelle prossime fasi.">
        @if(!request()->routeIs($area.'.dashboard'))<x-button variant="secondary" :href="route($area.'.dashboard')">Torna alla dashboard</x-button>@endif
    </x-empty-state></x-card>
    @if(request()->routeIs($area.'.dashboard'))
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach(array_slice(config('navigation.'.$area), 1) as $link)
        <a href="{{ route($link['route']) }}" class="group min-w-0 rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-emerald-300 hover:shadow-sm">
            <span class="mb-4 inline-flex rounded-xl bg-brand-50 p-3 text-brand-600"><x-icon :name="$link['icon']" /></span><div class="flex items-center justify-between gap-3"><span class="font-semibold">{{ $link['label'] }}</span><x-icon name="arrow" class="text-slate-400 group-hover:text-brand-600" /></div><p class="mt-2 text-sm text-slate-500">Esplora la sezione</p>
        </a>
        @endforeach
    </div>
    @endif
</x-dynamic-component>
