<x-app-layout>
    <h1 class="mb-6">Design system</h1>
    <div class="grid gap-6 md:grid-cols-2">
        <x-card><h2 class="mb-4">Pulsanti e stati</h2><div class="flex flex-wrap gap-3"><x-button>Primary</x-button><x-button variant="secondary">Secondary</x-button><x-button variant="danger">Danger</x-button></div><div class="my-4 flex flex-wrap gap-2"><x-badge>Bozza</x-badge><x-badge variant="success">Approvato</x-badge><x-badge variant="warning">In verifica</x-badge><x-badge variant="danger">Rifiutato</x-badge></div><x-alert>Messaggio informativo</x-alert><x-loading-state class="mt-4" /></x-card>
        <x-card><h2 class="mb-4">Campi</h2><div class="space-y-4"><x-field name="demo-title" label="Titolo" hint="Un titolo breve e riconoscibile." /><x-select name="demo-unit" label="Unità"><option>Pezzi</option><option>Metri</option></x-select><x-textarea name="demo-description" label="Descrizione" rows="3" /><x-checkbox name="demo-check" label="Conferma selezione" /></div></x-card>
        <x-card><h2 class="mb-4">Messaggi</h2><div class="flex flex-wrap gap-3"><x-button x-data x-on:click="$dispatch('open-modal', 'demo')">Apri modal</x-button><x-button variant="secondary" x-data x-on:click="$dispatch('toast', 'Esempio di notifica')">Mostra toast</x-button></div><x-modal name="demo" title="Esempio di finestra"><p class="text-sm text-slate-600">La finestra gestisce focus, tastiera e chiusura con Escape.</p></x-modal></x-card>
        <x-card><x-empty-state title="Nessun elemento" description="Gli elementi saranno mostrati qui quando disponibili." /></x-card>
    </div>
</x-app-layout>
