@props(['title', 'description' => null, 'canonical' => null, 'image' => null, 'robots' => 'noindex,nofollow', 'entry' => false])
<!DOCTYPE html>
<html data-theme="light" lang="it">
<head><x-head :title="$title" :description="$description" :canonical="$canonical" :image="$image" robots="noindex,nofollow" /></head>
<body>
<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:bg-surface focus:p-3">Vai al contenuto</a>
<header class="border-b border-line bg-surface">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
        <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center"><x-brand /></a>
        <x-theme-toggle />
        @if($entry)
            <span class="text-xs font-semibold text-muted">Materiali fermi. Nuove possibilità.</span>
        @else
            <nav aria-label="Navigazione principale" class="flex flex-wrap items-center gap-2">
                <a class="btn btn-secondary" href="{{ route('marketplace.index') }}">Marketplace</a>
                <a class="btn btn-secondary" href="{{ route('home') }}#home-categories">Categorie</a>
                @auth
                    @can('purchase', \App\Models\User::class)
                        <a class="btn btn-secondary" href="{{ route('cart.index') }}">Carrello</a>
                        <a class="btn btn-secondary" href="{{ route('orders.index') }}">Ordini</a>
                    @endcan
                    <a class="btn btn-primary" href="{{ route('dashboard') }}">La tua area</a>
                @else
                    <a class="btn btn-primary" href="{{ route('login') }}">Accedi</a>
                @endauth
            </nav>
        @endif
        @unless($entry)
        <form role="search" method="GET" action="{{ route('marketplace.index') }}" class="w-full"><label class="sr-only" for="header-search">Cerca prodotti, marche, codici o EAN</label><div class="flex gap-2"><input id="header-search" type="search" name="q" maxlength="120" value="{{ request('q') }}" placeholder="Cerca prodotti, marche, codici o EAN" class="field min-w-0 flex-1"><button class="btn btn-primary" type="submit">Cerca</button></div></form>
        @endunless
    </div>
</header>
<main id="main-content" tabindex="-1" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-10">{{ $slot }}</main>
<footer class="mt-10 border-t border-line bg-surface">
    <div class="mx-auto grid max-w-7xl gap-5 px-4 py-8 sm:grid-cols-2 sm:px-6">
        <div><x-brand /><p class="mt-3 max-w-md text-sm text-muted">Un nuovo valore ai materiali. Giacenze approvate di rivenditori verificati, in un unico spazio.</p></div>
        <nav aria-label="Collegamenti utili" class="flex flex-wrap items-start gap-3 sm:justify-end">
            @unless($entry)
                @guest
                    <a class="btn btn-secondary" href="{{ route('register') }}">Diventa rivenditore</a>
                    <a class="btn btn-secondary" href="{{ route('customer.register') }}">Registrati come cliente</a>
                @endguest
            @endunless
            <a class="inline-flex min-h-11 items-center underline" href="{{ route('privacy') }}">Privacy</a>
        </nav>
    </div>
</footer>
</body>
</html>
