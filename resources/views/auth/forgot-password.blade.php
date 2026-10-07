<x-guest-layout title="Recupera password">
    <h1>Recupera la password</h1><p class="mt-2 mb-6 text-sm leading-relaxed text-slate-500">Inserisci la tua email per richiedere un link e scegliere una nuova password.</p>
    @if(session('status'))<x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>@endif
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">@csrf
        <x-field name="email" label="Email" type="email" required autocomplete="email" />
        <x-button type="submit" class="w-full">Invia link di recupero</x-button>
    </form><a href="{{ route('login') }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-medium text-brand-700 underline">Torna all’accesso</a>
</x-guest-layout>
