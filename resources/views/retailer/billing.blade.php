<x-layouts.retailer title="Piano e pagamenti">
<h1>Piano e pagamenti</h1><p class="mt-2 text-slate-600">L’attivazione PRO e l’approvazione dell’azienda sono verifiche separate.</p>
@if(session('status'))<x-alert class="mt-4" title="Stato checkout"><p>{{ session('status') }}</p></x-alert>@endif
@if($errors->any())<x-alert class="mt-4" variant="danger" title="Checkout non avviato">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</x-alert>@endif
<div class="mt-6 grid min-w-0 gap-4 md:grid-cols-2"><x-card><h2>Piano attivo: {{ $active?->name ?? 'In attesa' }}</h2><div class="mt-3"><x-retailer-status :retailer="$retailer" /></div>
@if($billing)<p class="mt-3">Abbonamento Stripe: <x-badge>{{ match($billing->status){'active'=>$billing->invoice_paid ? 'Attivo e pagato' : 'Pagamento in attesa','past_due','unpaid'=>'Pagamento non riuscito','canceled'=>'Annullato','incomplete_expired'=>'Checkout scaduto',default=>'Pagamento in attesa'} }}</x-badge></p>
@if($billing->cancel_at_period_end)<p class="mt-3">Rinnovo annullato. Il piano resta attivo fino alla scadenza pagata.</p>@endif
@if($billing->current_period_end)<p class="mt-2 text-sm text-slate-600">Fine periodo: {{ $billing->current_period_end->format('d/m/Y') }}</p>@endif
@endif</x-card>
<x-card><h2>Attiva PRO</h2><p class="mt-2">{{ \App\Support\DisplayMoney::euros($plan->monthly_price_cents) }} al mese oppure {{ \App\Support\DisplayMoney::euros($plan->annual_price_cents) }} all’anno. Rinnovo automatico.</p><p class="mt-2 text-sm">Prodotti illimitati, scambio disponibile e commissione {{ \App\Support\DisplayMoney::percent($plan->commission_basis_points) }}.</p>
@if(!$configured)<x-alert class="mt-4" title="Pagamenti non ancora disponibili">Stripe non è ancora configurato. Non verrà effettuato alcun addebito.</x-alert>@endif
@can('checkout',\App\Models\Subscription::class)
<form method="POST" action="{{ route('retailer.billing.checkout') }}" class="mt-4 space-y-4">@csrf<x-select name="billing_interval" label="Periodo di rinnovo"><option value="monthly">Mensile · {{ \App\Support\DisplayMoney::euros($plan->monthly_price_cents) }}</option><option value="yearly">Annuale · {{ \App\Support\DisplayMoney::euros($plan->annual_price_cents) }}</option></x-select><x-button type="submit" :disabled="!$configured || ($billing && !in_array($billing->status,['canceled','incomplete_expired','incomplete']))">Continua su Stripe</x-button></form>
@endcan
<p class="mt-4 text-sm text-slate-600">Un pagamento riuscito non approva il profilo aziendale. Non puoi pubblicare prodotti finché il profilo resta in verifica.</p></x-card></div>
</x-layouts.retailer>
