<x-marketplace-layout title="Dai nuovo valore alle tue giacenze" description="ZeroMagazzino, il marketplace per rivendite edili: vendi l'invenduto, trasforma le giacenze in liquidità e scambia prodotti." :entry="true" :canonical="route('home')">
    <section class="brand-hero px-5 py-10 sm:px-10 sm:py-16 lg:px-14" aria-labelledby="welcome-title">
        <div class="grid items-center gap-10 lg:grid-cols-[1.3fr_1fr] lg:gap-16">
            <div>
                <p class="brand-eyebrow">Il marketplace per le rivendite edili</p>
                <h1 id="welcome-title" class="mt-5 max-w-2xl text-4xl leading-tight sm:text-5xl lg:text-6xl">Il tuo invenduto.<br><span class="text-brand-600">La prossima opportunità.</span></h1>
                <p class="mt-6 max-w-xl text-base leading-relaxed text-muted sm:text-lg">Dai spazio a nuovi progetti. Vendi i materiali fermi in magazzino, trasforma le giacenze in liquidità e trova prodotti da scambiare.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <x-button :href="route('login')">Accedi <x-icon name="arrow" /></x-button>
                    <x-button variant="secondary" :href="route('register')">Registrati</x-button>
                </div>
                <p class="mt-5 max-w-lg text-sm leading-relaxed text-muted">Marketplace riservato agli utenti autenticati. Le rivendite accedono dopo l’approvazione dell’account. In registrazione puoi scegliere anche il profilo cliente.</p>
            </div>
            <div class="grid gap-4" aria-label="Le opportunità ZeroMagazzino">
                @foreach([
                    ['icon' => 'box', 'title' => 'Vendi l’invenduto', 'text' => 'Metti in circolo i materiali edili che non utilizzi e libera spazio in magazzino.'],
                    ['icon' => 'wallet', 'title' => 'Trasforma le giacenze in liquidità', 'text' => 'Dai valore alle scorte ferme e crea nuove opportunità per la tua rivendita.'],
                    ['icon' => 'exchange', 'title' => 'Scambia prodotti', 'text' => 'Con il piano PRO, proponi scambi alle altre rivendite e trova ciò che serve.'],
                ] as $benefit)
                    <div class="flex gap-4 rounded-2xl border border-line bg-canvas/60 p-5">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-icon :name="$benefit['icon']" /></span>
                        <div><h2 class="text-lg">{{ $benefit['title'] }}</h2><p class="mt-2 text-sm leading-relaxed text-muted">{{ $benefit['text'] }}</p></div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</x-marketplace-layout>
