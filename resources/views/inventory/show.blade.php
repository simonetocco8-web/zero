@php
    $publiclyAvailable = app(\App\Services\PublicInventory::class)->isAvailable($item);
    $hasProposal = $item->proposed_data !== null || $item->proposed_images !== null;
    $approvedPhotos = $item->images->sortBy('position')->map(fn ($photo) => route('inventory.image', [$item, $photo->position]))->values()->all();
    $proposedPhotos = collect($item->proposed_images ?? [])->map(fn ($photo, $position) => route('inventory.image', [$item, $position, 'proposed' => 1]))->values()->all();
    $proposal = clone $item;
    $proposal->fill($item->proposed_data ?? []);
    $availableQuantity = $item->available_quantity_milliunits;
@endphp
<x-layouts.retailer title="Giacenza">
<div class="mx-auto max-w-6xl space-y-6">
    <section aria-label="Testata prodotto" class="space-y-4">
        <div class="flex flex-col items-start justify-between gap-4 sm:flex-row">
            <div class="min-w-0 w-full sm:w-auto sm:flex-1"><p class="text-sm font-semibold uppercase tracking-wider text-muted">Prodotto</p><h1 class="mt-2 break-words">{{ $item->name }}</h1><p class="mt-2 break-words text-muted">{{ $item->brand ?: 'Marca non indicata' }} · {{ $item->categoryRecord?->name ?? $item->category }}</p><p class="mt-1 break-words text-sm text-muted">SKU {{ $item->sku ?: '—' }} · EAN {{ $item->ean ?: '—' }}</p></div>
            <x-inventory-status :item="$item" />
        </div>
        <div class="flex flex-wrap gap-3">
            @can('update', $item)<x-button :href="route('retailer.stock.edit', $item)"><x-icon name="edit" /> Modifica prodotto</x-button>@endcan
            @can('managePhotos', $item)<x-button variant="secondary" :href="route('inventory.photos.edit', $item)"><x-icon name="photos" /> Gestisci foto</x-button>@endcan
            @can('viewPublic', $item)<x-button variant="secondary" :href="route('marketplace.show', $item)"><x-icon name="eye" /> Vedi nel marketplace</x-button>@endcan
        </div>
    </section>
    @if(session('status'))<x-alert variant="success">{{ session('status') }}</x-alert>@endif
    @if($hasProposal)<x-alert title="Modifica in verifica">Il marketplace continua a mostrare la versione approvata. Le modifiche sotto saranno pubblicate dopo l'approvazione amministrativa. <a href="#proposed-changes" class="inline-flex min-h-11 items-center underline">Consulta le modifiche proposte</a></x-alert>@endif
    @if($item->rejection_reason)<x-alert variant="danger" title="Motivo di rifiuto"><p class="whitespace-pre-wrap break-words">{{ $item->rejection_reason }}</p>@can('update', $item)<x-button class="mt-3" variant="secondary" :href="route('retailer.stock.edit', $item)">Correggi prodotto</x-button>@endcan</x-alert>@endif
    <div class="grid items-start gap-6 lg:grid-cols-2">
        <x-inventory.product-gallery :urls="$approvedPhotos" :name="$item->name" :label="$publiclyAvailable ? 'Foto attualmente pubblicate' : 'Foto del prodotto'" />
        <x-card class="space-y-6">
            <h2 class="text-lg font-semibold">Dati commerciali</h2>
            <div><p class="text-sm text-muted">Prezzo ZeroMagazzino</p><p class="mt-1 break-words text-4xl font-bold tracking-tight">{{ \App\Support\DisplayMoney::euros($item->zero_price_cents) }}</p>@if($item->list_price_cents !== null)<p class="mt-2 text-sm text-muted">Prezzo di listino: {{ \App\Support\DisplayMoney::euros($item->list_price_cents) }}</p>@endif</div>
            <dl class="grid gap-4 sm:grid-cols-2"><div><dt class="text-sm text-muted">Quantità disponibile</dt><dd class="mt-1 break-words text-2xl font-semibold">{{ \App\Support\DisplayQuantity::format($availableQuantity) }} {{ $item->unit }}</dd>@if($availableQuantity !== $item->quantity_milliunits)<p class="text-sm text-muted">Quantità registrata: {{ \App\Support\DisplayQuantity::format($item->quantity_milliunits) }} {{ $item->unit }}</p>@endif</div><div><dt class="text-sm text-muted">Condizione</dt><dd class="mt-1 font-semibold">{{ match($item->condition->value){'new'=>'Nuovo','end_of_line'=>'Fine serie','old_stock'=>'Fondo magazzino',default=>'Imballo danneggiato'} }}</dd></div></dl>
            <div class="border-t border-line pt-5"><h3 class="font-semibold">Disponibilità</h3><ul class="mt-3 space-y-3">@foreach([[$item->pickup_available, 'Ritiro in sede', 'Ritiro non disponibile'], [$item->shipping_available, 'Spedizione disponibile', 'Spedizione non disponibile'], [$item->exchange_available, 'Disponibile per scambio', 'Scambio non disponibile']] as [$enabled, $yes, $no])<li class="flex items-center gap-2"><x-icon :name="$enabled ? 'check-circle' : 'x-circle'" /><span>{{ $enabled ? $yes : $no }}</span></li>@endforeach</ul></div>
        </x-card>
    </div>
    <div class="grid gap-6 md:grid-cols-2">
        <x-card><h2 class="mb-4 text-lg font-semibold">Consegna</h2><dl class="space-y-3"><div><dt class="text-sm text-muted">Ritiro in sede</dt><dd>{{ $item->pickup_available ? 'Disponibile' : 'Non disponibile' }}</dd></div><div><dt class="text-sm text-muted">Spedizione</dt><dd>{{ $item->shipping_available ? 'Disponibile' : 'Non disponibile' }}</dd></div><div><dt class="text-sm text-muted">Costo spedizione</dt><dd class="font-semibold">{{ $item->shipping_cost_cents === null ? 'Da definire' : \App\Support\DisplayMoney::euros($item->shipping_cost_cents) }}</dd></div><div><dt class="text-sm text-muted">IVA spedizione</dt><dd>{{ $item->shippingVatLabel() }}</dd></div>@if($item->delivery_notes)<div><dt class="text-sm text-muted">Note consegna</dt><dd class="whitespace-pre-wrap break-words">{{ $item->delivery_notes }}</dd></div>@endif</dl></x-card>
        <x-card><h2 class="mb-4 text-lg font-semibold">Dati fiscali</h2><dl class="space-y-4"><div><dt class="text-sm text-muted">Trattamento IVA merce</dt><dd>{{ $item->vatLabel() }}</dd></div><div><dt class="text-sm text-muted">Trattamento IVA spedizione</dt><dd>{{ $item->shippingVatLabel() }}</dd></div></dl></x-card>
    </div>
    <x-card><h2 class="mb-4 text-lg font-semibold">Codici prodotto</h2><dl class="grid gap-4 break-words sm:grid-cols-2 lg:grid-cols-3">@foreach(['SKU'=>$item->sku,'EAN'=>$item->ean,'Marca'=>$item->brand,'Categoria'=>$item->categoryRecord?->name ?? $item->category,'Provincia'=>$item->province] as $label=>$value)<div class="min-w-0"><dt class="text-sm text-muted">{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd></div>@endforeach</dl></x-card>
    <x-card><h2 class="mb-4 text-lg font-semibold">Descrizione prodotto</h2><p class="max-w-prose whitespace-pre-wrap break-words">{{ $item->description ?: 'Nessuna descrizione inserita.' }}</p></x-card>
    <x-card><h2 class="mb-4 text-lg font-semibold">Pubblicazione</h2><dl class="grid gap-4 sm:grid-cols-3"><div><dt class="mb-1 text-sm text-muted">Stato</dt><dd><x-inventory-status :item="$item" /></dd></div><div><dt class="text-sm text-muted">Marketplace</dt><dd>{{ $publiclyAvailable ? 'Visibile' : 'Non visibile' }}</dd></div>@if($item->published_at)<div><dt class="text-sm text-muted">Data pubblicazione</dt><dd>{{ $item->published_at->format('d/m/Y H:i') }}</dd></div>@endif</dl></x-card>
    @if($hasProposal)
        <section id="proposed-changes" class="space-y-4 border-t border-line pt-6"><h2 class="text-xl font-semibold">Modifiche proposte</h2><p class="text-muted">Proposta in verifica: i dati approvati restano invariati fino alla decisione amministrativa.</p><div class="grid items-start gap-6 md:grid-cols-2"><x-inventory.product-version :item="$item" :label="$publiclyAvailable ? 'Versione pubblicata' : 'Versione attuale'" /><x-inventory.product-version :item="$proposal" label="Versione proposta" /></div>
        @if($item->proposed_images !== null)<x-inventory.product-gallery :urls="$proposedPhotos" :name="$proposal->name" label="Nuove foto proposte" />@else<p class="text-sm text-muted">Nessuna modifica alle foto proposta. Le foto attuali restano quelle mostrate sopra.</p>@endif</section>
    @endif
    @can('viewPublic', $item)<x-card><h2 class="mb-3 text-lg font-semibold">Altre azioni</h2><x-button variant="secondary" :href="route('availability.create', $item)">Apri richiesta pubblica</x-button></x-card>@endcan
    @if(auth()->user()->can('delete', $item) || auth()->user()->can('archive', $item))
        <x-card class="border-danger"><h2 class="text-lg font-semibold">Zona pericolosa</h2><p class="mt-2 text-sm text-muted">Conferma l'operazione prima di rimuovere il prodotto dalle tue giacenze.</p><div class="mt-4 flex flex-wrap gap-3">
        @can('archive', $item)<x-admin-confirm :name="'archive-detail-'.$item->id" label="Archivia" :url="route('retailer.stock.archive', $item)" variant="danger" />@endcan
        @can('delete', $item)<x-admin-confirm :name="'delete-detail-'.$item->id" label="Elimina articolo" :url="route('inventory.destroy', $item)" method="DELETE" variant="danger" :message="'Vuoi eliminare l’articolo «'.$item->name.'»? Non sarà più disponibile nel marketplace o nelle tue liste. Ordini, scambi e storico saranno conservati. Le prenotazioni attive impediscono l’eliminazione.'" />@endcan
        </div></x-card>
    @endif
</div>
</x-layouts.retailer>
