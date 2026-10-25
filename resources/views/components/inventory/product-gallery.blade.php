@props(['urls', 'name', 'label'])
<x-card x-data="{ active: 0 }" class="space-y-4">
    <h2 class="text-lg font-semibold">{{ $label }}</h2>
    <div class="relative aspect-[4/3] overflow-hidden rounded-xl bg-raised">
        @forelse($urls as $index => $url)
            <img src="{{ $url }}" alt="{{ $name }} — foto {{ $index + 1 }} di {{ count($urls) }}" class="absolute inset-0 h-full w-full object-contain p-3" x-show="active === {{ $index }}" @if($index) x-cloak loading="lazy" @endif decoding="async">
        @empty
            <div class="flex h-full flex-col items-center justify-center gap-3 text-muted"><x-icon name="photos" /><p>Nessuna foto disponibile</p></div>
        @endforelse
    </div>
    @if(count($urls) > 1)
        <div class="grid grid-cols-4 gap-2 sm:grid-cols-5" aria-label="Selezione foto">
            @foreach($urls as $index => $url)
                <button type="button" class="min-h-11 min-w-11 overflow-hidden rounded-lg border-2 border-line focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700" x-on:click="active = {{ $index }}" aria-label="Mostra foto {{ $index + 1 }} di {{ count($urls) }}" aria-pressed="{{ $index === 0 ? 'true' : 'false' }}" x-bind:aria-pressed="active === {{ $index }}" x-bind:class="active === {{ $index }} ? 'ring-2 ring-brand-700' : ''"><img src="{{ $url }}" alt="" loading="lazy" class="aspect-square w-full object-contain"></button>
            @endforeach
        </div>
    @endif
</x-card>
