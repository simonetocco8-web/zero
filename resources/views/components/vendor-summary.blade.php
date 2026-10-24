@props(['vendor','showBuyer'=>false])
<h2>Sottoordine #{{ $vendor->id }}</h2><p class="mt-3">{{ $vendor->retailer_snapshot['name'] }} · {{ $vendor->status->label() }} · {{ $vendor->delivery_mode==='shipping'?'Spedizione':'Ritiro' }}</p>
@if($showBuyer)<p class="mt-3 break-words">Cliente: {{ $vendor->order->customer_snapshot['name'] ?? '—' }} · {{ $vendor->order->customer_snapshot['email'] ?? '' }}</p>@endif
<ul class="my-4 space-y-3">@foreach($vendor->items as $item)<li><strong class="break-words">{{ $item->name }}</strong><p>{{ \App\Support\DisplayQuantity::format($item->quantity_milliunits) }} {{ $item->unit }} · SKU {{ $item->sku ?: '—' }}</p><x-line-tax-breakdown :line="$item" /></li>@endforeach</ul>
@if($vendor->address_snapshot)<p class="break-words">{{ $vendor->address_snapshot['recipient_name'] }} · {{ $vendor->address_snapshot['address_line_1'] }} · {{ $vendor->address_snapshot['postal_code'] }} {{ $vendor->address_snapshot['city'] }} · {{ $vendor->address_snapshot['province'] }} {{ $vendor->address_snapshot['country_code'] }}</p>@endif
@if($vendor->carrier || $vendor->tracking_reference)<p class="mt-3 break-words">Corriere: {{ $vendor->carrier ?: '—' }} · Tracciamento: {{ $vendor->tracking_reference ?: '—' }}</p>@endif
<x-order-totals :data="$vendor" />
@if($vendor->refund)<p class="mt-3 font-semibold">Rimborso simulato: {{ \App\Support\DisplayMoney::euros($vendor->refund->amount_cents) }}.</p>@endif
