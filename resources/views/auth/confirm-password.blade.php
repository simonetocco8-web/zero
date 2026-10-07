<x-guest-layout title="Conferma password">
    <h1>Conferma la password</h1><p class="mt-2 mb-6 text-sm text-slate-500">Conferma la tua identità per continuare.</p>
    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">@csrf
        <x-field name="password" label="Password" type="password" required autocomplete="current-password" />
        <x-button type="submit" class="w-full">Conferma</x-button>
    </form>
</x-guest-layout>
