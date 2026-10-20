<x-marketplace-layout title="Il magazzino cambia valore" description="Compra giacenze professionali presso rivenditori verificati." :canonical="route('home')">
<section class="brand-hero grid items-center gap-8 p-6 sm:p-10 lg:grid-cols-2">
    <div><p class="brand-eyebrow">NUOVE POSSIBILITÀ, ZERO SPRECHI</p><h1 class="mt-4 text-4xl leading-tight sm:text-5xl">Il magazzino<br>cambia valore.</h1><p class="mt-5 max-w-xl text-lg text-muted">Compra giacenze professionali, scopri occasioni e trova materiali disponibili presso rivenditori verificati.</p><div class="mt-7 flex flex-wrap gap-3"><x-button :href="route('marketplace.index')">Esplora il marketplace <x-icon name="arrow" /></x-button><x-button variant="secondary" href="#deals">Scopri le occasioni</x-button></div></div>
    <div class="grid grid-cols-2 gap-3" aria-label="Materiali dal marketplace">
@forelse($items->take(4) as $item)<a href="{{ route('marketplace.show', $item) }}" class="min-w-0 rounded-2xl border border-line bg-surface p-3">
@if($photo=$item->images->first())<div class="aspect-[4/3] rounded-xl bg-white"><img src="{{ route('marketplace.image', [$item,$photo->position]) }}" alt="{{ $photo->alt_text ?: $item->name }}" width="300" height="225" class="h-full w-full object-contain p-2" decoding="async"></div>@else
<div class="flex aspect-[4/3] items-center justify-center rounded-xl bg-raised"><x-icon name="box" class="size-12 text-brand-700" /></div>@endif
<p class="mt-2 text-sm font-semibold">{{ $item->name }}</p></a>@empty
<div class="col-span-2 flex min-h-48 items-center justify-center rounded-2xl bg-brand-50"><x-icon name="exchange" class="size-24 text-brand-700" /><span class="sr-only">Nuove opportunità per i materiali</span></div>@endforelse
</div>
</section>
<section class="mt-10" aria-labelledby="home-categories"><h2 id="home-categories">Esplora le categorie</h2><div class="mt-5 flex flex-wrap gap-3">
@foreach($categories as $category)<a class="inline-flex min-h-14 items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3 font-semibold hover:border-brand-600" href="{{ route('marketplace.category', $category->slug) }}"><x-icon name="box" class="text-brand-700" />{{ $category->name }}</a>@endforeach
</div></section>
<section aria-label="I vantaggi" class="my-10 grid gap-4 border-y border-line py-6 sm:grid-cols-2 lg:grid-cols-4">
@foreach(['users'=>'Rivenditori verificati','wallet'=>'Prezzi da giacenza','box'=>'Spedizione o ritiro','exchange'=>'Scambio tra rivenditori'] as $icon=>$label)<div class="flex items-center gap-3 font-semibold"><span class="rounded-xl bg-brand-50 p-3 text-brand-700"><x-icon :name="$icon" /></span>{{ $label }}</div>@endforeach
</section>
<section id="deals" aria-labelledby="deals-title"><p class="brand-eyebrow">MENO COSTI, PIÙ POSSIBILITÀ</p><h2 id="deals-title" class="mt-2">Occasioni da non perdere</h2><div class="product-rail mt-5">
@forelse($deals as $item)<x-marketplace-card :item="$item" />@empty
<p class="text-muted">Le nuove occasioni saranno disponibili qui.</p>@endforelse
</div></section>
<section class="promo-navy my-12 rounded-3xl p-7 sm:p-10"><p class="text-sm font-semibold text-[#75efdf]">DA GIACENZA A OPPORTUNITÀ</p><h2 class="mt-3 text-3xl leading-tight">Più spazio in magazzino.<br>Più liquidità.<br>Zero sprechi.</h2><a href="{{ route('register') }}" class="btn mt-6 bg-brand-600 text-brand-950">Scopri come vendere <x-icon name="arrow" /></a></section>
@if($featured->isNotEmpty())<section class="my-10"><h2>In evidenza</h2><div class="product-rail mt-5">
@foreach($featured as $item)<x-marketplace-card :item="$item" />@endforeach
</div></section>@endif
<section aria-labelledby="recent-title"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="recent-title">Appena pubblicati</h2><p class="mt-1 text-muted">Nuovi materiali, direttamente dai rivenditori.</p></div><a class="btn btn-secondary" href="{{ route('marketplace.index') }}">Tutto il catalogo <x-icon name="arrow" /></a></div><div class="product-rail mt-5">
@forelse($items as $item)<x-marketplace-card :item="$item" />@empty
<p class="text-muted">Le prime giacenze approvate arriveranno qui.</p>@endforelse
</div></section>
<section class="my-12" aria-labelledby="brands-title"><h2 id="brands-title">I marchi che trovi su ZeroMagazzino</h2><div class="mt-5 flex flex-wrap gap-3">
@forelse($brands as $brand)<span class="rounded-xl border border-line bg-raised px-5 py-4 font-bold">{{ $brand }}</span>@empty
<p class="text-muted">I marchi dei prodotti pubblicati compariranno qui.</p>@endforelse
</div></section>
<section class="rounded-3xl border border-line bg-brand-50 p-6 sm:p-9"><x-icon name="exchange" class="size-10 text-brand-700" /><h2 class="mt-4 text-3xl">Scambia invece di acquistare.</h2><p class="mt-3 max-w-2xl text-muted">Con ZeroMagazzino PRO puoi proporre scambi tra rivenditori e trasformare le giacenze ferme in nuove opportunità.</p><x-button class="mt-5" :href="route('marketplace.index', ['exchange_available'=>1])">Scopri i prodotti scambiabili</x-button></section>
@if($exchangeItems->isNotEmpty())<div class="product-rail mt-6" aria-label="Prodotti scambiabili">
@foreach($exchangeItems as $item)<x-marketplace-card :item="$item" />@endforeach
</div>@endif
@foreach($categorySections as $section)@if($section['items']->isNotEmpty())<section class="mt-12"><h2>{{ $section['title'] }}</h2><div class="product-rail mt-5">
@foreach($section['items'] as $item)<x-marketplace-card :item="$item" />@endforeach
</div></section>@endif
@endforeach
<section class="my-12 rounded-3xl border border-line bg-raised p-5 sm:p-8" aria-labelledby="reviews-title"><p class="brand-eyebrow">TESTIMONIANZE DIMOSTRATIVE</p><h2 id="reviews-title" class="mt-2">Cosa dicono di ZeroMagazzino</h2><p class="mt-3 text-lg font-bold">{{ number_format(collect(config('marketplace_reviews'))->avg('rating'), 1, ',', '.') }}/5 <span class="text-sm font-normal text-muted">· {{ count(config('marketplace_reviews')) }} recensioni demo</span></p><p class="mt-2 text-sm text-muted">Nomi fittizi e testi di esempio: queste recensioni demo non rappresentano esperienze verificate.</p><div class="product-rail mt-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700" tabindex="0" role="region" aria-label="Recensioni demo">
@foreach(config('marketplace_reviews') as $review)<article class="rounded-2xl border border-line bg-surface p-5"><div class="flex items-center justify-between gap-2"><span role="img" class="text-brand-700" aria-label="{{ $review['rating'] }} stelle su 5"><span aria-hidden="true">{{ str_repeat('★', $review['rating']) }}{{ str_repeat('☆', 5-$review['rating']) }}</span></span><span class="rounded-full bg-raised px-2 py-1 text-xs font-semibold">Demo</span></div><p class="my-4 text-sm leading-relaxed">{{ $review['text'] }}</p><p class="font-semibold">{{ $review['name'] }}</p><p class="mt-1 text-xs text-muted">{{ $review['business'] }}</p></article>@endforeach
</div></section>
<section class="promo-navy rounded-3xl p-7 sm:p-10"><h2 class="text-3xl">Hai materiali fermi in magazzino?</h2><p class="mt-3 text-[#b2c3d6]">Trasformali in liquidità o usali per uno scambio.</p><a class="btn mt-6 bg-brand-600 text-brand-950" href="{{ route('register') }}">Diventa rivenditore <x-icon name="arrow" /></a></section>
</x-marketplace-layout>
