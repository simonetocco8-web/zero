<x-dynamic-component :component="auth()->user()->role->value === 'admin' ? 'layouts.admin' : 'layouts.retailer'" title="Gestisci foto">
    <h1>Gestisci foto</h1><p class="my-4 break-words">{{ $item->name }}</p>
    @if(session('status'))<x-alert>{{ session('status') }}</x-alert>@endif
    @if($errors->any())<x-alert variant="danger">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</x-alert>@endif
    <p class="my-4">Puoi eliminare, sostituire o aggiungere foto. Le modifiche agli articoli pubblicati saranno applicate dopo verifica amministrativa.</p>
    <form method="POST" enctype="multipart/form-data" action="{{ route('inventory.photos.update', $item) }}">@csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $version }}">
        <div class="grid min-w-0 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($photos as $index => $photo)
                <x-card>
                    <img class="aspect-square w-full rounded-lg object-cover" src="{{ route('inventory.image', [$item, $index, 'proposed' => $item->proposed_images !== null ? 1 : 0]) }}" alt="Foto {{ $index + 1 }} di {{ $item->name }}">
                    <label class="my-3 flex min-h-11 items-center gap-3"><input type="checkbox" name="remove[]" value="{{ $index }}">Elimina foto {{ $index + 1 }}</label>
                    <label class="field-label" for="replace-{{ $index }}">Sostituisci foto {{ $index + 1 }}</label>
                    <input class="block min-h-11 w-full min-w-0 text-sm" id="replace-{{ $index }}" type="file" name="replacements[{{ $index }}]" accept="image/jpeg,image/png,image/webp">
                </x-card>
            @empty<p>Nessuna foto caricata.</p>@endforelse
        </div>
        <label class="field-label mt-5" for="additional-photos">Aggiungi foto</label><input class="block min-h-11 w-full min-w-0 text-sm" id="additional-photos" type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp">
        <p class="my-4">JPG, PNG o WebP; massimo 15 MB per foto e {{ config('inventory.max_photos') }} foto totali.</p>
        <x-button type="submit">Salva modifiche alle foto</x-button>
    </form>
</x-dynamic-component>
