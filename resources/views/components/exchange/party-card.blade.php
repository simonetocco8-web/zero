@props(['version', 'side', 'label', 'historical' => false])
@php
    $party = $version->parties_snapshot[$side];
    $delivery = $side === 'a' ? $version->delivery_a : $version->delivery_b;
    $heading = $historical ? 'h4' : 'h3';
    $productHeading = $historical ? 'h5' : 'h4';
@endphp
<x-card data-exchange-side="{{ $side }}" data-party-label="{{ $label }}" class="h-full">
    <p class="text-sm font-bold uppercase tracking-wide text-brand-700">{{ $label }}</p>
    <{{ $heading }} class="mt-2 break-words text-lg font-semibold">{{ $party['name'] }}</{{ $heading }}>
    <ul class="mt-5 space-y-4">
        @foreach($version->lines->where('side', $side) as $line)
            <li><article class="min-w-0 rounded-xl border border-line bg-canvas p-4">
                <{{ $productHeading }} class="break-words font-semibold">{{ $line->product_snapshot['name'] }}</{{ $productHeading }}>
                <p class="mt-1 break-words text-xs text-muted">SKU: {{ $line->product_snapshot['sku'] ?: '—' }}</p>
                <p class="mt-4 text-lg font-semibold">{{ \App\Support\DisplayQuantity::format($line->quantity_milliunits) }} {{ $line->product_snapshot['unit'] }}</p>
                <p class="text-sm text-muted">Valore unitario {{ \App\Support\DisplayMoney::euros($line->agreed_unit_value_cents) }}</p>
                <dl class="mt-4 flex flex-wrap items-baseline justify-between gap-2"><dt class="text-sm">Totale merce</dt><dd class="text-xl font-bold">{{ \App\Support\DisplayMoney::euros($line->goods_value_cents) }}</dd></dl>
                <p class="mt-2 text-sm text-muted">{{ config('marketplace.vat_treatments.'.($line->product_snapshot['vat_treatment'] ?? '')) ?? 'IVA da definire' }}</p>
            </article></li>
        @endforeach
    </ul>
    <section class="mt-6 border-t border-line pt-5" aria-label="Consegna {{ $label }}">
        <p class="flex items-center gap-2 font-semibold"><x-icon :name="$delivery['mode'] === 'pickup' ? 'building' : 'box'" /> Consegna</p>
        <p class="mt-2 font-medium">{{ $delivery['mode'] === 'pickup' ? 'Ritiro presso il rivenditore' : 'Spedizione' }}</p>
        <dl class="mt-3 space-y-3 text-sm">
            <div><dt class="text-muted">{{ $delivery['mode'] === 'pickup' ? 'Luogo di ritiro' : 'Destinazione' }}</dt><dd class="break-words">{{ $delivery['destination'] }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt class="text-muted">Trasporto separato</dt><dd class="font-semibold">{{ \App\Support\DisplayMoney::euros($delivery['shipping_cost_cents']) }}</dd></div>
            <div><dt class="text-muted">Pagato da</dt><dd class="break-words">{{ isset($delivery['payer_side']) ? $version->parties_snapshot[$delivery['payer_side']]['name'] : 'Pagatore non definito nello storico' }}</dd></div>
        </dl>
    </section>
</x-card>
