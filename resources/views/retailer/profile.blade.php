<x-layouts.retailer title="Profilo"><h1 class="mb-5">Profilo</h1><x-retailer-status :retailer="$retailer" />
@if(session('status'))<x-alert variant="success" class="mt-4">{{ session('status') }}</x-alert>@endif
<x-card class="mt-6"><form method="POST" action="{{ route('retailer.profile.update') }}" class="space-y-5">@csrf @method('PUT')
<x-company-fields :retailer="$retailer" />
@if($retailer->iban)<p class="text-sm">IBAN salvato: •••• {{ substr($retailer->iban, -4) }}</p>@endif
<x-field name="iban" label="Nuovo IBAN" autocomplete="off" hint="Lascia vuoto per conservare l’IBAN salvato. Le coordinate sono cifrate." />
<x-button type="submit">Salva profilo</x-button></form></x-card>
</x-layouts.retailer>
