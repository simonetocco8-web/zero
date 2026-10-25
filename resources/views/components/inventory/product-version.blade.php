@props(['item', 'label'])
<x-card class="space-y-4">
    <h3 class="text-lg font-semibold">{{ $label }}</h3>
    <dl class="space-y-3 break-words">
        <div><dt class="text-sm text-muted">Nome</dt><dd class="font-semibold">{{ $item->name }}</dd></div>
        <div><dt class="text-sm text-muted">Prezzo ZeroMagazzino</dt><dd>{{ \App\Support\DisplayMoney::euros($item->zero_price_cents) }}</dd></div>
        <div><dt class="text-sm text-muted">Quantità</dt><dd>{{ \App\Support\DisplayQuantity::format($item->quantity_milliunits) }} {{ $item->unit }}</dd></div>
        <div><dt class="text-sm text-muted">Consegna</dt><dd>{{ $item->pickup_available ? 'Ritiro disponibile' : 'Ritiro non disponibile' }} · {{ $item->shipping_available ? 'Spedizione disponibile' : 'Spedizione non disponibile' }}</dd></div>
        <div><dt class="text-sm text-muted">Costo spedizione</dt><dd>{{ $item->shipping_cost_cents === null ? 'Da definire' : \App\Support\DisplayMoney::euros($item->shipping_cost_cents) }}</dd></div>
        <div><dt class="text-sm text-muted">IVA merce</dt><dd>{{ $item->vatLabel() }}</dd></div>
        <div><dt class="text-sm text-muted">IVA spedizione</dt><dd>{{ $item->shippingVatLabel() }}</dd></div>
        <div><dt class="text-sm text-muted">Scambio</dt><dd>{{ $item->exchange_available ? 'Disponibile per scambio' : 'Scambio non disponibile' }}</dd></div>
        @if($item->delivery_notes)<div><dt class="text-sm text-muted">Note consegna</dt><dd class="whitespace-pre-wrap">{{ $item->delivery_notes }}</dd></div>@endif
    </dl>
    <details><summary class="flex min-h-11 cursor-pointer items-center font-semibold focus-visible:outline-2">Descrizione completa</summary><p class="mt-3 max-w-prose whitespace-pre-wrap break-words">{{ $item->description ?: 'Nessuna descrizione inserita.' }}</p></details>
</x-card>
