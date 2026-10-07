<x-layouts.retailer title="Dashboard"><h1 class="mb-5">Dashboard</h1><x-retailer-status :retailer="$retailer" />
@if($requested && $requested->status->value === 'pending')<x-alert class="mt-4" title="Piano richiesto: {{ $requested->plan->name }}">L’attivazione del piano a pagamento è separata dalla verifica aziendale. Nessun pagamento è stato effettuato.</x-alert>@endif
<div class="mt-6 grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-4">
@foreach(['Piano attivo'=>$plan?->name ?? 'Nessun piano attivo','Prodotti'=>$productCount,'Richieste nuove'=>$newRequestCount,'Valore magazzino'=>\App\Support\DisplayMoney::euros($inventoryValue),'Commissione corrente'=>$plan ? \App\Support\DisplayMoney::percent($plan->commission_basis_points) : 'In attesa di attivazione','Credito disponibile'=>\App\Support\DisplayMoney::euros($credit)] as $label=>$value)
<x-card><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 break-words text-xl font-semibold">{{ $value }}</p></x-card>@endforeach
</div>
<p class="mt-3 text-sm text-slate-500">Valori in EUR; le giacenze archiviate sono escluse.</p>
<div class="mt-6 flex flex-wrap gap-3"><x-button variant="secondary" :href="route('retailer.profile')">Modifica profilo</x-button>@can('operate',$retailer)<x-button :href="route('retailer.sell')">Metti in vendita</x-button>@endcan</div>
<x-card class="mt-6"><h2>Richieste recenti</h2><div class="mt-4 space-y-3">@forelse($recentRequests as $recent)<article class="rounded-xl border border-slate-200 p-4"><p class="font-semibold">{{ $recent->inventoryItem->name }}</p><p class="text-sm">{{ $recent->customer_name }} · {{ $recent->created_at->format('d/m/Y') }}</p><x-badge>{{ match($recent->status->value) {'new'=>'Nuova','contacted'=>'Contattato',default=>'Chiusa'} }}</x-badge></article>@empty<x-empty-state title="Nessuna richiesta" description="Le richieste dei clienti compariranno qui." />@endforelse</div></x-card>
</x-layouts.retailer>
