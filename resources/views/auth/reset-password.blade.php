<x-guest-layout>
    <h1>Nuova password</h1><p class="mt-2 mb-6 text-sm text-slate-500">Scegli una nuova password per il tuo account.</p>
    <form method="POST" action="{{ route('password.store') }}" class="space-y-5">@csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-field name="email" label="Email" type="email" :value="$request->email" required autocomplete="username" />
        <x-field name="password" label="Nuova password" type="password" required autofocus autocomplete="new-password" />
        <x-field name="password_confirmation" label="Conferma password" type="password" required autocomplete="new-password" />
        <x-button type="submit" class="w-full">Salva nuova password</x-button>
    </form>
</x-guest-layout>
