@props(['version'])
<details class="rounded-xl border border-line bg-surface p-4">
    <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">Dettagli economici e fiscali <x-icon name="chevron-down" /></summary>
    <div class="mt-4 space-y-3 text-sm leading-relaxed">
        <p>Valore A {{ \App\Support\DisplayMoney::euros($version->value_a_cents) }} · Valore B {{ \App\Support\DisplayMoney::euros($version->value_b_cents) }} · Differenza {{ \App\Support\DisplayMoney::euros($version->difference_cents) }}</p>
        <p>Il conguaglio considera i debiti reciproci concordati. Le due cessioni, i trasporti e la commissione restano distinti; il trasporto pagato al vettore resta esterno al conguaglio.</p>
        @if(($version->commission_snapshot['policy'] ?? null) === 'approved_difference_v1')
            <p>Commissione sul solo conguaglio: {{ \App\Support\DisplayMoney::euros($version->commission_snapshot['amount_cents']) }} ({{ \App\Support\DisplayMoney::percent($version->commission_snapshot['basis_points']) }}), trattenuta al destinatario. Netto prima di costi PSP e fiscalità commissione: {{ \App\Support\DisplayMoney::euros($version->commission_snapshot['recipient_net_cents']) }}.</p>
        @else
            <p>Snapshot storico: commissione scambio non definita.</p>
        @endif
        @foreach(['a' => $version->delivery_a, 'b' => $version->delivery_b] as $side => $terms)
            <p>Trasporto {{ strtoupper($side) }} → {{ $side === 'a' ? 'B' : 'A' }}: {{ isset($terms['payer_side']) ? 'paga '.strtoupper($terms['payer_side']) : 'pagatore non definito nello storico' }}; {{ match($terms['settlement'] ?? null) { 'sender' => 'rimborso al mittente', 'external' => 'pagamento esterno al vettore', default => 'pagamento trasporto non definito nello storico' } }}.</p>
        @endforeach
        <p>Ciascun rivenditore documenta la propria cessione; ZeroMagazzino documenta il proprio servizio.</p>
        @if($version->notes)<p class="whitespace-pre-line break-words"><strong>Note della versione</strong><br>{{ $version->notes }}</p>@endif
        @if($version->payment && $version->payment->simulated)
            <p>Conguaglio fake: {{ match($version->payment->status) { 'payment_pending' => 'In attesa', 'paid' => 'Simulato riuscito', 'failed' => 'Fallito', 'cancelled' => 'Annullato', 'expired' => 'Scaduto', default => 'Da verificare' } }} · Nessun pagamento reale.</p>
        @endif
        @if($version->payment_provisional)<p>IVA da definire: conguaglio provvisorio, nessun incasso simulato.</p>@endif
    </div>
</details>
