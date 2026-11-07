<x-layouts.retailer title="Caricamento Massivo Giacenze">
    <div class="mb-6"><h1>Caricamento Massivo Giacenze</h1><p class="mt-2 text-muted">Importa il CSV del tuo gestionale. Sarai tu ad associare le colonne e a confermare gli articoli prima del salvataggio.</p></div>
    @if($errors->any())<x-alert variant="danger" title="Controlla il caricamento"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif
    <x-card class="my-6">
        <h2>1. Caricamento CSV</h2>
        <p class="my-3 text-sm text-muted">Massimo {{ config('inventory_imports.max_kilobytes') / 1024 }} MB e {{ config('inventory_imports.max_rows') }} righe. Nessuna foto verrà importata. Le integrazioni del gestionale non vengono contattate.</p>
        <form method="POST" action="{{ route('retailer.imports.store') }}" enctype="multipart/form-data" class="space-y-5">@csrf
            <div class="rounded-xl border border-dashed border-line p-5" x-data="{ filename: '' }" @dragover.prevent @drop.prevent="if ($event.dataTransfer.files.length === 1) { $refs.csv.files = $event.dataTransfer.files; filename = $refs.csv.files[0].name; }">
                <label for="file" class="field-label">Seleziona o trascina un file CSV</label>
                <input id="file" x-ref="csv" type="file" name="file" accept=".csv,text/csv,text/plain" required class="field" @change="filename = $event.target.files[0]?.name || ''">
                <p class="mt-2 text-sm" x-text="filename" aria-live="polite"></p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-select name="template_id" label="Modello salvato"><option value="">Nuova configurazione</option>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</x-select>
                @include('inventory-imports.options', ['settings' => []])
            </div>
            <p class="text-sm text-muted">I modelli ripropongono le impostazioni di lettura. Le associazioni devono essere sempre confermate; se le colonne cambiano, sarà richiesta una nuova mappatura.</p>
            <x-button type="submit">Leggi CSV e continua</x-button>
        </form>
    </x-card>
    <x-card><h2 class="mb-4">Importazioni precedenti</h2>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Storico delle tue importazioni</caption><thead><tr>@foreach(['Data', 'File', 'Stato', 'Righe', 'Creati', 'Aggiornati', 'Scartati', 'Dettaglio', 'Report'] as $label)<th scope="col" class="p-3">{{ $label }}</th>@endforeach</tr></thead><tbody>
        @forelse($imports as $import)<tr class="border-t border-line"><td class="p-3 whitespace-nowrap">{{ $import->created_at->format('d/m/Y H:i') }}</td><td class="p-3 break-all">{{ $import->filename }}</td><td class="p-3">@include('inventory-imports.status', ['status' => $import->status])</td><td class="p-3">{{ $import->total_rows }}</td><td class="p-3">{{ $import->summary['created'] ?? 0 }}</td><td class="p-3">{{ $import->summary['updated'] ?? 0 }}</td><td class="p-3">{{ ($import->summary['duplicate'] ?? 0) + ($import->summary['error'] ?? 0) + ($import->summary['limit'] ?? 0) }}</td><td class="p-3"><a class="btn btn-secondary" href="{{ route('retailer.imports.show', $import) }}">Apri <span class="sr-only">{{ $import->filename }}</span></a></td><td class="p-3">@if(($import->summary['duplicate']??0)+($import->summary['error']??0)+($import->summary['limit']??0)>0)<a class="btn btn-secondary" href="{{ route('retailer.imports.report',$import) }}">Scarica report <span class="sr-only">{{ $import->filename }}</span></a>@else<span class="text-muted">—</span>@endif</td></tr>
        @empty<tr><td colspan="9" class="p-4 text-muted">Non hai ancora caricato file CSV.</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $imports->links() }}</div>
    </x-card>
</x-layouts.retailer>
