<div class="mt-4 space-y-4">
    <div class="flex flex-wrap gap-3" data-primary-actions>
        @if($canAccept)
            <form method="POST" action="{{ route('retailer.exchanges.accept', $exchange) }}">@csrf<input type="hidden" name="version_id" value="{{ $version->id }}"><input type="hidden" name="summary_hash" value="{{ $version->summary_hash }}"><x-button type="submit" :disabled="$version->difference_cents === 0 && $version->payment_provisional">Accetta versione {{ $version->number }}</x-button></form>
        @endif
        @if($canPay)
            @if($version->payment?->provider === 'stripe_connect')
                @if($version->payment?->checkout_url)<x-button :href="$version->payment->checkout_url">Paga il conguaglio con carta su Stripe</x-button>@endif
            @else
                <form method="POST" action="{{ route('retailer.exchanges.simulate', $exchange) }}">@csrf<input type="hidden" name="version_id" value="{{ $version->id }}"><input type="hidden" name="summary_hash" value="{{ $version->summary_hash }}"><x-button type="submit" :disabled="$version->payment_provisional">Simula pagamento conguaglio</x-button></form>
            @endif
        @endif
        @if($canComplete)
            <form method="POST" action="{{ route('retailer.exchanges.complete', $exchange) }}">@csrf<input type="hidden" name="version_id" value="{{ $version->id }}"><input type="hidden" name="summary_hash" value="{{ $version->summary_hash }}"><x-button type="submit">Conferma ricezione merce</x-button></form>
        @endif
    </div>
    @if($simulated)
        <x-alert title="Modalità simulazione">Nessun pagamento reale verrà effettuato.</x-alert>
    @endif
    @if($active)
        <div class="flex flex-wrap items-center gap-3 border-t border-line pt-4" data-secondary-actions>
            @if($canNegotiate)<x-button variant="secondary" :href="route('retailer.exchanges.edit', $exchange)">Crea controproposta</x-button>@endif
            @foreach(['reject' => 'Rifiuta', 'cancel' => 'Annulla'] as $action => $label)
                <form method="POST" action="{{ route('retailer.exchanges.'.$action, $exchange) }}">@csrf<input type="hidden" name="version_id" value="{{ $version->id }}"><input type="hidden" name="summary_hash" value="{{ $version->summary_hash }}"><x-button type="submit" variant="danger">{{ $label }}</x-button></form>
            @endforeach
        </div>
        <details class="rounded-xl border border-line p-3 text-sm">
            <summary class="flex min-h-11 cursor-pointer items-center gap-2 font-semibold">Prenotazioni e credito <x-icon name="chevron-down" /></summary>
            <p class="mt-2">{{ $exchange->status->value === 'awaiting_difference_payment' ? 'Quantità prenotate fino alla scadenza indicata.' : 'Quantità non ancora prenotate.' }}</p>
            @if($canNegotiate && $version->payer_retailer_id === auth()->user()->retailer->id && $version->difference_cents > 0 && config('marketplace.credit.retailer_spending_enabled'))
                <p class="mt-2">Alla doppia accettazione sarà riservato il tuo credito retailer disponibile fino al conguaglio; il residuo sarà pagato con carta. Credito disponibile: {{ \App\Support\DisplayMoney::euros(app(\App\Services\RetailerCredit::class)->available(auth()->user()->retailer)) }}.</p>
            @endif
            @if($version->payment?->retailer_credit_cents)<p class="mt-2">Credito retailer riservato: {{ \App\Support\DisplayMoney::euros($version->payment->retailer_credit_cents) }} · Carta: {{ \App\Support\DisplayMoney::euros($version->payment->card_cents) }}.</p>@endif
        </details>
    @endif
</div>
