@if($errors->has('company_exists'))
<x-alert variant="danger" title="Azienda già registrata" class="mb-5">
<p>{{ \App\Support\RegistrationMessages::DUPLICATE_VAT }}</p>
<div class="mt-3 flex flex-wrap gap-3"><x-button variant="secondary" :href="route('login')">Accedi</x-button><x-button variant="secondary" :href="route('password.request')">Recupera password</x-button></div>
</x-alert>
@endif
