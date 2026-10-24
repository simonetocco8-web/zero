@php
    $data = $item->proposed_data ?? $item->getAttributes();
    $publication = $item->latestPublication;
    $proposedPhoto = $item->proposed_data && !empty($item->proposed_images);
    $photoPosition = $proposedPhoto ? array_key_first($item->proposed_images) : $item->images->sortBy('position')->first()?->position;
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
@endphp
<x-modal name="stock-detail-{{ $item->id }}" title="Dettaglio giacenza #{{ $item->id }}">
    @if($item->proposed_data)<p class="mb-4 text-sm text-muted">Dati proposti in verifica.</p>@endif
    @if($photoPosition !== null)
        <img src="{{ route('inventory.image', [$item, $photoPosition, 'proposed' => $proposedPhoto ? 1 : 0]) }}" alt="Immagine di {{ $data['name'] }}" loading="lazy" decoding="async" class="mb-5 max-h-56 w-full rounded-xl border border-line bg-raised object-contain">
    @else
        <p class="mb-5 rounded-xl border border-line bg-raised p-4 text-sm text-muted">Nessuna immagine disponibile</p>
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
