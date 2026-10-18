<x-layouts.retailer :title="'Scambio #'.$exchange->id">
    <h1>Scambio #{{ $exchange->id }}</h1>
    <p class="my-3 font-semibold">{{ ($exchange->status->open() && $exchange->expires_at<=now() ? \App\Enums\ExchangeStatus::Expired : $exchange->status)->label() }} · Versione corrente {{ $version->number }}</p>
    @if(session('status'))<x-alert>{{ session('status') }}</x-alert>@endif
    @if($errors->any())<x-alert variant="danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif
    @if($exchange->status->open())<p class="my-4">Scadenza: {{ $exchange->expires_at->format('d/m/Y H:i') }}. {{ $exchange->status->value==='awaiting_difference_payment' ? 'Quantità prenotate fino a questa scadenza.' : 'Quantità non ancora prenotate.' }}</p>@endif
    <x-card class="my-5">
        <h2>Conguaglio economico</h2>
        <p class="mt-3">A: {{ \App\Support\DisplayMoney::euros($version->value_a_cents) }} · B: {{ \App\Support\DisplayMoney::euros($version->value_b_cents) }}</p>
        @if($version->difference_cents===0)
            <p class="mt-3 font-semibold">{{ $version->payment_provisional ? 'Valori provvisori uguali: conguaglio definitivo da verificare.' : 'Valori uguali: nessun conguaglio.' }}</p>
        @else
            @php($payer=$version->payer_retailer_id===$exchange->retailer_a_id?'a':'b')
            @php($payee=$payer==='a'?'b':'a')
            <p class="mt-3 font-semibold break-words">{{ $version->parties_snapshot[$payer]['name'] }} paga {{ \App\Support\DisplayMoney::euros($version->difference_cents) }} a {{ $version->parties_snapshot[$payee]['name'] }}.</p>
        @endif
        <p class="mt-3 text-sm text-slate-600">Il conguaglio considera i debiti reciproci concordati. Le due cessioni, i trasporti e la commissione restano distinti; il trasporto pagato al vettore resta esterno al conguaglio.</p>
        @if($version->payment_provisional)<p class="mt-3 font-semibold">IVA da definire: conguaglio provvisorio, nessun incasso simulato. Un conguaglio zero provvisorio richiede una nuova versione con importi definiti.</p>@endif
        <p class="mt-3">Accettazioni della versione corrente: {{ $version->acceptances->count() }} / 2</p>
        <ul class="mt-2 space-y-2">@foreach(['a','b'] as $side)<li class="break-words">{{ $version->parties_snapshot[$side]['name'] }}: {{ $version->acceptances->contains('retailer_id',$version->parties_snapshot[$side]['id']) ? 'Accettato' : 'Da accettare' }}</li>@endforeach</ul>
    </x-card>
    <div class="my-5 flex flex-wrap gap-3">
        @if($exchange->status->open() && $exchange->expires_at>now())
            @can('negotiate',$exchange)
                @if($version->payer_retailer_id === auth()->user()->retailer->id && $version->difference_cents > 0 && config('marketplace.credit.retailer_spending_enabled'))
<p class="my-3">Alla doppia accettazione sarà riservato il tuo credito retailer disponibile fino al conguaglio; il residuo sarà pagato con carta. Credito disponibile: {{ \App\Support\DisplayMoney::euros(app(\App\Services\RetailerCredit::class)->available(auth()->user()->retailer)) }}.</p>
@endif
@if(!$version->acceptances->contains('retailer_id',auth()->user()->retailer->id))
                    <form method="POST" action="{{ route('retailer.exchanges.accept',$exchange) }}">@csrf<input type="hidden" name="version_id" value="{{ $version->id }}"><input type="hidden" name="summary_hash" value="{{ $version->summary_hash }}"><x-button type="submit" :disabled="$version->difference_cents===0 && $version->payment_provisional">Accetta esattamente la versione {{ $version->number }}</x-button></form>
                @endif
                <x-button variant="secondary" :href="route('retailer.exchanges.edit',$exchange)">Crea controproposta</x-button>
            @endcan
            @foreach(['reject'=>'Rifiuta','cancel'=>'Annulla'] as $action=>$label)
                <form method="POST" action="{{ route('retailer.exchanges.'.$action,$exchange) }}">@csrf<input type="hidden" name="version_id" value="{{ $version->id }}"><input type="hidden" name="summary_hash" value="{{ $version->summary_hash }}"><x-button type="submit" variant="secondary">{{ $label }}</x-button></form>
            @endforeach
            @if($version->payment?->retailer_credit_cents)<p>Credito retailer riservato: {{ \App\Support\DisplayMoney::euros($version->payment->retailer_credit_cents) }} · Carta: {{ \App\Support\DisplayMoney::euros($version->payment->card_cents) }}.</p>@endif
