@php
    $viewer = auth()->user()->retailer->id;
    $ownSide = (int)$exchange->retailer_a_id === (int)$viewer ? 'a' : 'b';
    $otherSide = $ownSide === 'a' ? 'b' : 'a';
    $ownParty = $version->parties_snapshot[$ownSide];
    $otherParty = $version->parties_snapshot[$otherSide];
    $active = $exchange->status->open() && $exchange->expires_at > now();
    $state = $exchange->status->open() && !$active ? \App\Enums\ExchangeStatus::Expired : $exchange->status;
    $ownAccepted = $version->acceptances->contains('retailer_id', $viewer);
    $ownCompleted = $version->completions->contains('retailer_id', $viewer);
    $canNegotiate = $active && auth()->user()->can('negotiate', $exchange);
    $canAccept = $canNegotiate && !$ownAccepted;
    $canPay = $active && $exchange->status->value === 'awaiting_difference_payment' && auth()->user()->can('simulatePayment', $exchange);
    $canComplete = $exchange->status->value === 'confirmed' && !$ownCompleted;
    $payerSide = $version->payer_retailer_id === $exchange->retailer_a_id ? 'a' : 'b';
    $payeeSide = $payerSide === 'a' ? 'b' : 'a';
    $simulated = $version->payment?->simulated || $version->payment?->provider === 'fake_marketplace';
    $statusLabel = $state->value === 'awaiting_difference_payment' ? 'In attesa di conguaglio' : ($canAccept ? 'Da accettare' : $state->label());
    $tone = match($state->value) { 'completed','confirmed' => 'success', 'rejected','cancelled','expired' => 'danger', 'awaiting_difference_payment' => 'warning', default => $canAccept ? 'warning' : 'neutral' };
    $actionTitle = 'Nessuna azione richiesta';
    $next = match($state->value) { 'completed' => 'Lo scambio è completato. Entrambe le parti hanno confermato la ricezione.', 'rejected' => 'Lo scambio è stato rifiutato. Puoi consultare versioni e storico.', 'cancelled' => 'Lo scambio è stato annullato. Puoi consultare versioni e storico.', 'expired' => 'Lo scambio è scaduto. Puoi consultare versioni e storico.', default => 'Lo scambio è in attesa della controparte.' };
    if ($canComplete) {
        $actionTitle = 'Prossima azione';
        $next = 'Conferma di aver ricevuto la merce.';
    } elseif ($state->value === 'confirmed' && $ownCompleted) {
        $next = 'Hai confermato la ricezione. Attendi che '.$otherParty['name'].' confermi la propria.';
    } elseif ($active && $state->value === 'awaiting_difference_payment') {
        if ($version->payment_provisional) {
            $next = 'La configurazione IVA del conguaglio non è determinata: il pagamento non può essere completato.';
        } elseif ($canPay) {
            $actionTitle = 'Prossima azione';
            $next = 'Paga il conguaglio di '.\App\Support\DisplayMoney::euros($version->difference_cents).' per confermare lo scambio.';
        } elseif ($version->payer_retailer_id === $viewer) {
            $next = 'Il pagamento del conguaglio non è disponibile con i requisiti attuali. Consulta i dettagli dello scambio.';
        } else {
            $next = 'Attendi il pagamento del conguaglio da '.$version->parties_snapshot[$payerSide]['name'].'.';
        }
    } elseif ($canAccept) {
        $actionTitle = 'Prossima azione';
        $next = $version->difference_cents === 0 && $version->payment_provisional ? 'Serve una nuova versione con importi definiti prima di accettare lo scambio.' : 'Controlla cosa offri e cosa ricevi, poi accetta la versione '.$version->number.'.';
    } elseif ($active && $version->acceptances->count() >= 2) {
        $next = 'Entrambe le parti hanno accettato. Lo scambio è in lavorazione.';
    } elseif ($active && $ownAccepted && $version->acceptances->count() < 2) {
        $next = 'Attendi che '.$otherParty['name'].' accetti la versione '.$version->number.'.';
    }
    $previous = $exchange->versions->reverse()->reject(fn($v) => $v->id === $version->id);
