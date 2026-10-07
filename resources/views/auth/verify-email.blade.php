<x-guest-layout>
    <h1>Verifica la tua email</h1><p class="mt-2 mb-6 text-sm text-slate-500">Usa il link ricevuto via email per confermare il tuo indirizzo.</p>
    @if(session('status') === 'verification-link-sent')<x-alert variant="success" class="mb-4">Abbiamo inviato un nuovo link di verifica.</x-alert>@endif
    <form method="POST" action="{{ route('verification.send') }}">@csrf<x-button type="submit" class="w-full">Invia un nuovo link</x-button></form>
    <form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<x-button variant="secondary" type="submit" class="w-full">Esci</x-button></form>
</x-guest-layout>
