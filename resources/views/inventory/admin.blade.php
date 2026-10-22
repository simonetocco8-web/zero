<x-layouts.admin title="Giacenze">
    <h1 id="stock-title">Giacenze</h1>
    <x-admin-filters :statuses="['draft'=>'Bozza','pending'=>'In verifica','published'=>'Pubblicato','change_pending'=>'Modifica in verifica','rejected'=>'Rifiutato','archived'=>'Archiviato']" />
    <div class="min-w-0 max-w-full overflow-x-auto rounded-2xl border border-line bg-surface" role="region" aria-labelledby="stock-title" tabindex="0">
        <table class="admin-table min-w-[76rem]">
            <caption class="sr-only">Giacenze e verifica amministrativa</caption>
            <thead><tr>@foreach(['ID', 'Prodotto', 'Rivenditore', 'Categoria', 'Quantità', 'Prezzo Zero', 'Stato', 'Data richiesta', 'Verifica', 'Dettaglio'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse($items as $item)
                    @php($data = $item->proposed_data ?? $item->getAttributes())
                    <tr>
                        <td>#{{ $item->id }}</td>
                        <th scope="row" class="font-semibold">{{ $data['name'] }}</th>
                        <td>{{ $item->retailer->company_name }}</td>
                        <td>{{ $data['category'] ?? '—' }}</td>
                        <td>{{ \App\Support\DisplayQuantity::format($data['quantity_milliunits']) }}</td>
                        <td class="whitespace-nowrap">{{ \App\Support\DisplayMoney::euros($data['zero_price_cents']) }}</td>
                        <td><x-inventory-status :item="$item" /></td>
                        <td class="whitespace-nowrap">{{ ($item->submitted_at ?? $item->created_at)->format('d/m/Y H:i') }}</td>
                        <td>
                            @if(in_array($item->status->value, ['pending', 'change_pending']))
                                <div class="flex flex-wrap gap-2">
                                    <x-admin-confirm name="stock-approve-{{ $item->id }}" label="APPROVA E PUBBLICA" :url="route('admin.stock.review', $item)" decision="published" />
                                    <x-admin-confirm name="stock-reject-{{ $item->id }}" label="RIFIUTA" :url="route('admin.stock.review', $item)" decision="rejected" variant="danger" :reason="true" />
                                </div>
                            @else
                                <span class="text-muted">Non richiesta</span>
                            @endif
                        </td>
                        <td><x-button type="button" variant="secondary" x-data x-on:click="$dispatch('open-modal', 'stock-detail-{{ $item->id }}')" aria-label="Dettaglio giacenza #{{ $item->id }}" title="Dettaglio giacenza"><x-icon name="eye" /></x-button></td>
                    </tr>
                @empty
                    <tr><td colspan="10">Nessuna giacenza corrisponde ai filtri.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @foreach($items as $item)
        @include('inventory.partials.admin-detail', ['item' => $item])
    @endforeach
    <div class="mt-5">{{ $items->links() }}</div>
</x-layouts.admin>