@endphp
<x-layouts.retailer :title="'Scambio #'.$exchange->id">
<div class="mx-auto w-full max-w-6xl space-y-6">
    <header class="flex flex-col gap-5 border-b border-line pb-6 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0"><h1>Scambio #{{ $exchange->id }}</h1><p class="mt-3 break-words text-muted">{{ $ownParty['name'] }} <span aria-label="scambia con">↔</span> {{ $otherParty['name'] }}</p></div>
        <div class="shrink-0 sm:max-w-sm sm:text-right">
            <x-badge :variant="$tone" class="px-4 py-2 text-sm uppercase tracking-wide">{{ $statusLabel }}</x-badge>
            <p class="mt-3 text-sm font-semibold">Versione corrente: {{ $version->number }}</p>
            @if($exchange->status->open())<p class="mt-1 text-sm text-muted">Scade il {{ $exchange->expires_at->format('d/m/Y') }} alle {{ $exchange->expires_at->format('H:i') }}</p>@endif
        </div>
    </header>
    @if(session('status'))<x-alert>{{ session('status') }}</x-alert>@endif
    @if($errors->any())<x-alert variant="danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif

    <x-card class="border-l-4 border-l-brand-600" aria-labelledby="next-action-title">
        <p id="next-action-title" class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-brand-700"><x-icon name="arrow" /> {{ $actionTitle }}</p>
        <p class="mt-3 text-lg font-semibold" data-next-action>{{ $next }}</p>
        @include('exchanges.partials.actions')
    </x-card>

    <section aria-labelledby="economic-summary-title">
        <h2 id="economic-summary-title" class="sr-only">Conguaglio economico</h2>
        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <div class="min-w-0 rounded-2xl border border-line bg-surface p-4 sm:p-6"><dt class="text-sm font-semibold uppercase text-muted">Tu offri</dt><dd class="mt-2 break-words text-xl font-bold sm:text-2xl">{{ \App\Support\DisplayMoney::euros($ownSide === 'a' ? $version->value_a_cents : $version->value_b_cents) }}</dd></div>
            <div class="min-w-0 rounded-2xl border border-line bg-surface p-4 sm:p-6"><dt class="text-sm font-semibold uppercase text-muted">Ricevi</dt><dd class="mt-2 break-words text-xl font-bold sm:text-2xl">{{ \App\Support\DisplayMoney::euros($otherSide === 'a' ? $version->value_a_cents : $version->value_b_cents) }}</dd></div>
            <div class="col-span-2 min-w-0 rounded-2xl border border-brand-600 bg-brand-50 p-4 lg:col-span-1 sm:p-6"><dt class="text-sm font-bold uppercase text-brand-700">{{ $version->payment_provisional ? 'Conguaglio provvisorio' : 'Conguaglio' }}</dt><dd class="mt-2 break-words text-3xl font-bold text-brand-700" data-difference>{{ \App\Support\DisplayMoney::euros($version->difference_cents) }}</dd></div>
        </dl>
        @if($version->difference_cents === 0)
            <p class="mt-4 font-semibold">{{ $version->payment_provisional ? 'Valori provvisori uguali: conguaglio definitivo da verificare.' : 'Nessun conguaglio' }}</p>
        @else
            <p class="mt-4 break-words font-semibold">{{ $version->parties_snapshot[$payerSide]['name'] }} paga {{ \App\Support\DisplayMoney::euros($version->difference_cents) }} a {{ $version->parties_snapshot[$payeeSide]['name'] }}.</p>
        @endif
        @if($version->payment_provisional)<x-alert class="mt-4" title="IVA da definire">Il conguaglio è provvisorio e non può ancora essere pagato. Un conguaglio zero provvisorio richiede una nuova versione con importi definiti.</x-alert>@endif
    </section>

    <section aria-labelledby="acceptances-title">
        <h2 id="acceptances-title" class="text-lg font-semibold">Accettazioni {{ $version->acceptances->count() }}/2</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            @foreach([$ownSide, $otherSide] as $side)
                @php($accepted = $version->acceptances->contains('retailer_id', $version->parties_snapshot[$side]['id']))
                <div class="flex min-w-0 items-start gap-3 rounded-xl border border-line bg-surface p-4" data-acceptance-side="{{ $side }}">
                    <span class="mt-1 {{ $accepted ? 'text-emerald-600' : 'text-muted' }}"><x-icon :name="$accepted ? 'check-circle' : 'clock'" /></span>
                    <div class="min-w-0"><p class="break-words font-semibold">{{ $version->parties_snapshot[$side]['name'] }}</p><p class="mt-1 text-sm text-muted">{{ $accepted ? 'Accettato' : 'In attesa' }}</p></div>
                </div>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="current-version-title" data-current-version="{{ $version->number }}">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2"><h2 id="current-version-title">Versione corrente · #{{ $version->number }}</h2><time class="text-sm text-muted" datetime="{{ $version->created_at->toIso8601String() }}">{{ $version->created_at->format('d/m/Y H:i') }}</time></div>
        <div class="grid items-stretch gap-4 lg:grid-cols-[1fr_auto_1fr]">
            <x-exchange.party-card :version="$version" :side="$ownSide" label="Tu offri" />
            <div class="flex items-center justify-center py-1 text-brand-700" aria-hidden="true"><x-icon name="exchange" class="size-8 rotate-90 lg:rotate-0" /></div>
            <x-exchange.party-card :version="$version" :side="$otherSide" label="Tu ricevi" />
        </div>
        <div class="mt-4"><x-exchange.financial-details :version="$version" /></div>
    </section>

    <section aria-labelledby="previous-versions-title">
        <h2 id="previous-versions-title">Versioni precedenti</h2>
        <div class="mt-4 space-y-3">
            @forelse($previous as $v)
                <details class="rounded-2xl border border-line bg-surface p-4 sm:p-5" data-previous-version="{{ $v->number }}">
                    <summary class="min-h-11 cursor-pointer rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                        <h3 class="text-base font-normal"><span class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold">Versione {{ $v->number }}</span><time class="text-sm text-muted" datetime="{{ $v->created_at->toIso8601String() }}">{{ $v->created_at->format('d/m/Y H:i') }}</time></span>
                        <span class="mt-1 block text-sm text-muted">Superata dalla versione {{ $version->number }}</span>
                        <span class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm"><span>Valore offerto {{ \App\Support\DisplayMoney::euros($ownSide === 'a' ? $v->value_a_cents : $v->value_b_cents) }}</span><span>Valore ricevuto {{ \App\Support\DisplayMoney::euros($otherSide === 'a' ? $v->value_a_cents : $v->value_b_cents) }}</span><span>Differenza {{ \App\Support\DisplayMoney::euros($v->difference_cents) }}</span></span>
                        <span class="mt-3 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-brand-700">Mostra dettagli <x-icon name="chevron-down" /></span></h3>
                    </summary>
                    <p class="mt-4 text-sm text-muted">Accettazioni: {{ $v->acceptances->count() }} / 2 (non valide per la versione corrente)</p>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2"><x-exchange.party-card :version="$v" :side="$ownSide" label="Tu offri" :historical="true" /><x-exchange.party-card :version="$v" :side="$otherSide" label="Tu ricevi" :historical="true" /></div>
                    <div class="mt-4"><x-exchange.financial-details :version="$v" /></div>
                </details>
            @empty<p class="text-sm text-muted">Non ci sono versioni precedenti.</p>@endforelse
        </div>
    </section>

    <section aria-labelledby="timeline-title"><h2 id="timeline-title">Timeline</h2><x-exchange.timeline :exchange="$exchange" /></section>
    <x-button variant="secondary" :href="route('retailer.exchanges.index')">Tutti gli scambi</x-button>
</div>
</x-layouts.retailer>
