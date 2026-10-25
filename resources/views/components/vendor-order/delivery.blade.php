@props(['vendor'])
@php
    $address = $vendor->address_snapshot;
    $taxes = $vendor->items->map(fn($item) => $item->tax_snapshot['shipping_tax'] ?? null)->filter(fn($tax) => is_array($tax))->unique(fn($tax) => ($tax['treatment'] ?? '').':'.($tax['rate_basis_points'] ?? 'unknown'));
@endphp
<x-card aria-labelledby="vendor-delivery-title">
    <h2 id="vendor-delivery-title" class="flex items-center gap-2"><x-icon :name="$vendor->delivery_mode === 'shipping' ? 'box' : 'building'" /> Consegna</h2>
    <p class="mt-4 font-bold uppercase tracking-wide text-brand-700">{{ $vendor->delivery_mode === 'shipping' ? 'Spedizione' : 'Ritiro presso il rivenditore' }}</p>
    @if($vendor->delivery_mode === 'shipping')
        @if($address)
            <h3 class="mt-5 text-sm font-semibold text-muted">Destinatario</h3>
            <address class="mt-1 break-words not-italic leading-relaxed">
                <span class="font-semibold">{{ $address['recipient_name'] }}</span><br>
                @if(!empty($address['company'])){{ $address['company'] }}<br>@endif
                {{ $address['address_line_1'] }}<br>
                @if(!empty($address['address_line_2'])){{ $address['address_line_2'] }}<br>@endif
                {{ $address['postal_code'] }} {{ $address['city'] }} ({{ $address['province'] }}) {{ $address['country_code'] }}
            </address>
        @else<p class="mt-4 text-sm text-muted">Indirizzo non disponibile nello storico.</p>@endif
        <dl class="mt-5 space-y-3 text-sm">
            <div class="flex flex-wrap justify-between gap-2"><dt>Costo spedizione con imposte</dt><dd class="font-semibold">{{ \App\Support\DisplayMoney::euros($vendor->shipping_cents) }}</dd></div>
            <div><dt class="text-muted">IVA spedizione</dt><dd class="mt-1">@forelse($taxes as $tax)<p>{{ $tax['description'] ?? 'IVA da definire' }}@if(in_array($tax['treatment'] ?? null, ['included', 'excluded'], true) && isset($tax['rate_basis_points'])) · {{ str_replace('.', ',', \App\Support\DisplayMoney::percent($tax['rate_basis_points'])) }}@endif</p>@empty Da definire nello storico @endforelse</dd></div>
        </dl>
    @else
        <p class="mt-4 break-words">{{ $vendor->retailer_snapshot['name'] }}@if(!empty($vendor->retailer_snapshot['city'])) · {{ $vendor->retailer_snapshot['city'] }}@endif</p>
    @endif
</x-card>
@if($vendor->carrier || $vendor->tracking_reference)
<x-card aria-labelledby="vendor-tracking-title" x-data="{ copied: false, copyFailed: false, async copyTracking() { try { await navigator.clipboard.writeText(this.$refs.tracking.textContent.trim()); this.copied = true; this.copyFailed = false; } catch { this.copyFailed = true; this.copied = false; } } }">
    <h2 id="vendor-tracking-title" class="flex items-center gap-2"><x-icon name="document" /> Spedizione e tracking</h2>
    <dl class="mt-4 space-y-4">
        @if($vendor->carrier)<div><dt class="text-sm text-muted">Corriere</dt><dd class="mt-1 break-words text-lg font-semibold">{{ $vendor->carrier }}</dd></div>@endif
        @if($vendor->tracking_reference)<div><dt class="text-sm text-muted">Codice tracking</dt><dd class="mt-2 flex min-w-0 flex-wrap items-center justify-between gap-3"><span class="min-w-0 break-all font-mono font-semibold" x-ref="tracking">{{ $vendor->tracking_reference }}</span><x-button variant="secondary" type="button" x-on:click="copyTracking()">Copia</x-button></dd></div>@endif
    </dl>
    <p x-cloak x-show="copied" role="status" class="mt-3 text-sm text-muted">Codice tracking copiato.</p>
    <p x-cloak x-show="copyFailed" role="status" class="mt-3 text-sm text-muted">Copia non disponibile: seleziona il codice tracking per copiarlo.</p>
</x-card>
@endif