@if($exchange->status->value==='awaiting_difference_payment')
                @can('simulatePayment',$exchange)
                    @if($version->payment?->provider === 'stripe_connect')
                    @if($version->payment?->checkout_url)<x-button :href="$version->payment->checkout_url">Paga il conguaglio con carta su Stripe</x-button>@endif
                    @else
                    <form method="POST" action="{{ route('retailer.exchanges.simulate',$exchange) }}">@csrf<input type="hidden" name="version_id" value="{{ $version->id }}"><input type="hidden" name="summary_hash" value="{{ $version->summary_hash }}"><x-button type="submit" :disabled="$version->payment_provisional">Simula conguaglio (nessun addebito)</x-button></form>@endif
                @endcan
            @endif
        @endif
        @if($exchange->status->value==='confirmed' && !$version->completions->contains('retailer_id',auth()->user()->retailer->id))
            <form method="POST" action="{{ route('retailer.exchanges.complete',$exchange) }}">@csrf<input type="hidden" name="version_id" value="{{ $version->id }}"><input type="hidden" name="summary_hash" value="{{ $version->summary_hash }}"><x-button type="submit">Conferma ricezione merci</x-button></form>
        @endif
    </div>
    <h2 class="mt-8">Storico versioni e riepiloghi</h2>
    <div class="mt-4 space-y-5">
        @foreach($exchange->versions->reverse() as $v)
            <x-card>
                <h3 class="text-xl font-semibold">Versione {{ $v->number }} {{ $v->id===$version->id ? '· Corrente' : '· Superata' }}</h3>
                <p class="mt-2">{{ $v->created_at->format('d/m/Y H:i') }} · Accettazioni: {{ $v->acceptances->count() }} / 2 {{ $v->id!==$version->id ? '(non valide per la versione corrente)' : '' }}</p>
                <div class="mt-4 grid gap-5 lg:grid-cols-2">
                    @foreach(['a','b'] as $side)
                        <section>
                            <h4 class="font-semibold break-words">Lato {{ strtoupper($side) }} · {{ $v->parties_snapshot[$side]['name'] }}</h4>
                            <ul class="mt-3 space-y-3">
                                @foreach($v->lines->where('side',$side) as $line)
                                    <li class="rounded-xl bg-slate-50 p-3"><strong class="break-words">{{ $line->product_snapshot['name'] }}</strong><p>SKU: {{ $line->product_snapshot['sku'] ?: '—' }}</p><p>{{ \App\Support\DisplayQuantity::format($line->quantity_milliunits) }} {{ $line->product_snapshot['unit'] }} × {{ \App\Support\DisplayMoney::euros($line->agreed_unit_value_cents) }} = {{ \App\Support\DisplayMoney::euros($line->goods_value_cents) }}</p><p>{{ config('marketplace.vat_treatments.'.$line->product_snapshot['vat_treatment']) ?? 'IVA da definire' }}</p></li>
                                @endforeach
                            </ul>
                            @php($d=$side==='a'?$v->delivery_a:$v->delivery_b)
                            <p class="mt-3">Consegna: {{ $d['mode']==='pickup' ? 'Ritiro':'Spedizione' }}</p><p class="break-words">{{ $d['destination'] }}</p><p>Trasporto separato: {{ \App\Support\DisplayMoney::euros($d['shipping_cost_cents']) }}</p>
                        </section>
                    @endforeach
                </div>
                <p class="mt-4 font-semibold">Valore A {{ \App\Support\DisplayMoney::euros($v->value_a_cents) }} · Valore B {{ \App\Support\DisplayMoney::euros($v->value_b_cents) }} · Differenza {{ \App\Support\DisplayMoney::euros($v->difference_cents) }}</p>
                @if(($v->commission_snapshot['policy']??null)==='approved_difference_v1')
                <p class="mt-3 text-sm">Commissione sul solo conguaglio: {{ \App\Support\DisplayMoney::euros($v->commission_snapshot['amount_cents']) }} ({{ \App\Support\DisplayMoney::percent($v->commission_snapshot['basis_points']) }}), trattenuta al destinatario. Netto prima di costi PSP e fiscalità commissione: {{ \App\Support\DisplayMoney::euros($v->commission_snapshot['recipient_net_cents']) }}.</p>
                @else<p class="mt-3 text-sm">Snapshot storico: commissione scambio non definita.</p>@endif
                @foreach(['a'=>$v->delivery_a,'b'=>$v->delivery_b] as $side=>$terms)
                <p class="mt-2 text-sm">Trasporto {{ strtoupper($side) }} → {{ $side==='a'?'B':'A' }}: {{ isset($terms['payer_side'])?'paga '.strtoupper($terms['payer_side']):'pagatore non definito nello storico' }}; {{ match($terms['settlement']??null){'sender'=>'rimborso al mittente','external'=>'pagamento esterno al vettore',default=>'pagamento trasporto non definito nello storico'} }}.</p>
                @endforeach
                <p class="mt-3 text-sm">Ciascun rivenditore documenta la propria cessione; ZeroMagazzino documenta il proprio servizio.</p>

                @if($v->notes)<p class="mt-3 whitespace-pre-line break-words">{{ $v->notes }}</p>@endif
                @if($v->payment && $v->payment->simulated)<p class="mt-3">Conguaglio fake: {{ match($v->payment->status){'payment_pending'=>'In attesa','paid'=>'Simulato riuscito','failed'=>'Fallito','cancelled'=>'Annullato','expired'=>'Scaduto',default=>'Da verificare'} }} · Nessun pagamento reale.</p>@endif
            </x-card>
        @endforeach
    </div>
    <h2 class="mt-8">Timeline</h2>
    <ol class="my-5 space-y-3">
        @foreach($exchange->events as $event)
            <li class="rounded-xl border border-slate-200 bg-white p-4">{{ $event->created_at->format('d/m/Y H:i') }} · {{ match($event->action){'proposed'=>'Proposta inviata','countered'=>'Nuova controproposta','accepted_by'=>'Accettazione registrata','accepted'=>'Doppia accettazione','awaiting_difference_payment'=>'Conguaglio in attesa','confirmed'=>'Scambio confermato','rejected'=>'Scambio rifiutato','cancelled'=>'Scambio annullato','expired'=>'Scambio scaduto','difference_paid'=>'Conguaglio simulato riuscito','difference_failed'=>'Conguaglio simulato fallito','difference_cancelled'=>'Conguaglio annullato','received'=>'Ricezione merci confermata','completed'=>'Scambio completato','eligibility_lost'=>'Requisiti PRO non più validi',default=>'Evento registrato'} }} · versione {{ $exchange->versions->firstWhere('id',$event->exchange_version_id)?->number }} · {{ $event->user_id===null ? 'Sistema' : ($event->user_id===$exchange->retailerA->user_id ? $exchange->retailerA->company_name : $exchange->retailerB->company_name) }}</li>
        @endforeach
    </ol>
    <x-button variant="secondary" :href="route('retailer.exchanges.index')">Tutti gli scambi</x-button>
</x-layouts.retailer>
