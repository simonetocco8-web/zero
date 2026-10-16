@props(['vendor'])
@can('fulfil',$vendor)
    @php($next=match($vendor->status->value){'paid'=>'preparing','preparing'=>$vendor->delivery_mode==='shipping'?'shipped':'ready_for_pickup','shipped','ready_for_pickup'=>'completed',default=>null})
    @if($next)
        <form method="POST" action="{{ route(auth()->user()->role->value==='admin'?'admin.marketplace.vendors.update':'retailer.sales.update',$vendor) }}" class="mt-5 space-y-4">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $next }}">
            @if($next==='shipped')<label class="block"><span class="field-label">Corriere (facoltativo)</span><input class="field" name="carrier" maxlength="120"></label><label class="block"><span class="field-label">Codice tracciamento (facoltativo)</span><input class="field" name="tracking_reference" maxlength="191"></label>@endif
            <x-button type="submit">{{ match($next){'preparing'=>'Inizia preparazione','shipped'=>'Segna come spedito','ready_for_pickup'=>'Pronto al ritiro','completed'=>'Segna consegna completata'} }}</x-button>
        </form>
    @endif
@endcan
