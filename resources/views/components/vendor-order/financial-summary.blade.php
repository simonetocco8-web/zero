@props(['vendor'])
@php
    $flow = $vendor->funds_flow_snapshot ?? [];
    $money = fn($amount) => $amount === null ? 'Da definire' : \App\Support\DisplayMoney::euros($amount);
@endphp
<x-card aria-labelledby="vendor-financial-title">
    <h2 id="vendor-financial-title">Riepilogo vendita</h2>
    <dl class="mt-5 space-y-3 text-sm">
        <div class="flex flex-wrap justify-between gap-2"><dt>Prodotti (prezzi indicati)</dt><dd class="font-semibold">{{ \App\Support\DisplayMoney::euros($vendor->subtotal_cents) }}</dd></div>
        <div class="flex flex-wrap justify-between gap-2"><dt>{{ $vendor->delivery_mode === 'shipping' ? 'Spedizione con imposte' : 'Trasporto' }}</dt><dd class="font-semibold">{{ \App\Support\DisplayMoney::euros($vendor->shipping_cents) }}</dd></div>
        <div class="border-t border-line pt-4"><dt class="font-semibold">{{ $vendor->totals_provisional ? 'Totale cliente provvisorio' : 'Totale cliente' }}</dt><dd class="mt-2 break-words text-3xl font-bold text-brand-700" data-customer-total>{{ \App\Support\DisplayMoney::euros($vendor->total_cents) }}</dd></div>
    </dl>
    @if($vendor->totals_provisional)<x-alert class="mt-4" title="IVA da definire">I totali fiscali dello snapshot non sono ancora definitivi.</x-alert>@endif
    <details class="mt-5 border-t border-line pt-2" data-vendor-tax>
        <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">Dettaglio fiscale <x-icon name="chevron-down" /></summary>
        <dl class="mt-3 space-y-3 text-sm">
            <div class="flex flex-wrap justify-between gap-2"><dt>Imponibile complessivo</dt><dd>{{ $money($vendor->taxable_cents) }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>IVA complessiva</dt><dd>{{ $vendor->totals_provisional ? 'Da definire' : $money($vendor->tax_cents) }}</dd></div>
        </dl>
        <p class="mt-3 text-sm text-muted">I prezzi indicati dei prodotti mantengono il trattamento IVA concordato; il totale cliente comprende le imposte determinate nello snapshot.</p>
    </details>
    @if($vendor->refund)<div class="mt-4"><x-alert title="Rimborso registrato">{{ $vendor->refund->simulated ? 'Rimborso simulato' : 'Rimborso' }}: {{ \App\Support\DisplayMoney::euros($vendor->refund->amount_cents) }}.</x-alert></div>@endif
</x-card>
<x-card aria-labelledby="vendor-commission-title" data-vendor-commission>
    <h2 id="vendor-commission-title" class="text-lg">Commissione ZeroMagazzino</h2>
    <dl class="mt-4 space-y-3 text-sm">
        <div class="flex flex-wrap justify-between gap-2"><dt>Base commissionabile</dt><dd class="font-semibold">{{ $money($flow['commission_base_cents'] ?? null) }}</dd></div>
        <div class="flex flex-wrap justify-between gap-2"><dt>Aliquota @if(!empty($flow['plan_code'])) {{ strtoupper($flow['plan_code']) }}@endif</dt><dd class="font-semibold">{{ isset($flow['commission_basis_points']) ? str_replace('.', ',', \App\Support\DisplayMoney::percent($flow['commission_basis_points'])) : 'Da definire' }}</dd></div>
        <div class="flex flex-wrap justify-between gap-2 border-t border-line pt-3"><dt>Commissione</dt><dd class="font-bold">{{ $money($flow['commission_cents'] ?? null) }}</dd></div>
    </dl>
    <p class="mt-4 text-sm text-muted">La commissione è a carico del rivenditore e non modifica il totale pagato dal cliente.</p>
    @if(isset($flow['commission_tax']))<details class="mt-3 border-t border-line pt-2"><summary class="flex min-h-11 cursor-pointer items-center justify-between gap-2 text-sm font-semibold">IVA del servizio ZeroMagazzino <x-icon name="chevron-down" /></summary><dl class="mt-3 space-y-2 text-sm"><div><dt>IVA commissione</dt><dd>{{ $money($flow['commission_tax']['tax_cents'] ?? null) }}</dd></div><div><dt>Commissione con IVA del servizio</dt><dd>{{ $money($flow['commission_tax']['gross_cents'] ?? null) }}</dd></div></dl></details>@endif
</x-card>
