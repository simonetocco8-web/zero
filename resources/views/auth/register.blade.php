<x-guest-layout>
<nav class="mb-6 flex gap-5" aria-label="Accesso"><a class="inline-flex min-h-11 items-center underline" href="{{ route('login') }}">Accedi</a><span class="inline-flex min-h-11 items-center font-semibold" aria-current="page">Registrati</span></nav>
<h1>Registra la tua azienda</h1><p class="my-4 text-sm text-slate-600">Dopo la registrazione verificheremo il profilo della tua azienda.</p>
<form method="POST" action="{{ route('register') }}" class="space-y-5">@csrf
<x-company-fields />
<x-field name="email" label="Email account" type="email" required autocomplete="username" />
<x-field name="password" label="Password" type="password" required autocomplete="new-password" hint="Usa almeno 8 caratteri." />
<x-field name="password_confirmation" label="Conferma password" type="password" required autocomplete="new-password" />
<fieldset><legend class="field-label">Scegli il piano</legend><div class="space-y-3">
@foreach($plans as $plan)<label class="flex min-h-11 gap-3 rounded-xl border border-slate-200 p-4"><input type="radio" name="plan_code" value="{{ $plan->code }}" required @checked(old('plan_code','free') === $plan->code)><span><strong>{{ $plan->name }}</strong><span class="block text-sm">{{ \App\Support\DisplayMoney::euros($plan->monthly_price_cents) }} / mese · Commissione {{ \App\Support\DisplayMoney::percent($plan->commission_basis_points) }}</span>@if($plan->code === 'pro')<span class="block text-sm">Annuale: {{ \App\Support\DisplayMoney::euros($plan->annual_price_cents) }}. Pagamento da attivare separatamente.</span>@endif</span></label>@endforeach
</div>@error('plan_code')<p class="text-sm text-red-700">{{ $message }}</p>@enderror</fieldset>
<p class="text-sm text-slate-600">La scelta PRO non addebita alcun importo e non approva automaticamente il profilo.</p>
<x-button type="submit" class="w-full">Registrati</x-button>
</form>
</x-guest-layout>
