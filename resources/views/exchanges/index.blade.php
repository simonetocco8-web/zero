<x-layouts.retailer title="Scambi">
    <h1>Scambi tra rivenditori</h1>
    <p class="mt-3 text-slate-600">Proposte e riepiloghi versionati. Per iniziare, scegli un prodotto scambiabile nel marketplace.</p>
    <x-button class="mt-4" :href="route('marketplace.index')">Esplora il marketplace</x-button>
    <nav aria-label="Filtri scambi" class="my-6 flex flex-wrap gap-2">
        @foreach(['all'=>'Tutti','received'=>'Ricevuti','sent'=>'Inviati','to_accept'=>'Da accettare','payment'=>'In pagamento','completed'=>'Completati','closed'=>'Rifiutati/scaduti'] as $key=>$label)
            <a class="inline-flex min-h-11 items-center rounded-xl border px-4 py-2 {{ $tab===$key ? 'border-brand-700 bg-brand-700 text-white':'border-slate-300 bg-white text-slate-800' }}" href="{{ route('retailer.exchanges.index',['tab'=>$key]) }}" @if($tab===$key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="grid gap-4 lg:grid-cols-2">
        @forelse($exchanges as $exchange)
            <x-card>
                <h2>Scambio #{{ $exchange->id }}</h2>
                <p class="mt-2 break-words">{{ $exchange->retailerA->company_name }} ↔ {{ $exchange->retailerB->company_name }}</p>
                <p class="mt-3 font-semibold">{{ ($exchange->status->open() && $exchange->expires_at<=now() ? \App\Enums\ExchangeStatus::Expired : $exchange->status)->label() }} · Versione {{ $exchange->currentVersion->number }}</p>
                <p>Conguaglio merci: {{ \App\Support\DisplayMoney::euros($exchange->currentVersion->difference_cents) }}</p>
                @if($exchange->status->open())<p>Scadenza: {{ $exchange->expires_at->format('d/m/Y H:i') }}</p>@endif
                <x-button class="mt-4" variant="secondary" :href="route('retailer.exchanges.show',$exchange)">Apri scambio #{{ $exchange->id }}</x-button>
            </x-card>
        @empty
            <x-card><p>Nessuno scambio in questa sezione.</p></x-card>
        @endforelse
    </div>
    <div class="mt-6">{{ $exchanges->links() }}</div>
</x-layouts.retailer>
