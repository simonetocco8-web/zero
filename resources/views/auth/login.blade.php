<x-guest-layout>
    <h1>Bentornato</h1><p class="mt-2 mb-6 text-sm text-slate-500">Accedi al tuo spazio ZeroMagazzino.</p>
    @if(session('status'))<x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>@endif
    <form method="POST" action="{{ route('login') }}" class="space-y-5">@csrf
        <x-field name="email" label="Email" type="email" required autofocus autocomplete="username" />
        <x-field name="password" label="Password" type="password" required autocomplete="current-password" />
        <x-checkbox name="remember" label="Ricordami" />
        <x-button type="submit" class="w-full">Accedi</x-button>
        <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-brand-700 underline">Hai dimenticato la password?</a>
    </form>
    <p class="mt-6 border-t border-slate-100 pt-5 text-sm text-slate-600">Non hai un account? <a href="{{ route('register') }}" class="inline-flex min-h-11 items-center font-semibold text-brand-700 underline">Registrati</a></p>
</x-guest-layout>
