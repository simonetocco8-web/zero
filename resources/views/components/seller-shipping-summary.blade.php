@props(['group', 'returnTo' => 'cart', 'calculator' => true])
<div class="space-y-4" data-seller-summary="{{ $group['retailer_id'] }}">
    <p><strong>Consegna:</strong> {{ match($group['mode']) { 'pickup'=>'Ritiro presso il rivenditore', 'shipping'=>'Spedizione', default=>'Scegli una modalità unica per il rivenditore' } }}</p>
    @if($group['selected'])
        <div class="rounded-xl border border-line p-4"><p class="font-semibold">{{ $group['selected']->rate['carrier'] }} · {{ $group['selected']->rate['service'] }}</p><p class="mt-1">Costo indicativo: {{ \App\Support\DisplayMoney::euros($group['shipping_estimate_cents']) }}</p><p class="mt-2 text-sm text-muted">Destinazione: {{ $group['destination']['address_line_1'] }} · {{ $group['destination']['postal_code'] }} {{ $group['destination']['city'] }}. Valida fino alle {{ $group['selected']->expires_at->format('H:i') }}; da riconfermare prima dell’acquisto.</p></div>
    @elseif($group['mode'] === 'pickup')<p>Ritiro senza costi di spedizione.</p>
    @elseif($group['shipping_missing'])<p>Spedizione da calcolare. Se hai cambiato prodotti, quantità o indirizzo, richiedi una nuova tariffa.</p>@endif
    <x-purchase-summary :data="$group" title="Totale parziale rivenditore" />
    @if($calculator && $group['can_calculate'])<x-shipping-calculator :retailer-id="$group['retailer_id']" :lines="$group['lines']" :label="$group['selected'] ? 'Modifica spedizione' : 'Calcola spedizione'" :return-to="$returnTo" />@endif
</div>
