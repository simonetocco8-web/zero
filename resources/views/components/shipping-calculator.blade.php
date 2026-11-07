@props(['item' => null, 'retailerId' => null])
<div x-data="{ busy: false, error: '', rates: [], informational: false, selected: '', alternate: false,
 async calculate() {
    this.busy = true; this.error = ''; this.rates = []; this.selected = '';
    const form = new FormData(this.$refs.calculation);
    if (!this.alternate) { for (const key of [...form.keys()]) { if(key.startsWith('address[')) form.delete(key); } }
    else { form.delete('address_id'); }
    try {
        const response = await fetch(@js(route('shipping.quote')), {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token())}, body: form});
        const result = await response.json();
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || 'Quotazione non disponibile. Riprova.');
        this.rates = result.quotes; this.informational = result.informational;
        if (!this.rates.length) this.error = 'Nessuna tariffa disponibile per questa spedizione.';
    } catch (error) { this.error = error.message || 'Connessione non disponibile. Riprova.'; }
    finally { this.busy = false; }
 }}">
<button type="button" class="btn btn-secondary min-h-11" x-ref="opener" @click="$refs.dialog.showModal()">Calcola Spedizione</button>
<dialog x-ref="dialog" @close="$refs.opener.focus()" class="m-auto max-h-[90dvh] w-[min(95vw,42rem)] overflow-y-auto rounded-xl border border-white/20 bg-slate-900 p-5 text-white backdrop:bg-black/70" aria-label="Calcola Spedizione">
<div class="flex items-center justify-between gap-3"><h2>Calcola Spedizione</h2><button class="min-h-11 px-3" type="button" @click="$refs.dialog.close()" aria-label="Chiudi calcolatore">Chiudi</button></div>
@if($item)<p class="my-3 font-semibold">{{ $item->name }}</p><p>Imballaggio: {{ $item->length_mm ?? '—' }} × {{ $item->width_mm ?? '—' }} × {{ $item->height_mm ?? '—' }} mm · {{ $item->weight_g ?? '—' }} g per collo.</p><p>Partenza: {{ $item->retailer->city }} · {{ $item->retailer->province }}.</p>@endif
<p class="my-3 text-sm">Il preventivo comprende tutti i prodotti di questo rivenditore presenti nel carrello. Le tariffe restano indicative fino alla verifica finale.</p>
<form x-ref="calculation" @submit.prevent="calculate" class="space-y-4" @change="rates=[];selected=''">
@if($item)<input type="hidden" name="item_id" value="{{ $item->id }}"><label class="field-label">Quantità da aggiungere<input class="field" name="quantity" value="1" inputmode="decimal" required @input="$refs.addQuantity.value=$event.target.value;rates=[];selected=''"></label>@else<input type="hidden" name="retailer_id" value="{{ $retailerId }}">@endif
<label class="field-label">Indirizzo salvato<select class="field" name="address_id" :disabled="alternate"><option value="">Seleziona un indirizzo</option>@foreach(auth()->user()->customerAddresses()->orderByDesc('is_default_shipping')->get() as $address)<option value="{{ $address->id }}" @selected($address->is_default_shipping)>{{ $address->recipient_name }} · {{ $address->address_line_1 }} · {{ $address->postal_code }} {{ $address->city }}</option>@endforeach</select></label>
<label class="flex min-h-11 items-center gap-2"><input type="checkbox" x-model="alternate"> Calcola su altro indirizzo</label>
<fieldset x-show="alternate" :disabled="!alternate" class="grid gap-3 sm:grid-cols-2"><legend>Destinazione alternativa</legend>
@foreach(['recipient_name'=>'Destinatario','address_line_1'=>'Indirizzo e numero civico','postal_code'=>'CAP','city'=>'Città','province'=>'Provincia (sigla per Italia)','country_code'=>'Paese ISO (es. IT)','phone'=>'Telefono (facoltativo)'] as $field=>$label)<label class="field-label">{{ $label }}<input class="field" name="address[{{ $field }}]" maxlength="255" value="{{ $field === 'country_code' ? 'IT' : '' }}"></label>@endforeach
<label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="save_address" value="1"> Salva questo indirizzo nella rubrica</label></fieldset>
<a class="underline" href="{{ route('customer.addresses.index') }}">Gestisci rubrica indirizzi</a>
<button type="submit" class="btn btn-primary min-h-11" :disabled="busy" x-text="busy ? 'Calcolo in corso…' : 'Calcola costo'"></button>
</form>
<p role="alert" class="my-3 text-red-300" x-text="error"></p>
<p x-show="informational && rates.length" class="my-3">Quotazione informativa: checkout con spedizione in attesa di verifica fiscale e del modello di incasso.</p>
<fieldset class="space-y-3"><legend x-show="rates.length">Scegli il servizio</legend><template x-for="(quote,index) in rates" :key="quote.id"><label class="flex min-h-11 cursor-pointer gap-3 rounded-lg border p-3"><input type="radio" x-model="selected" :value="quote.id"><span><strong x-text="quote.rate.carrier + ' · ' + quote.rate.service"></strong><span class="block" x-text="(quote.rate.final_cents/100).toLocaleString('it-IT',{style:'currency',currency:'EUR'})"></span><span class="block" x-show="index===0">Più economica</span><span class="block" x-text="quote.rate.conditions || ''"></span><span class="block" x-text="quote.rate.eta || ''"></span><span class="block" x-text="quote.rate.tax_treatment === 'included' ? 'IVA inclusa' : 'Trattamento IVA da verificare'"></span></span></label></template></fieldset>
<form method="POST" action="{{ $item ? route('cart.store',$item) : route('shipping.select') }}" x-show="selected" class="mt-4">@csrf<input type="hidden" name="shipping_quote_id" :value="selected">@if($item)<input type="hidden" name="delivery_mode" value="shipping"><input type="hidden" name="quantity" value="1" x-ref="addQuantity">@endif<x-button type="submit">{{ $item ? 'Aggiungi al carrello' : 'Conferma tariffa' }}</x-button></form>
</dialog></div>
