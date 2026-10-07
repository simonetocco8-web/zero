<x-guest-layout>
    <h1>Crea il tuo account</h1><p class="mt-2 mb-6 text-sm text-slate-500">Inizia dal tuo accesso personale. Registrazione azienda e scelta piano arriveranno nelle prossime fasi.</p>
    <form method="POST" action="{{ route('register') }}" class="space-y-5">@csrf
        <x-field name="name" label="Nome e cognome" required autofocus autocomplete="name" />
        <x-field name="email" label="Email" type="email" required autocomplete="username" />
        <x-field name="password" label="Password" type="password" required autocomplete="new-password" hint="Usa almeno 8 caratteri." />
        <x-field name="password_confirmation" label="Conferma password" type="password" required autocomplete="new-password" />
        <x-button type="submit" class="w-full">Registrati</x-button>
    </form>
    <p class="mt-6 text-sm text-slate-600">Hai già un account? <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center font-semibold text-brand-700 underline">Accedi</a></p>
</x-guest-layout>
