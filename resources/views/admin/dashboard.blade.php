<x-layouts.admin title="Dashboard">
    <h1>Dashboard</h1>
    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($counts as $label => $count)
            <x-card><p class="text-sm text-muted">{{ $label }}</p><p class="mt-2 text-2xl font-semibold">{{ $count }}</p></x-card>
        @endforeach
    </div>
    <x-card class="mt-5">
        <h2 id="recent-audit-title">Audit recente</h2>
        <div class="mt-4 min-w-0 max-w-full overflow-x-auto" role="region" aria-labelledby="recent-audit-title" tabindex="0">
            <table class="admin-table min-w-[44rem]">
                <caption class="sr-only">Ultime 10 azioni amministrative</caption>
                <thead><tr>@foreach(['Azione', 'Operatore', 'Risorsa', 'ID', 'Data / Ora'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="font-semibold">{{ match($log->action->value) {
                                'retailer_approved' => 'Rivenditore approvato',
                                'retailer_rejected' => 'Rivenditore rifiutato',
                                'retailer_suspended' => 'Rivenditore sospeso',
                                'retailer_password_reset' => 'Password rivenditore ripristinata',
                                'inventory_approved' => 'Giacenza approvata',
                                'inventory_rejected' => 'Giacenza rifiutata',
                                'inventory_published' => 'Prodotto pubblicato nel negozio simulato',
                                'payout_paid' => 'Bonifico segnato come pagato',
                                'payout_rejected' => 'Bonifico rifiutato',
                                default => 'Azione amministrativa',
                            } }}</td>
                            <td>{{ $log->actor?->name ?? 'Operatore non disponibile' }}</td>
                            <td>{{ match(class_basename($log->subject_type)) { 'Retailer' => 'Rivenditore', 'InventoryItem' => 'Giacenza', 'PayoutRequest' => 'Bonifico', default => 'Risorsa' } }}</td>
                            <td>#{{ $log->subject_id }}</td>
                            <td class="whitespace-nowrap"><time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('d/m/Y H:i') }}</time></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Nessuna azione amministrativa registrata.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.admin>
