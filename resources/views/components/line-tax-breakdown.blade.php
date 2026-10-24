@props(['line'])
@php
    $tax = $line['tax_snapshot'];
    $money = fn ($amount) => $amount === null ? 'IVA da definire' : \App\Support\DisplayMoney::euros($amount);
@endphp
@if(($tax['policy'] ?? null) === 'explicit_product_vat_v1')
<dl class="mt-3 grid min-w-0 gap-2 text-sm sm:grid-cols-2">
    <div><dt>Imponibile merce</dt><dd>{{ $money($tax['taxable_cents']) }}</dd></div>
    <div><dt>IVA merce · {{ $tax['description'] }}</dt><dd>{{ $money($tax['tax_cents']) }}</dd></div>
    <div><dt>Prezzo merce con imposte</dt><dd>{{ $money($tax['gross_cents']) }}</dd></div>
    <div><dt>Imponibile spedizione</dt><dd>{{ $money($tax['shipping_tax']['taxable_cents']) }}</dd></div>
    <div><dt>IVA spedizione · {{ $tax['shipping_tax']['description'] }}@if($tax['shipping_tax']['rate_basis_points'] !== null) · {{ \App\Support\DisplayMoney::percent($tax['shipping_tax']['rate_basis_points']) }}@endif</dt><dd>{{ $money($tax['shipping_tax']['tax_cents']) }}</dd></div>
    <div><dt>Spedizione con imposte</dt><dd>{{ $money($tax['shipping_tax']['gross_cents']) }}</dd></div>
    <div><dt>Base commissione (solo imponibile merce)</dt><dd>{{ $money($tax['commission_base_cents']) }}</dd></div>
    <div><dt>Commissione ZeroMagazzino · {{ \App\Support\DisplayMoney::percent($line['commission_basis_points']) }}</dt><dd>{{ $money($line['commission_cents']) }}</dd></div>
</dl>
<p class="mt-2 text-sm text-muted">Commissione a carico del rivenditore, non aggiunta al totale cliente. IVA del servizio ZeroMagazzino separata.</p>
@endif
