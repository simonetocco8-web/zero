@props(['data', 'title' => 'Riepilogo acquisto'])
<section class="rounded-2xl border border-line bg-raised p-5" aria-label="{{ $title }}" data-purchase-summary>
    <h2>{{ $title }}</h2>
    <dl class="mt-4 space-y-3">
        <div class="flex flex-wrap justify-between gap-2"><dt>Subtotale merce (prezzi indicati)</dt><dd>{{ \App\Support\DisplayMoney::euros($data['goods_input_cents']) }}</dd></div>
        @if($data['goods_cents'] !== null && $data['goods_cents'] !== $data['goods_input_cents'])<div class="flex flex-wrap justify-between gap-2"><dt>Merce con imposte</dt><dd>{{ \App\Support\DisplayMoney::euros($data['goods_cents']) }}</dd></div>@endif
        <div class="flex flex-wrap justify-between gap-2"><dt>Spedizioni calcolate{{ ($data['dynamic'] ?? false) || $data['checkout_blocked'] ? ' · indicative' : '' }}</dt><dd>{{ $data['shipping_estimate_cents'] === null ? 'Da calcolare' : \App\Support\DisplayMoney::euros($data['shipping_estimate_cents']) }}</dd></div>
        <div class="flex flex-wrap justify-between gap-2 border-t border-line pt-3 font-bold"><dt>Totale complessivo stimato</dt><dd data-estimated-total>{{ $data['total_cents'] === null ? 'Non ancora disponibile' : \App\Support\DisplayMoney::euros($data['total_cents']) }}</dd></div>
    </dl>
    @if($data['shipping_missing'])<p class="mt-3 font-semibold">Spedizione da calcolare. Scegli una tariffa per ogni rivenditore oppure il ritiro.</p>@endif
    @if($data['tax_pending'])<p class="mt-3 text-sm">Gli importi indicati non comprendono eventuali imposte ancora da determinare. Il totale sarà disponibile quando tutti i costi saranno definiti.</p>@endif
    @if($data['unavailable'])<p class="mt-3 text-sm text-danger">Controlla disponibilità, quantità e modalità di consegna degli articoli prima di continuare.</p>@endif
    @if($data['checkout_blocked'])<p class="mt-3 text-sm">Le tariffe di spedizione sono indicative. L’acquisto con questa spedizione non è ancora disponibile; puoi modificare la consegna o scegliere il ritiro, se previsto.</p>@endif
</section>
