@php
    $state = $vendor->status->value;
    $operational = in_array($state, ['paid', 'preparing', 'shipped', 'ready_for_pickup'], true) && auth()->user()->can('fulfil', $vendor);
    $nextMessage = match($state) {
        'paid' => $vendor->delivery_mode === 'shipping' ? 'Prepara i prodotti per la spedizione.' : 'Prepara i prodotti per il ritiro.',
        'preparing' => $vendor->delivery_mode === 'shipping' ? 'Inserisci corriere e tracking, se disponibili, e conferma la spedizione.' : 'Prepara l’ordine per il ritiro e avvisa il cliente aggiornando lo stato.',
        'shipped' => 'La merce risulta spedita. Quando la consegna è conclusa, conferma la consegna.',
        'ready_for_pickup' => 'L’ordine è pronto per il cliente. Quando il ritiro è concluso, conferma la consegna.',
        'completed' => 'La consegna risulta completata. Puoi consultare i dettagli e lo storico dell’ordine.',
        'cancelled' => 'L’ordine è annullato. Non sono disponibili avanzamenti della consegna.',
        'refunded' => 'L’ordine risulta rimborsato. Consulta il riepilogo e lo storico.',
        default => 'Attendi la conferma del pagamento. L’ordine non può ancora essere preparato.',
    };
    if (in_array($state, ['paid', 'preparing', 'shipped', 'ready_for_pickup'], true) && !$operational) {
        $nextMessage = 'Le azioni operative non sono disponibili per il tuo account. Puoi consultare lo storico dell’ordine.';
    }
    $statusColors = match($state) {
        'paid', 'shipped', 'ready_for_pickup' => 'border-brand-600 bg-brand-50 text-brand-700',
        'preparing' => 'border-amber-800 bg-amber-950 text-amber-200',
        'completed' => 'border-emerald-800 bg-emerald-950 text-emerald-200',
        'cancelled', 'refunded' => 'border-red-800 bg-red-950 text-red-200',
        default => 'border-line bg-raised text-muted',
    };
@endphp
<x-layouts.retailer :title="'Ordine ricevuto #'.$vendor->id">
<div class="mx-auto w-full max-w-6xl space-y-6">
    <header class="flex flex-col gap-4 border-b border-line pb-6 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0"><h1>Ordine ricevuto #{{ $vendor->id }}</h1><p class="mt-3 text-sm text-muted">Ordine cliente #{{ $vendor->order_id }} @if($vendor->created_at) · Ricevuto il <time datetime="{{ $vendor->created_at->toDateString() }}">{{ $vendor->created_at->format('d/m/Y') }}</time>@endif</p><p class="mt-2 text-sm text-muted">{{ $vendor->delivery_mode === 'shipping' ? 'Consegna tramite spedizione' : 'Ritiro presso il rivenditore' }}</p></div>
        <span data-vendor-status class="inline-flex self-start rounded-full border px-4 py-2 text-sm font-bold uppercase tracking-wide {{ $statusColors }}">{{ $vendor->status->label() }}</span>
    </header>
    @if(session('status'))<x-alert variant="success">{{ session('status') }}</x-alert>@endif
    @if($errors->any())<x-alert variant="danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif

    <x-vendor-order.status-progress :vendor="$vendor" />
    <x-card class="border-l-4 border-l-brand-600" aria-labelledby="vendor-next-title">
        <h2 id="vendor-next-title" class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-brand-700"><x-icon name="arrow" /> {{ $operational ? 'Prossima azione' : 'Nessuna azione richiesta' }}</h2>
        <p class="mt-3 text-lg font-semibold" data-next-action>{{ $nextMessage }}</p>
        <x-vendor-fulfilment :vendor="$vendor" />
    </x-card>

    <div class="grid min-w-0 gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2" data-vendor-main>
            <x-vendor-order.products :vendor="$vendor" />
            <x-card aria-labelledby="vendor-customer-title">
                <h2 id="vendor-customer-title" class="flex items-center gap-2"><x-icon name="user" /> Cliente</h2>
                <p class="mt-4 break-words text-lg font-semibold">{{ $vendor->order->customer_snapshot['name'] ?? '—' }}</p>
                @if(!empty($vendor->order->customer_snapshot['email']))<p class="mt-2 break-all text-muted">{{ $vendor->order->customer_snapshot['email'] }}</p>@endif
            </x-card>
            <x-vendor-order.delivery :vendor="$vendor" />
        </div>
        <aside class="min-w-0 space-y-6" aria-label="Riepilogo economico della vendita" data-vendor-sidebar><x-vendor-order.financial-summary :vendor="$vendor" /></aside>
    </div>

    <section aria-labelledby="vendor-history-title">
        <h2 id="vendor-history-title">Storico ordine</h2>
        <ol class="mt-5 border-l-2 border-line pl-5">
            @forelse($vendor->events as $event)
                <li class="relative pb-6 pl-3 last:pb-0"><span class="absolute -left-7 top-1.5 size-3 rounded-full border-2 border-surface bg-brand-600" aria-hidden="true"></span><div class="flex flex-wrap items-baseline gap-x-3 gap-y-1"><time datetime="{{ $event->created_at->toIso8601String() }}" class="text-sm font-semibold">{{ $event->created_at->format('H:i') }}<span class="ml-2 text-xs font-normal text-muted">{{ $event->created_at->format('d/m/Y') }}</span></time><p class="font-semibold">Ordine {{ mb_strtolower(\App\Enums\OrderStatus::from($event->to_status)->label()) }}</p></div></li>
            @empty<li class="pb-2 text-sm text-muted">Nessun cambio di stato di consegna.</li>@endforelse
        </ol>
    </section>
    <x-button variant="secondary" :href="route('retailer.sales.index')">Ordini ricevuti</x-button>
</div>
</x-layouts.retailer>
