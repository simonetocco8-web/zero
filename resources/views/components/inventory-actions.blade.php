@props(['item'])
<div class="flex flex-wrap gap-2"><x-button variant="secondary" :href="route('retailer.stock.show',$item)">Visualizza</x-button>@can('update',$item)<x-button variant="secondary" :href="route('retailer.stock.edit',$item)">Modifica</x-button>@endcan
@can('archive',$item)<x-admin-confirm :name="'archive-'.$item->id.'-'.Str::uuid()" label="Archivia" :url="route('retailer.stock.archive',$item)" variant="danger" />@endcan</div>
