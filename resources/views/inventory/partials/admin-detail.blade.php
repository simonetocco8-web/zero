@php
    $comparison = app(\App\View\InventoryReviewComparison::class);
    $rows = $comparison->rows($item);
    $photosChanged = $comparison->photosChanged($item);
    $data = $item->proposed_data ?? $item->getAttributes();
    $publication = $item->latestPublication;
    $money = fn ($key) => isset($data[$key]) ? \App\Support\DisplayMoney::euros($data[$key]) : 'Non indicato';
    $yesNo = fn ($key) => !empty($data[$key]) ? 'Sì' : 'No';
    $details = [
        'ID articolo' => '#'.$item->id,
        'Nome' => $data['name'] ?? '—',
        'Rivenditore' => $item->retailer->company_name,
        'Categoria' => $data['category'] ?? '—',
        'Marca' => $data['brand'] ?? '—',
        'SKU' => $data['sku'] ?? '—',
        'EAN' => $data['ean'] ?? '—',
        'Condizione' => match($data['condition'] ?? '') { 'new' => 'Nuovo', 'end_of_line' => 'Fine serie', 'old_stock' => 'Fondo magazzino', 'damaged_packaging' => 'Imballo danneggiato', default => 'Non indicata' },
        'Provincia' => $data['province'] ?? '—',
        'Quantità' => \App\Support\DisplayQuantity::format($data['quantity_milliunits']),
        'Unità' => $data['unit'] ?? '—',
        'Prezzo di listino' => $money('list_price_cents'),
        'Prezzo Zero' => $money('zero_price_cents'),
        'Valuta' => $data['currency'] ?? $item->currency,
        'Costo spedizione' => $money('shipping_cost_cents'),
        'Ritiro disponibile' => $yesNo('pickup_available'),
        'Spedizione disponibile' => $yesNo('shipping_available'),
        'Scambio disponibile' => $yesNo('exchange_available'),
        'Trattamento IVA' => config('marketplace.vat_treatments.'.($data['vat_treatment'] ?? '')) ?: 'Da definire',
        'Aliquota IVA merce' => isset($data['vat_rate_basis_points']) ? \App\Support\DisplayMoney::percent($data['vat_rate_basis_points']) : 'Da definire / non applicabile',
        'Trattamento IVA spedizione' => config('marketplace.vat_treatments.'.($data['shipping_vat_treatment'] ?? '')) ?: 'Da definire',
        'Aliquota IVA spedizione' => isset($data['shipping_vat_rate_basis_points']) ? \App\Support\DisplayMoney::percent($data['shipping_vat_rate_basis_points']) : 'Da definire / non applicabile',
        'Data creazione' => $item->created_at->format('d/m/Y H:i'),
        'Data richiesta' => ($item->submitted_at ?? $item->created_at)->format('d/m/Y H:i'),
        'Data pubblicazione' => $item->published_at?->format('d/m/Y H:i') ?? 'Non pubblicato',
        'Ultimo aggiornamento' => $item->updated_at->format('d/m/Y H:i'),
        'ID prodotto esterno' => $item->external_product_id ?? 'Non assegnato',
        'Negozio simulato' => $publication ? match($publication->status) { 'succeeded' => 'Completato', 'failed' => 'Errore recuperabile', 'processing' => 'In elaborazione', default => 'In attesa' } : 'Non richiesto',
    ];
    foreach ($rows as $row) {
        if (in_array($row['key'], ['length_mm', 'width_mm', 'height_mm', 'weight_g', 'packaging_type', 'packing_rule', 'units_per_parcel_milliunits'], true)) { $details[$row['label']] = $row['proposed']; }
    }
