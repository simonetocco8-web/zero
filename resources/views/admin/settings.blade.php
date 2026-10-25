<x-layouts.admin title="Impostazioni"><h1>Impostazioni</h1>
<x-card class="mt-6 space-y-4"><h2>Verifica bancaria OpenAPI Trust</h2>
@if(app(\App\Services\BankVerification\OpenApiTrustClient::class)->configured())<x-badge variant="success">Servizio configurato</x-badge>@else<x-alert title="Servizio di verifica non configurato">Configura il token e l'ambiente OpenAPI Trust sul server. Gli IBAN attivi non vengono modificati in assenza di verifica.</x-alert>@endif
<p>Ambiente: {{ config('services.openapi_trust.base_url') === 'https://trust.openapi.com' ? 'Produzione' : 'Sandbox / configurazione da verificare' }}.</p>
<p class="text-sm text-muted">Le verifiche richiedono il worker della queue integrations e lo scheduler attivi. Credenziali e coordinate bancarie non sono esposte in questa pagina.</p>
</x-card></x-layouts.admin>
