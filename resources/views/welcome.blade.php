<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><x-head title="Benvenuto" /></head>
<body>
    <header class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-6 sm:px-6"><a href="{{ route('home') }}"><x-brand class="text-lg" /></a><x-button variant="secondary" :href="auth()->check() ? route('dashboard') : route('login')">{{ auth()->check() ? 'La tua area' : 'Accedi' }}</x-button></header>
    <main class="mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-20">
        <div class="grid items-center gap-10 lg:grid-cols-2">
            <div><x-badge variant="success">Nuove opportunità, meno sprechi</x-badge><h1 class="mt-6 text-4xl leading-tight sm:text-5xl lg:text-6xl">Il materiale fermo.<br><span class="text-brand-600">Il valore in movimento.</span></h1><p class="mt-6 max-w-lg text-lg leading-relaxed text-slate-600">Lo spazio dedicato ai rivenditori di materiale edile. Una nuova occasione per le tue giacenze, una gestione più semplice per la tua azienda.</p><div class="mt-8 flex flex-wrap gap-3"><x-button :href="route('register')">Crea il tuo account<x-icon name="arrow" /></x-button><x-button variant="secondary" :href="route('login')">Accedi</x-button></div></div>
            <div class="rounded-3xl bg-brand-950 p-6 text-white sm:p-10"><span class="mb-8 inline-flex size-16 items-center justify-center rounded-2xl bg-white/10"><x-icon name="box" class="size-9" /></span><h2 class="text-2xl">Un nuovo spazio per il tuo magazzino.</h2><p class="mt-4 leading-relaxed text-emerald-100">Giacenze, richieste e credito: tutto parte da una visione chiara.</p><div class="mt-8 space-y-3">@foreach(['Giacenze organizzate','Richieste a portata di mano','Credito sotto controllo'] as $item)<div class="flex items-center gap-3 rounded-xl border border-white/10 p-4"><span class="size-2 shrink-0 rounded-full bg-emerald-300" aria-hidden="true"></span>{{ $item }}</div>@endforeach</div><p class="mt-6 text-xs text-emerald-200">Le sezioni gestionali sono in preparazione.</p></div>
        </div>
    </main>
    <footer class="mx-auto max-w-6xl px-4 py-8 text-sm text-slate-500 sm:px-6">ZeroMagazzino · Un nuovo valore ai materiali.</footer>
</body>
</html>
