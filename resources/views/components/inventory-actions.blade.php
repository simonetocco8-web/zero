@props(['item', 'iconOnly' => false])
<div class="flex flex-wrap gap-2">
<x-button variant="secondary" :href="route('retailer.stock.show',$item)" :aria-label="$iconOnly ? 'Visualizza' : null" :title="$iconOnly ? 'Visualizza' : null">@if($iconOnly)<x-icon name="eye" />@else Visualizza @endif</x-button>
@can('update',$item)<x-button variant="secondary" :href="route('retailer.stock.edit',$item)" :aria-label="$iconOnly ? 'Modifica' : null" :title="$iconOnly ? 'Modifica' : null">@if($iconOnly)<x-icon name="edit" />@else Modifica @endif</x-button>@endcan
@can('archive',$item)<x-admin-confirm :name="'archive-'.$item->id.'-'.Str::uuid()" label="Archivia" :icon="$iconOnly ? 'archive' : null" :url="route('retailer.stock.archive',$item)" variant="danger" />@endcan
@can('managePhotos',$item)<x-button variant="secondary" :href="route('inventory.photos.edit',$item)" :aria-label="$iconOnly ? 'Gestisci foto' : null" :title="$iconOnly ? 'Gestisci foto' : null">@if($iconOnly)<x-icon name="photos" />@else Gestisci foto @endif</x-button>@endcan
@can('delete',$item)<x-admin-confirm :name="'delete-item-'.$item->id.'-'.Str::uuid()" label="Elimina articolo" :icon="$iconOnly ? 'trash' : null" :url="route('inventory.destroy',$item)" method="DELETE" variant="danger" :message="'Vuoi eliminare l’articolo «'.$item->name.'»? Non sarà più disponibile nel marketplace o nelle tue liste. Ordini, scambi e storico saranno conservati. Le prenotazioni attive impediscono l’eliminazione.'" />@endcan
</div>
