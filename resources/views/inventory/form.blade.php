@php
$editing=$item->exists; $title=$editing ? 'Modifica giacenza':'Metti in vendita';
$quantity=isset($data['quantity_milliunits']) ? (string)\Brick\Math\BigDecimal::of($data['quantity_milliunits'])->dividedBy(1000,3):'';
$price=fn($key)=>isset($data[$key]) ? (string)\Brick\Math\BigDecimal::of($data[$key])->dividedBy(100,2):'';
@endphp
<x-layouts.retailer :title="$title"><h1 class="mb-5">{{ $title }}</h1>
@if($item->published_at)<x-alert title="La versione pubblicata resta invariata">Le modifiche saranno applicate solo dopo approvazione.</x-alert>@endif
@if($errors->any())<x-alert variant="danger" class="my-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif
<x-card class="mt-5"><form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('retailer.stock.update',$item):route('retailer.stock.store') }}" class="space-y-6" x-data="{ summary: {}, refreshSummary() { this.summary = { name: $el.elements.namedItem('name').value, quantity: $el.elements.namedItem('quantity').value, price: $el.elements.namedItem('zero_price').value }; } }" x-init="refreshSummary()" x-on:input="refreshSummary()">@csrf @if($editing) @method('PUT') @endif
<fieldset class="space-y-5"><legend class="mb-4 text-lg font-semibold">Dati essenziali</legend><div class="grid gap-5 sm:grid-cols-2">
@foreach(['name'=>'Nome prodotto','brand'=>'Marca','category'=>'Categoria','sku'=>'Codice','ean'=>'EAN','province'=>'Provincia'] as $key=>$label)<x-field :name="$key" :label="$label" :value="$data[$key]??null" :required="in_array($key,['name','category','province'])" />@endforeach
<x-select name="condition" label="Condizione" required>@foreach(['new'=>'Nuovo','end_of_line'=>'Fine serie','old_stock'=>'Fondo magazzino','damaged_packaging'=>'Imballo danneggiato'] as $value=>$label)<option value="{{ $value }}" @selected(old('condition',$data['condition']??'new')===$value)>{{ $label }}</option>@endforeach</x-select></div>
<x-textarea name="description" label="Descrizione" rows="5" maxlength="10000" required>{{ old('description',$data['description']??'') }}</x-textarea>
</fieldset>
<fieldset><legend class="mb-4 text-lg font-semibold">Foto</legend>
@if($editing)<div class="grid grid-cols-3 gap-2">@foreach($item->proposed_images??$item->images->map->only(['disk','path'])->all() as $position=>$photo)<img class="aspect-square w-full rounded-xl object-cover" src="{{ route('inventory.image',[$item,$position,'proposed'=>$item->proposed_data ? 1:0]) }}" alt="Foto attuale" loading="lazy" decoding="async">@endforeach</div>@endif
<div x-data="{ gallery: [], camera: [], get previews() { return [...this.gallery,...this.camera]; }, preview(files, source) { this[source].forEach(url => URL.revokeObjectURL(url)); this[source] = Array.from(files).map(file => URL.createObjectURL(file)); } }">
<label class="field-label" for="photos">Foto da galleria o dispositivo</label><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple @change="preview($event.target.files, 'gallery')" class="block w-full min-w-0 text-sm" aria-invalid="{{ $errors->has('photos') || $errors->has('photos.*') ? 'true' : 'false' }}" aria-describedby="photo-help photo-errors">
<label class="mt-3 flex min-h-11 items-center gap-2" for="camera">Oppure scatta una foto</label><input id="camera" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" @change="preview($event.target.files, 'camera')" class="block w-full min-w-0 text-sm" aria-invalid="{{ $errors->has('photos') || $errors->has('photos.*') ? 'true' : 'false' }}" aria-describedby="photo-help photo-errors">
<p id="photo-help" class="mt-2 text-sm text-slate-600">JPG, PNG o WebP; massimo 15 MB per foto e {{ config('inventory.max_photos') }} foto. Nuove foto sostituiscono l’intero gruppo precedente; senza nuovi file le foto restano invariate.</p>
<div class="mt-3 grid grid-cols-3 gap-2"><template x-for="url in previews" :key="url"><img :src="url" alt="Anteprima foto selezionata" class="aspect-square w-full rounded-xl object-cover"></template></div></div>
<div id="photo-errors">@error('photos')<p id="photos-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
@foreach($errors->get('photos.*') as $messages)@foreach($messages as $message)<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@endforeach @endforeach</div>
</fieldset>
<fieldset><legend class="mb-4 text-lg font-semibold">Prezzo e quantità</legend><div class="grid gap-5 sm:grid-cols-2"><x-field name="quantity" label="Quantità disponibile" :value="$quantity" inputmode="decimal" required hint="Fino a tre decimali." />
<x-field name="list_price" label="Prezzo di listino (€)" :value="$price('list_price_cents')" inputmode="decimal" />
<x-field name="zero_price" label="Prezzo ZeroMagazzino (€)" :value="$price('zero_price_cents')" inputmode="decimal" required />
</div></fieldset>
<fieldset><legend class="mb-4 text-lg font-semibold">Opzioni</legend><fieldset><legend class="field-label">Consegna</legend>@foreach(['pickup_available'=>'Ritiro in sede','shipping_available'=>'Spedizione'] as $key=>$label)<x-checkbox :name="$key" :label="$label" :checked="$data[$key]??false" />@endforeach</fieldset>
@if(auth()->user()->retailer->activeSubscription?->plan->exchange_available)<x-checkbox name="exchange_available" label="Scambio disponibile" :checked="$data['exchange_available']??false" />@else<p class="text-sm text-slate-600">Scambio disponibile solo con piano PRO attivo.</p>@endif
</fieldset>
<section aria-labelledby="submission-summary"><h2 id="submission-summary">Riepilogo e invio</h2><dl class="mt-3 grid gap-3 sm:grid-cols-3" x-cloak><div><dt class="text-sm text-slate-600">Prodotto</dt><dd class="font-semibold" x-text="summary.name || 'Da compilare'"></dd></div><div><dt class="text-sm text-slate-600">Quantità</dt><dd class="font-semibold" x-text="summary.quantity || 'Da compilare'"></dd></div><div><dt class="text-sm text-slate-600">Prezzo unitario (€)</dt><dd class="font-semibold" x-text="summary.price || 'Da compilare'"></dd></div></dl><p class="mt-2 text-sm text-slate-600">Controlla dati, foto, prezzo e consegna prima di inviare. L’articolo sarà visibile nel negozio solo dopo approvazione.</p></section>
<div class="flex flex-wrap gap-3">@if(!$item->published_at)<x-button type="submit" name="intent" value="draft" variant="secondary">Salva bozza</x-button>@endif<x-button type="submit" name="intent" value="pending">{{ $item->published_at ? 'Invia modifiche in verifica':'Invia in approvazione' }}</x-button></div>
</form></x-card></x-layouts.retailer>