@endphp
<x-modal name="stock-detail-{{ $item->id }}" title="Dettaglio giacenza #{{ $item->id }}" :wide="true">
    @if($item->proposed_data)<p class="mb-4 text-sm text-muted">Dati proposti in verifica.</p>@endif
    @if($item->proposed_data && $item->published_at)
        <section aria-label="Fotografie delle due versioni" class="mb-6">
            <h3 class="mb-3 font-semibold">{{ $photosChanged ? 'Fotografie modificate' : 'Fotografie invariate' }}</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><h4 class="mb-2 font-semibold">Foto proposte</h4><div class="grid grid-cols-2 gap-2">@forelse($item->proposed_images ?? [] as $position=>$photo)<img src="{{ route('inventory.image', [$item, $position, 'proposed' => 1]) }}" alt="Foto proposta {{ $loop->iteration }} di {{ $data['name'] }}" loading="lazy" decoding="async" class="aspect-square w-full rounded-xl object-contain">@empty<p>Nessuna immagine proposta: le foto pubblicate saranno rimosse solo dopo approvazione.</p>@endforelse</div></div>
                <div><h4 class="mb-2 font-semibold">Foto pubblicate</h4><div class="grid grid-cols-2 gap-2">@forelse($item->images->sortBy('position') as $photo)<img src="{{ route('inventory.image', [$item, $photo->position, 'proposed' => 0]) }}" alt="Foto pubblicata {{ $loop->iteration }} di {{ $item->name }}" loading="lazy" decoding="async" class="aspect-square w-full rounded-xl object-contain">@empty<p>Nessuna immagine disponibile</p>@endforelse</div></div>
            </div>
        </section>
        <section aria-label="Confronto delle versioni" class="mb-6">
            <h3 class="text-lg font-semibold">Versione pubblicata e modifiche proposte</h3>
            <p class="my-3">I campi con la dicitura «Modificato» saranno applicati soltanto dopo approvazione. In caso di rifiuto resta la versione pubblicata.</p>
            <dl class="space-y-3">@foreach($rows as $row)<div data-review-field="{{ $row['key'] }}" data-changed="{{ $row['changed'] ? 'true' : 'false' }}" class="rounded-xl border p-3 {{ $row['changed'] ? 'border-brand-600 bg-brand-50' : 'border-line' }}"><dt class="mb-2 font-semibold">{{ $row['label'] }} @if($row['changed'])<span class="ml-2 text-sm text-brand-700">Modificato</span>@endif</dt><dd class="grid min-w-0 gap-3 sm:grid-cols-2"><div class="min-w-0"><span class="text-sm text-muted">Pubblicata</span><p data-before class="whitespace-pre-wrap break-words">{{ $row['published'] }}</p></div><div class="min-w-0"><span class="text-sm text-muted">Proposta</span><p data-after class="whitespace-pre-wrap break-words font-medium">{{ $row['proposed'] }}</p></div></dd></div>@endforeach</dl>
        </section>
    @else
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3">@forelse($item->images->sortBy('position') as $photo)<img src="{{ route('inventory.image', [$item, $photo->position, 'proposed' => 0]) }}" alt="Foto {{ $loop->iteration }} di {{ $data['name'] }}" loading="lazy" decoding="async" class="aspect-square w-full rounded-xl object-contain">@empty<p>Nessuna immagine disponibile</p>@endforelse</div>
    @endif
    <dl class="grid min-w-0 gap-4 sm:grid-cols-2">
        @foreach($details as $label => $value)
            <div class="min-w-0"><dt class="text-sm text-muted">{{ $label }}</dt><dd class="break-words">{{ $value }}</dd></div>
        @endforeach
        <div><dt class="text-sm text-muted">Stato</dt><dd><x-inventory-status :item="$item" /></dd></div>
    </dl>
    <p class="mt-4">Foto presenti: {{ count($item->proposed_images ?? $item->images) }}</p>
    <dl class="mt-5 space-y-4">
        <div><dt class="font-semibold">Descrizione completa</dt><dd class="whitespace-pre-wrap break-words">{{ $data['description'] ?? 'Non indicata' }}</dd></div>
        <div><dt class="font-semibold">Note di consegna</dt><dd class="whitespace-pre-wrap break-words">{{ $data['delivery_notes'] ?? 'Non indicate' }}</dd></div>
        @if($item->rejection_reason)<div><dt class="font-semibold">Motivo di rifiuto</dt><dd class="whitespace-pre-wrap break-words">{{ $item->rejection_reason }}</dd></div>@endif
    </dl>
    <div class="mt-6 flex flex-wrap gap-3 border-t border-line pt-4">
        @if($item->status->needsReview()) @can('review', $item)
            <x-button type="button" x-on:click="$dispatch('open-modal', 'stock-approve-{{ $item->id }}')"><x-icon name="check-circle" /> APPROVA E PUBBLICA</x-button>
            <x-button type="button" variant="danger" x-on:click="$dispatch('open-modal', 'stock-reject-{{ $item->id }}')"><x-icon name="x-circle" /> RIFIUTA</x-button>
        @endcan @endif
        @can('managePhotos', $item)<x-button variant="secondary" :href="route('inventory.photos.edit', $item)">Gestisci foto</x-button>@endcan
        @can('delete', $item)<x-button type="button" variant="danger" x-on:click="$dispatch('open-modal', 'delete-item-{{ $item->id }}')">Elimina articolo</x-button>@endcan
        <x-button type="button" variant="secondary" x-on:click="close()">Chiudi</x-button>
    </div>
</x-modal>
@can('delete', $item)
    <x-modal name="delete-item-{{ $item->id }}" title="Elimina articolo" :show="old('confirmation_id') === 'delete-item-'.$item->id && $errors->any()">
        @include('components.admin-confirm-form', ['name' => 'delete-item-'.$item->id, 'url' => route('inventory.destroy', $item), 'method' => 'DELETE', 'variant' => 'danger', 'decision' => null, 'reason' => false, 'reference' => false, 'hasErrors' => false, 'message' => 'Vuoi eliminare l’articolo «'.$item->name.'»? Non sarà più disponibile nel marketplace o nelle tue liste. Ordini, scambi e storico saranno conservati. Le prenotazioni attive impediscono l’eliminazione.'])
    </x-modal>
@endcan
