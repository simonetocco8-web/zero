@props(['vendor'])
<x-card aria-labelledby="vendor-products-title">
    <h2 id="vendor-products-title" class="flex items-center gap-2"><x-icon name="box" /> Prodotti da consegnare</h2>
    <ul class="mt-5 space-y-4">
        @foreach($vendor->items as $item)
            @php
                $tax = $item->tax_snapshot ?? [];
                $goods = $tax['gross_cents'] ?? null;
                $vatDescription = $tax['description'] ?? 'IVA da definire';
                if (in_array($tax['treatment'] ?? null, ['included', 'excluded'], true) && isset($tax['rate_basis_points']) && !str_contains($vatDescription, '%')) {
                    $vatDescription .= ' · '.str_replace('.', ',', \App\Support\DisplayMoney::percent($tax['rate_basis_points']));
                }
            @endphp
            <li><article class="min-w-0 rounded-xl border border-line bg-canvas p-4" data-vendor-product="{{ $item->id }}">
                <h3 class="break-words text-lg font-semibold">{{ $item->name }}</h3>
                <p class="mt-1 break-words text-xs text-muted">SKU {{ $item->sku ?: '—' }}</p>
                <p class="mt-4 text-xl font-semibold">{{ \App\Support\DisplayQuantity::format($item->quantity_milliunits) }} {{ $item->unit }}</p>
                <p class="mt-1 text-sm text-muted">Prezzo unitario {{ \App\Support\DisplayMoney::euros($item->unit_price_cents) }}</p>
                <dl class="mt-4 flex flex-wrap items-baseline justify-between gap-2"><dt class="text-sm">{{ $goods === null ? 'Merce (importo indicato)' : 'Totale prodotto' }}</dt><dd class="text-2xl font-bold">{{ \App\Support\DisplayMoney::euros($goods ?? $item->subtotal_cents) }}</dd></dl>
                <p class="mt-2 text-sm text-muted">{{ $vatDescription }}</p>
                @if($goods === null)<p class="mt-1 text-sm font-semibold text-muted">IVA da definire</p>@endif
                <details class="mt-4 border-t border-line pt-2" data-product-tax>
                    <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">Dettagli fiscali <x-icon name="chevron-down" /></summary>
                    @if(($tax['policy'] ?? null) === 'explicit_product_vat_v1')<x-line-tax-breakdown :line="$item" />
                    @else
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt>Imponibile</dt><dd>{{ $item->taxable_cents === null ? 'Da definire' : \App\Support\DisplayMoney::euros($item->taxable_cents) }}</dd></div>
                            <div><dt>IVA</dt><dd>{{ $item->tax_cents === null ? 'Da definire' : \App\Support\DisplayMoney::euros($item->tax_cents) }}</dd></div>
                            <div><dt>Aliquota commissione</dt><dd>{{ str_replace('.', ',', \App\Support\DisplayMoney::percent($item->commission_basis_points)) }}</dd></div>
                            <div><dt>Commissione</dt><dd>{{ $item->commission_cents === null ? 'Da definire' : \App\Support\DisplayMoney::euros($item->commission_cents) }}</dd></div>
                        </dl>
                    @endif
                </details>
            </article></li>
        @endforeach
    </ul>
</x-card>
