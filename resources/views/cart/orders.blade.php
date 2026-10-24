<x-layouts.customer title="I tuoi ordini" :canonical="route('orders.index')">
<h1 id="orders-title">I miei ordini</h1>
<x-order-status-filter :filters="$filters" />
<div class="mt-5 max-w-full overflow-x-auto rounded-2xl border border-line bg-surface" role="region" aria-labelledby="orders-title" tabindex="0">
<table class="admin-table min-w-[48rem]">
<caption class="sr-only">Elenco dei tuoi ordini</caption>
<thead><tr>@foreach(['ID','Data Ordine','Rivenditore','Totale','Status','Dettaglio'] as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
<tbody>@forelse($orders as $order)<tr>
<th scope="row">#{{ $order->id }}</th>
<td class="whitespace-nowrap"><time datetime="{{ $order->created_at->toDateString() }}">{{ $order->created_at->format('d/m/Y') }}</time></td>
<td class="max-w-64 break-words">{{ $order->vendorOrders->map(fn($vendor) => $vendor->retailer_snapshot['name'])->unique()->implode(', ') }}</td>
<td class="whitespace-nowrap">{{ \App\Support\DisplayMoney::euros($order->total_cents) }}</td>
<td>{{ $order->status->label() }}</td>
<td><x-button variant="secondary" :href="route('orders.show',$order)" aria-label="Ordine #{{ $order->id }}: dettaglio" title="Dettaglio ordine #{{ $order->id }}"><x-icon name="eye" /></x-button></td>
</tr>@empty<tr><td colspan="6">Nessun ordine.</td></tr>@endforelse</tbody>
</table></div>
{{ $orders->links() }}
</x-layouts.customer>
