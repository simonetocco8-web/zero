<x-layouts.admin title="Giacenze">
    <h1 id="stock-title">Giacenze</h1>
    <x-admin-filters :statuses="[\App\Enums\InventoryStatus::REVIEW_FILTER=>'Da approvare','draft'=>'Bozza','pending'=>'In verifica','published'=>'Pubblicato','change_pending'=>'Modifica in verifica','rejected'=>'Rifiutato','archived'=>'Archiviato']" />
    <p class="my-4 font-semibold" data-stock-total>{{ request('status') === \App\Enums\InventoryStatus::REVIEW_FILTER ? 'Giacenze da approvare' : 'Totale risultati' }}: {{ $items->total() }}</p>
    <p class="mb-4 text-sm text-muted">Le richieste in verifica hanno precedenza, dalla modifica più recente. Apri il dettaglio completo per confrontare le versioni prima di decidere.</p>
    <div class="min-w-0 max-w-full overflow-x-auto rounded-2xl border border-line bg-surface" role="region" aria-labelledby="stock-title" tabindex="0">
        <table class="admin-table admin-stock-table">
            <caption class="sr-only">Giacenze e verifica amministrativa</caption>
            <thead><tr>@foreach(['ID', 'Prodotto', 'Rivenditore', 'Categoria', 'Quantità', 'Prezzo Zero', 'Stato', 'Data richiesta', 'Verifica', 'Dettaglio'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse($items as $item)
                    @php($data = $item->proposed_data ?? $item->getAttributes())
                    <tr>
                        <td><span class="stock-cell-label">ID</span>#{{ $item->id }}</td>
                        <th scope="row" class="font-semibold"><span class="stock-cell-label">Prodotto</span>{{ $data['name'] }}</th>
                        <td><span class="stock-cell-label">Rivenditore</span>{{ $item->retailer->company_name }}</td>
                        <td><span class="stock-cell-label">Categoria</span>{{ $data['category'] ?? '—' }}</td>
                        <td><span class="stock-cell-label">Quantità</span>{{ \App\Support\DisplayQuantity::format($data['quantity_milliunits']) }}</td>
                        <td class="whitespace-nowrap"><span class="stock-cell-label">Prezzo Zero</span>{{ \App\Support\DisplayMoney::euros($data['zero_price_cents']) }}</td>
                        <td><span class="stock-cell-label">Stato</span><x-inventory-status-icon :item="$item" /> <x-inventory-status :item="$item" /></td>
                        <td class="whitespace-nowrap"><span class="stock-cell-label">Data richiesta</span>{{ ($item->submitted_at ?? $item->created_at)->format('d/m/Y H:i') }}</td>
                        <td><span class="stock-cell-label">Verifica</span>
                            @if($item->status->needsReview())
                                <div class="flex flex-wrap gap-2">
                                    <x-admin-confirm name="stock-approve-{{ $item->id }}" label="APPROVA E PUBBLICA" icon="check-circle" :show-label="true" error-key="decision" :url="route('admin.stock.review', $item)" decision="published" />
                                    <x-admin-confirm name="stock-reject-{{ $item->id }}" label="RIFIUTA" icon="x-circle" :show-label="true" error-key="decision" :url="route('admin.stock.review', $item)" decision="rejected" variant="danger" :reason="true" />
                                </div>
                            @else
                                <span class="text-muted">Non richiesta</span>
                            @endif
                        </td>
                        <td><span class="stock-cell-label">Dettaglio</span><x-button type="button" variant="secondary" x-data x-on:click="$dispatch('open-modal', 'stock-detail-{{ $item->id }}')" aria-label="Dettaglio giacenza #{{ $item->id }}" title="Dettaglio giacenza"><x-icon name="eye" /> Dettaglio completo</x-button></td>
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
