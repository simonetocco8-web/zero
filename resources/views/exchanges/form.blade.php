<x-layouts.retailer :title="$exchange ? 'Nuova controproposta' : 'Proponi uno scambio'">
    <h1>{{ $exchange ? 'Nuova controproposta' : 'Proponi uno scambio' }}</h1>
    <p class="my-4">Seleziona i prodotti di entrambi i lati e indica i valori unitari concordati delle merci. Trasporto, IVA e commissioni sono voci separate. La proposta richiederà due accettazioni del riepilogo e non riserva ancora quantità.</p>
    @if($errors->any())<x-alert variant="danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif
    <form method="POST" action="{{ $exchange ? route('retailer.exchanges.counter',$exchange) : route('retailer.exchanges.store') }}" class="space-y-6">
        @csrf
        <input type="hidden" name="selection_mode" value="checkboxes">
        <input type="hidden" name="key" value="{{ old('key',$key) }}">
        @if($exchange)
            <input type="hidden" name="base_version_id" value="{{ $version->id }}">
            <input type="hidden" name="base_summary_hash" value="{{ $version->summary_hash }}">
        @else
            <input type="hidden" name="requested_item_id" value="{{ $requested->id }}">
        @endif
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach(['a'=>$a,'b'=>$b] as $side=>$owner)
                <x-card>
                    <h2>Lato {{ strtoupper($side) }} · {{ $owner->company_name }}</h2>
                    <fieldset class="mt-4 space-y-4">
                        <legend class="field-label">Prodotti offerti dal lato {{ strtoupper($side) }}</legend>
                        @forelse($products->where('retailer_id',$owner->id) as $product)
                            @php
                                $existing=$version?->lines->firstWhere('inventory_item_id',$product->id);
                                $oldLines=collect(old('lines.'.$side,[]));
                                $oldLine=$oldLines->firstWhere('inventory_item_id',(string)$product->id);
                                $selected=$errors->any() ? $oldLine!==null : ($existing!==null || (!$exchange && $side==='b' && $product->id===$requested->id));
                                $quantity=$oldLine['quantity'] ?? ($existing ? \App\Support\DisplayQuantity::format($existing->quantity_milliunits) : \App\Support\DisplayQuantity::input(min(1000,$product->available_quantity_milliunits)));
                                $value=$oldLine['value'] ?? (string)\Brick\Math\BigDecimal::of($existing?->agreed_unit_value_cents ?? $product->zero_price_cents)->dividedBy(100,2);
                                $prefix='lines['.$side.']['.$product->id.']';
                            @endphp
                            <div class="rounded-xl border border-line p-4" x-data="{selected: @js($selected)}">
                                <label class="flex min-h-11 items-center gap-3 break-words"><input type="checkbox" name="{{ $prefix }}[selected]" value="1" x-model="selected" @checked($selected)> {{ $product->name }} · {{ $product->sku ?: 'senza SKU' }}</label>
                                <p class="mt-2 text-sm text-muted">Disponibile ora: {{ \App\Support\DisplayQuantity::format($product->available_quantity_milliunits) }} {{ $product->unit }}. {{ $product->vatLabel() }}</p>
                                <input type="hidden" name="{{ $prefix }}[inventory_item_id]" value="{{ $product->id }}" :disabled="!selected">
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label class="block"><span class="field-label">Quantità · {{ $product->unit }}</span><input class="field" name="{{ $prefix }}[quantity]" inputmode="decimal" value="{{ $quantity }}" :disabled="!selected" :required="selected"></label>
                                    <label class="block"><span class="field-label">Valore unitario merci (€)</span><input class="field" name="{{ $prefix }}[value]" inputmode="decimal" value="{{ $value }}" :disabled="!selected" :required="selected"></label>
                                </div>
                            </div>
                        @empty
                            <p>Non ci sono prodotti pubblicati e scambiabili per questo lato.</p>
                        @endforelse
                    </fieldset>
                    @php($delivery=$side==='a' ? $version?->delivery_a : $version?->delivery_b)
                    <fieldset class="mt-6 space-y-3">
                        <legend class="field-label">Consegna delle merci {{ strtoupper($side) }} alla controparte</legend>
                        <label class="block"><span class="field-label">Modalità</span><select class="field" name="delivery[{{ $side }}][mode]">@foreach(['pickup'=>'Ritiro presso il proprietario','shipping'=>'Spedizione alla controparte'] as $value=>$label)<option value="{{ $value }}" @selected(old('delivery.'.$side.'.mode',$delivery['mode']??'pickup')===$value)>{{ $label }}</option>@endforeach</select></label>
                        <label class="block"><span class="field-label">Costo trasporto separato (€)</span><input class="field" name="delivery[{{ $side }}][cost]" inputmode="decimal" value="{{ old('delivery.'.$side.'.cost',(string)\Brick\Math\BigDecimal::of($delivery['shipping_cost_cents']??0)->dividedBy(100,2)) }}" required></label>
                        <label class="block"><span class="field-label">Chi paga il trasporto</span><select class="field" name="delivery[{{ $side }}][payer_side]">@foreach(['a'=>'A','b'=>'B'] as $payer=>$label)<option value="{{ $payer }}" @selected(old('delivery.'.$side.'.payer_side',$delivery['payer_side']??($side==='a'?'b':'a'))===$payer)>{{ $label }}</option>@endforeach</select></label>
                        <label class="block"><span class="field-label">Pagamento trasporto</span><select class="field" name="delivery[{{ $side }}][settlement]"><option value="external" @selected(old('delivery.'.$side.'.settlement',$delivery['settlement']??'external')==='external')>Al vettore, separatamente</option><option value="sender" @selected(old('delivery.'.$side.'.settlement',$delivery['settlement']??'external')==='sender')>Rimborso al mittente nel conguaglio</option></select></label>
                        <label class="block"><span class="field-label">Destinazione concordata (obbligatoria per spedizione)</span><textarea class="field" name="delivery[{{ $side }}][destination]" maxlength="1000" rows="3">{{ old('delivery.'.$side.'.destination',$delivery['destination']??'') }}</textarea></label>
                    </fieldset>
                </x-card>
            @endforeach
        </div>
        <label class="block"><span class="field-label">Note concordate (facoltative)</span><textarea class="field" name="notes" maxlength="2000" rows="3">{{ old('notes',$version?->notes) }}</textarea></label>
        <div class="flex flex-wrap gap-3"><x-button type="submit">{{ $exchange ? 'Crea nuova versione' : 'Invia proposta' }}</x-button><x-button variant="secondary" :href="$exchange ? route('retailer.exchanges.show',$exchange) : route('marketplace.show',$requested)">Torna indietro</x-button></div>
    </form>
</x-layouts.retailer>
