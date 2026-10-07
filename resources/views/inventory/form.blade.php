@php
$editing=$item->exists; $title=$editing ? 'Modifica giacenza':'Metti in vendita';
$quantity=isset($data['quantity_milliunits']) ? (string)\Brick\Math\BigDecimal::of($data['quantity_milliunits'])->dividedBy(1000,3):'';
$price=fn($key)=>isset($data[$key]) ? (string)\Brick\Math\BigDecimal::of($data[$key])->dividedBy(100,2):'';
@endphp
<x-layouts.retailer :title="$title"><h1 class="mb-5">{{ $title }}</h1>
@if($item->published_at)<x-alert title="La versione pubblicata resta invariata">Le modifiche saranno applicate solo dopo approvazione.</x-alert>@endif
@if($errors->any())<x-alert variant="danger" class="my-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif
<x-card class="mt-5"><form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('retailer.stock.update',$item):route('retailer.stock.store') }}" class="space-y-6">@csrf @if($editing) @method('PUT') @endif
<div class="grid gap-5 sm:grid-cols-2">
@foreach(['name'=>'Nome prodotto','brand'=>'Marca','category'=>'Categoria','sku'=>'Codice','ean'=>'EAN','province'=>'Provincia'] as $key=>$label)<x-field :name="$key" :label="$label" :value="$data[$key]??null" :required="in_array($key,['name','category','province'])" />@endforeach
<x-field name="quantity" label="Quantità disponibile" :value="$quantity" inputmode="decimal" required hint="Fino a tre decimali." />
<x-field name="list_price" label="Prezzo di listino (€)" :value="$price('list_price_cents')" inputmode="decimal" />
<x-field name="zero_price" label="Prezzo ZeroMagazzino (€)" :value="$price('zero_price_cents')" inputmode="decimal" required />
<div><label class="field-label" for="condition">Condizione</label><select class="field" id="condition" name="condition" required>@foreach(['new'=>'Nuovo','end_of_line'=>'Fine serie','old_stock'=>'Fondo magazzino','damaged_packaging'=>'Imballo danneggiato'] as $value=>$label)<option value="{{ $value }}" @selected(old('condition',$data['condition']??'new')===$value)>{{ $label }}</option>@endforeach</select></div></div>
<fieldset><legend class="field-label">Consegna</legend>@foreach(['pickup_available'=>'Ritiro in sede','shipping_available'=>'Spedizione'] as $key=>$label)<label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key,$data[$key]??false))>{{ $label }}</label>@endforeach</fieldset>
@if(auth()->user()->retailer->activeSubscription?->plan->exchange_available)<label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="exchange_available" value="1" @checked(old('exchange_available',$data['exchange_available']??false))>Scambio disponibile</label>@else<p class="text-sm text-slate-600">Scambio disponibile solo con piano PRO attivo.</p>@endif
<div><label class="field-label" for="description">Descrizione</label><textarea class="field" name="description" id="description" rows="5" maxlength="10000" required>{{ old('description',$data['description']??'') }}</textarea></div>
@if($editing)<div class="grid grid-cols-3 gap-2">@foreach($item->proposed_images??$item->images->map->only(['disk','path'])->all() as $position=>$photo)<img class="aspect-square w-full rounded-xl object-cover" src="{{ route('inventory.image',[$item,$position,'proposed'=>$item->proposed_data ? 1:0]) }}" alt="Foto attuale">@endforeach</div>@endif
<div x-data="{ gallery: [], camera: [], get previews() { return [...this.gallery,...this.camera]; }, preview(files, source) { this[source].forEach(url => URL.revokeObjectURL(url)); this[source] = Array.from(files).map(file => URL.createObjectURL(file)); } }">
<label class="field-label" for="photos">Foto da galleria o dispositivo</label><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple @change="preview($event.target.files, 'gallery')" class="block w-full min-w-0 text-sm">
<label class="mt-3 flex min-h-11 items-center gap-2" for="camera">Oppure scatta una foto</label><input id="camera" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" @change="preview($event.target.files, 'camera')" class="block w-full min-w-0 text-sm">
<p class="mt-2 text-sm text-slate-500">JPG, PNG o WebP; massimo 15 MB per foto e {{ config('inventory.max_photos') }} foto. Nuove foto sostituiscono l’intero gruppo precedente; senza nuovi file le foto restano invariate.</p>
<div class="mt-3 grid grid-cols-3 gap-2"><template x-for="url in previews" :key="url"><img :src="url" alt="Anteprima foto selezionata" class="aspect-square w-full rounded-xl object-cover"></template></div></div>
<div class="flex flex-wrap gap-3">@if(!$item->published_at)<x-button type="submit" name="intent" value="draft" variant="secondary">Salva bozza</x-button>@endif<x-button type="submit" name="intent" value="pending">{{ $item->published_at ? 'Invia modifiche in verifica':'Invia in approvazione' }}</x-button></div>
</form></x-card></x-layouts.retailer>
