<x-dynamic-component :component="auth()->user()->role->value === 'admin' ? 'layouts.admin' : 'layouts.retailer'" title="Gestisci foto">
    <p class="brand-eyebrow">Gestione articolo</p>
    <h1 class="mt-2">Gestisci foto</h1><p class="my-4 break-words text-muted">{{ $item->name }}</p>
    @if(session('status'))<x-alert class="mb-4">{{ session('status') }}</x-alert>@endif
    @if($errors->any())<x-alert variant="danger">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</x-alert>@endif
    <p class="my-4 text-muted">Puoi eliminare, sostituire o aggiungere foto. Le modifiche agli articoli pubblicati saranno applicate dopo verifica amministrativa.</p>
    <p id="cover-help" class="mb-6 text-sm text-muted">La prima foto è la copertina. Se la elimini, la foto successiva diventa la nuova copertina. Puoi anche lasciare l’articolo senza immagini.</p>
    <form method="POST" enctype="multipart/form-data" action="{{ route('inventory.photos.update', $item) }}" aria-describedby="cover-help photos-help">@csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $version }}">
        <div class="grid min-w-0 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($photos as $index => $photo)
                <x-card x-data="{ remove: false }">
                    <div class="mb-3 flex items-center justify-between gap-2"><h2 class="text-base">Foto {{ $index + 1 }}</h2>@if($index === 0)<x-badge>Copertina</x-badge>@endif</div>
                    <img class="aspect-square w-full rounded-lg object-cover" src="{{ route('inventory.image', [$item, $index, 'proposed' => $item->proposed_images !== null ? 1 : 0]) }}" alt="Foto {{ $index + 1 }} di {{ $item->name }}">
                    <label class="my-3 flex min-h-11 items-center gap-3"><input type="checkbox" class="rounded" name="remove[]" value="{{ $index }}" x-model="remove">Elimina foto {{ $index + 1 }}</label>
                    <label class="field-label" for="replace-{{ $index }}">Sostituisci foto {{ $index + 1 }}</label>
                    <input class="block min-h-11 w-full min-w-0 text-sm disabled:opacity-50" id="replace-{{ $index }}" type="file" name="replacements[{{ $index }}]" accept="image/jpeg,image/png,image/webp" x-bind:disabled="remove" aria-describedby="photos-help">
                    <p class="mt-3 text-sm text-muted" x-show="remove" x-cloak>La foto sarà rimossa al salvataggio.</p>
                </x-card>
            @empty
                <x-empty-state class="sm:col-span-2 lg:col-span-3" title="Nessuna foto caricata" description="L’articolo userà un’immagine segnaposto. Aggiungi una foto per renderlo più riconoscibile." />
            @endforelse
        </div>
        <x-card class="mt-6">
            <label class="field-label" for="additional-photos">Aggiungi foto</label><input class="block min-h-11 w-full min-w-0 text-sm" id="additional-photos" type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" aria-describedby="photos-help">
            <p id="photos-help" class="my-4 text-sm text-muted">JPG, PNG o WebP; massimo 15 MB per foto e {{ config('inventory.max_photos') }} foto totali. Le modifiche vengono applicate con Salva.</p>
            <x-button type="submit">Salva modifiche alle foto</x-button>
        </x-card>
    </form>
</x-dynamic-component>
