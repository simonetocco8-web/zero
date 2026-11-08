<x-marketplace-layout title="Riepilogo indicativo" robots="noindex,follow" :canonical="route('checkout.index')">
    <h1>Riepilogo indicativo</h1>
    <x-alert class="my-5" title="Acquisto con spedizione non ancora disponibile">Puoi calcolare e salvare le tariffe per confrontarle. Per questi prodotti non puoi ancora confermare un ordine con spedizione e non viene richiesto alcun pagamento. Se previsto, puoi scegliere il ritiro presso il rivenditore.</x-alert>
    <div class="grid min-w-0 gap-5 lg:grid-cols-2">@foreach($summary['groups'] as $group)<x-card><h2 class="mb-4">{{ $group['name'] }}</h2><ul class="mb-4 space-y-2">@foreach($group['lines'] as $line)<li>{{ $line->product->name }} · {{ \App\Support\DisplayQuantity::format($line->quantity_milliunits) }} {{ $line->product->unit }}</li>@endforeach</ul><x-seller-shipping-summary :group="$group" return-to="checkout" /></x-card>@endforeach</div>
    <div class="my-6"><x-purchase-summary :data="$summary" /></div>
    <div class="flex flex-wrap gap-3"><x-button :href="route('checkout.index')">Modifica indirizzo e consegna</x-button><x-button variant="secondary" :href="route('cart.index')">Torna al carrello</x-button></div>
</x-marketplace-layout>
