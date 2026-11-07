<x-layouts.retailer title="Profilo"><div class="mx-auto max-w-5xl space-y-6"><h1>Profilo</h1><x-retailer-status :retailer="$retailer" />
@if(session('status'))<x-alert variant="success">{{ session('status') }}</x-alert>@endif
<x-card><h2 class="mb-5">Dati aziendali</h2><form id="retailer-profile" method="POST" action="{{ route('retailer.profile.update') }}" class="space-y-5">@csrf @method('PUT')
@if(config('shipping.enabled') && (!$retailer->postal_code || !$retailer->country_code || !$retailer->address || !$retailer->province))<x-alert>Completa indirizzo, CAP, sigla provincia e Paese di partenza per consentire i preventivi.</x-alert>@endif
<x-company-fields :retailer="$retailer" />
<x-button type="submit">Salva profilo</x-button></form></x-card>
<x-retailer-bank-details :retailer="$retailer" :verification="$bankVerification" :active="$activeBankVerification" />
</div></x-layouts.retailer>
