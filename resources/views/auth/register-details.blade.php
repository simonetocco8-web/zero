<x-guest-layout title="Dati azienda e account">
<h1>Completa la registrazione</h1>
<p class="mt-3 text-sm text-muted">Dopo la registrazione il profilo della tua azienda sarà sottoposto a verifica amministrativa.</p>
<x-registration-steps :step="$context['ready'] ? 2 : 1" />
<x-registration-company-duplicate />
@if($context['status'] === 'found')
<x-alert variant="success" title="Azienda trovata" class="mb-5">Abbiamo recuperato i dati della tua azienda. Verificali e completali prima di continuare.<p class="mt-2 text-sm">Dati aziendali precompilati tramite OpenAPI Company Start.</p></x-alert>
@if($context['activity_status'])<p class="mb-4 text-sm">Stato Registro Imprese: <strong>{{ $context['activity_status'] }}</strong></p>@endif
@if(in_array($context['activity_status'], ['CESSATA','INATTIVA','SOSPESA'], true))<x-alert title="Attenzione" class="mb-5">Il Registro Imprese segnala uno stato aziendale non attivo. Puoi completare la registrazione, ma il profilo sarà comunque sottoposto a verifica amministrativa.</x-alert>@endif
@elseif($context['status'] === 'not_found')
<x-alert title="Azienda non trovata automaticamente" class="mb-5">Non abbiamo trovato automaticamente i dati associati alla Partita IVA {{ $context['vat_number'] }}. Puoi continuare inserendoli manualmente.</x-alert>
@else
<x-alert title="Compilazione automatica non disponibile" class="mb-5"><p>{{ $context['reason'] === 'not_configured' ? 'Il servizio di compilazione automatica non è configurato.' : 'Non è stato possibile verificare automaticamente l’azienda.' }} Puoi continuare inserendo manualmente i dati oppure riprovare la verifica.</p></x-alert>
@endif
@if(!$context['ready'])
<p class="mb-3 font-semibold">Partita IVA {{ $context['vat_number'] }}</p>
<div class="space-y-3"><form method="POST" action="{{ route('register.company.lookup') }}">@csrf<input type="hidden" name="vat_number" value="{{ $context['vat_number'] }}"><x-button type="submit" variant="secondary">Riprova</x-button></form><p class="text-sm text-muted">Se il servizio è temporaneamente occupato, attendi qualche secondo prima di riprovare.</p><form method="POST" action="{{ route('register.company.manual') }}">@csrf<x-button type="submit">Continua manualmente</x-button></form></div>
@else
<form method="POST" action="{{ route('register.store') }}" class="space-y-5">@csrf
<h2>Dati azienda</h2>
<x-company-fields :retailer="$company" :vat-readonly="true" />
<a class="inline-flex min-h-11 items-center underline" href="{{ route('register') }}">Modifica Partita IVA</a>
<h2>Dati account</h2>
<x-field name="email" label="Email account" type="email" required autocomplete="username" />
<x-field name="password" label="Password" type="password" required autocomplete="new-password" hint="Usa almeno 8 caratteri." />
<x-field name="password_confirmation" label="Conferma password" type="password" required autocomplete="new-password" />
<fieldset><legend class="field-label">Scegli il piano</legend><div class="space-y-3">
@foreach($plans as $plan)<label class="flex min-h-11 gap-3 rounded-xl border border-line p-4"><input type="radio" name="plan_code" value="{{ $plan->code }}" required @checked(old('plan_code','free') === $plan->code)><span><strong>{{ $plan->name }}</strong><span class="block text-sm">{{ \App\Support\DisplayMoney::euros($plan->monthly_price_cents) }} / mese · Commissione {{ app(\App\Services\MarketplaceCommission::class)->saleLabel($plan->code) }}</span>@if($plan->code === 'pro')<span class="block text-sm">Annuale: {{ \App\Support\DisplayMoney::euros($plan->annual_price_cents) }}. Pagamento da attivare separatamente.</span>@endif</span></label>@endforeach
</div>@error('plan_code')<p class="text-sm text-red-300">{{ $message }}</p>@enderror</fieldset>
<p class="text-sm text-muted">La scelta PRO non addebita alcun importo e non approva automaticamente il profilo.</p>
<x-button type="submit" class="w-full">Completa registrazione</x-button>
</form>
@endif
</x-guest-layout>
