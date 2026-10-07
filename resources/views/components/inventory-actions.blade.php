@props(['item'])
<div class="flex flex-wrap gap-2"><x-button variant="secondary" :href="route('retailer.stock.show',$item)">Visualizza</x-button>@can('update',$item)<x-button variant="secondary" :href="route('retailer.stock.edit',$item)">Modifica</x-button>@endcan
@can('archive',$item)<form method="POST" action="{{ route('retailer.stock.archive',$item) }}">@csrf @method('PATCH')<x-button type="submit" variant="danger">Archivia</x-button></form>@endcan</div>
