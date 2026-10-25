<x-guest-layout title="Registrazione azienda">
<h1>Registra la tua azienda</h1>
<x-registration-steps />
<p class="mb-5 text-sm text-muted">Inserisci la Partita IVA della tua azienda. Cercheremo automaticamente i dati ufficiali per velocizzare la registrazione.</p>
<x-registration-company-duplicate />
@error('registration_step')<x-alert class="mb-4">{{ $message }}</x-alert>@enderror
<form method="POST" action="{{ route('register.company.lookup') }}" class="space-y-5">@csrf
<x-field name="vat_number" label="Partita IVA" inputmode="numeric" autocomplete="off" required maxlength="16" hint="Inserisci le 11 cifre della Partita IVA italiana." />
<x-button type="submit" class="w-full">Continua</x-button>
</form>
<p class="mt-5 text-sm">Hai già un account? <a class="inline-flex min-h-11 min-w-11 items-center underline" href="{{ route('login') }}">Accedi</a></p>
<a class="inline-flex min-h-11 min-w-11 items-center underline" href="{{ route('customer.register') }}">Registrati come cliente</a>
</x-guest-layout>
