<x-layouts.admin title="Categoria"><h1>{{ $category->exists ? 'Modifica categoria' : 'Nuova categoria' }}</h1><x-card class="mt-5"><form method="POST" action="{{ $category->exists ? route('admin.categories.update',$category) : route('admin.categories.store') }}" class="space-y-5">@csrf @if($category->exists) @method('PUT') @endif
<x-field name="name" label="Nome" :value="$category->name" required maxlength="120" />
<x-field name="slug" label="Slug" :value="$category->slug" required maxlength="191" hint="Lettere minuscole, numeri e trattini; deve essere univoco." />
<x-textarea name="description" label="Descrizione" maxlength="10000">{{ old('description',$category->description) }}</x-textarea>
<x-field name="sort_order" label="Ordine" type="number" min="0" :value="$category->sort_order" required />
<x-checkbox name="is_active" label="Categoria attiva" :checked="$category->is_active" />
<p class="text-sm">Disattivare impedisce nuove assegnazioni, conservando quelle esistenti.</p><x-button type="submit">Salva</x-button><x-button variant="secondary" :href="route('admin.categories.index')">Annulla</x-button></form></x-card></x-layouts.admin>
