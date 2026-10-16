<x-layouts.customer :title="'Ordine #'.$order->id"  :canonical="route('orders.show',$order)">
    <h1>Ordine #{{ $order->id }}</h1>
    <p class="my-3">Stato: {{ $order->status->label() }}. {{ $order->payment?->simulated ? 'Pagamento simulato: nessun addebito reale.' : 'Pagamento marketplace tramite Stripe.' }}</p>
    @if($order->status->value==='payment_pending')
        <p class="my-3">Prenotazione fino al {{ $order->stockReservations()->where('status','active')->min('expires_at') ?: '—' }}.</p>
    @endif
    @if(session('status'))
        <x-alert>{{ session('status') }}</x-alert>
    @endif
    <div class="mt-5 grid gap-4 lg:grid-cols-2">
        @foreach($order->vendorOrders as $vendor)
            <x-card>
                <h2>{{ $vendor->retailer_snapshot['name'] }}</h2>
                <p>Sottoordine #{{ $vendor->id }} · {{ $vendor->delivery_mode==='shipping' ? 'Spedizione':'Ritiro' }}</p>
                @foreach($vendor->items as $line)
                    <div class="mt-4">
                        <strong>{{ $line->name }}</strong>
                        <p>SKU: {{ $line->sku ?: '—' }} · {{ (string)\Brick\Math\BigDecimal::of($line->quantity_milliunits)->dividedBy(1000,3) }} {{ $line->unit }} × {{ \App\Support\DisplayMoney::euros($line->unit_price_cents) }}</p>
                        <p>{{ $line->tax_snapshot['description'] }}</p>
                    </div>
                @endforeach
                @if($vendor->address_snapshot)
                    <p class="mt-4">{{ $vendor->address_snapshot['recipient_name'] }} · {{ $vendor->address_snapshot['address_line_1'] }} · {{ $vendor->address_snapshot['city'] }}</p>
                @endif
                <x-order-totals :data="$vendor" /><x-button class="mt-3" variant="secondary" :href="route('customer.deliveries.show',$vendor)">Dettaglio consegna #{{ $vendor->id }}</x-button>
            </x-card>
        @endforeach
    </div>
    <x-card class="mt-5"><x-order-totals :data="$order" />
        @if($order->payment?->retailer_credit_cents)
<p>Credito retailer: {{ \App\Support\DisplayMoney::euros($order->payment->retailer_credit_cents) }} · Carta: {{ \App\Support\DisplayMoney::euros($order->payment->card_cents) }}.</p>
@endif
@if($order->payment?->bonus_cents)
            <p>Bonus promozionale: {{ \App\Support\DisplayMoney::euros($order->payment->bonus_cents) }} · Carta: {{ \App\Support\DisplayMoney::euros($order->payment->card_cents) }}.</p>
        @endif
    </x-card>
    @if($order->status->value==='payment_pending')
        @if($order->payment?->provider === 'stripe_connect' && $order->payment?->checkout_url)
            <x-button class="mt-5" :href="$order->payment->checkout_url">Paga con carta su Stripe</x-button>
        @endif
        @if(!$order->payment?->reference)
            <form class="mt-5" method="POST" action="{{ route('orders.payment-retry',$order) }}">
                @csrf
                <x-button type="submit">Riprova avvio pagamento</x-button>
            </form>
        @endif
        <form method="POST" action="{{ route('orders.cancel',$order) }}" class="mt-5">
            @csrf
            <x-button type="submit" variant="secondary">Annulla ordine e libera le quantità</x-button>
        </form>
    @endif
    <x-button class="mt-5" :href="route('orders.index')" variant="secondary">Tutti i tuoi ordini</x-button>
</x-layouts.customer>
