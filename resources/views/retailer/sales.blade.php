<x-layouts.retailer title="Ordini ricevuti">
<h1>Ordini ricevuti</h1>
<x-order-status-filter :filters="$filters" />
@if($vendors->isEmpty())<x-card><p>Nessun ordine ricevuto.</p></x-card>@else
<p class="mb-3 text-sm text-muted">Importi relativi al tuo sottoordine.</p>
<div class="overflow-x-auto rounded-2xl border border-line bg-surface">
<table class="w-full text-left text-sm"><caption class="sr-only">Ordini ricevuti dal rivenditore</caption><thead class="bg-raised"><tr>@foreach(['ID', 'Status', 'Cliente', 'Totale Ordine'] as $label)<th scope="col" class="p-3">{{ $label }}</th>@endforeach</tr></thead><tbody>
@foreach($vendors as $vendor)<tr class="border-t border-line align-top"><th scope="row" class="p-3"><a class="inline-flex min-h-11 min-w-11 items-center gap-2 rounded-lg text-brand-700 underline" href="{{ route('retailer.sales.show',$vendor) }}" aria-label="Dettaglio sottoordine #{{ $vendor->id }}">#{{ $vendor->id }}<x-icon name="eye" /></a><p class="text-xs font-normal text-muted">Ordine #{{ $vendor->order_id }}</p></th><td class="p-3">{{ $vendor->status->label() }}</td><td class="max-w-64 break-words p-3">{{ $vendor->order->customer_snapshot['name'] ?? '—' }}</td><td class="p-3 font-semibold">{{ \App\Support\DisplayMoney::euros($vendor->total_cents) }}</td></tr>@endforeach
</tbody></table></div>
@endif
<div class="mt-5">{{ $vendors->links() }}</div>
</x-layouts.retailer>
