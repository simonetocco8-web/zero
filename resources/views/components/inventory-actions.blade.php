@props(['item'])
<div class="flex flex-wrap gap-2"><x-button variant="secondary" :href="route('retailer.stock.show',$item)">Visualizza</x-button>@can('update',$item)<x-button variant="secondary" :href="route('retailer.stock.edit',$item)">Modifica</x-button>@endcan
@can('archive',$item)<x-admin-confirm :name="'archive-'.$item->id.'-'.Str::uuid()" label="Archivia" :url="route('retailer.stock.archive',$item)" variant="danger" />@endcan</div>

@can('managePhotos',$item)<x-button class="mt-3" variant="secondary" :href="route('inventory.photos.edit',$item)">Gestisci foto</x-button>@endcan
@can('delete',$item)<x-admin-confirm :name="'delete-item-'.$item->id.'-'.Str::uuid()" label="Elimina articolo" :url="route('inventory.destroy',$item)" method="DELETE" variant="danger" :message="'Vuoi eliminare l’articolo «'.$item->name.'»? Non sarà più disponibile nel marketplace o nelle tue liste. Ordini, scambi e storico saranno conservati. Le prenotazioni attive impediscono l’eliminazione.'" />@endcan
