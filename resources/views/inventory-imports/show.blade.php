@php
$steps = ['upload'=>'Caricamento CSV', 'mapping'=>'Associazione colonne', 'configuration'=>'Valori e categorie', 'preview'=>'Anteprima e conferma', 'results'=>'Elaborazione e report'];
$position = array_search($step, array_keys($steps), true) + 1;
@endphp
<x-layouts.retailer title="Caricamento Massivo Giacenze">
    <a href="{{ route('retailer.imports.index') }}" class="btn btn-secondary mb-4">Tutte le importazioni</a>
    <h1>Caricamento Massivo Giacenze</h1>
    <nav aria-label="Passaggi importazione" class="my-5"><ol class="grid gap-2 sm:grid-cols-5">@foreach($steps as $key=>$label)<li class="rounded-xl border border-line p-3 text-sm" @if($step===$key) aria-current="step" @endif><span class="font-semibold">{{ $loop->iteration }}. {{ $label }}</span>@if($import->editable() && $loop->iteration < $position)<a class="block py-2 underline" href="{{ route('retailer.imports.show', [$import, 'step'=>$key]) }}">Torna al passaggio {{ $loop->iteration }}</a>@endif</li>@endforeach</ol></nav>
    <p class="mb-4 break-all text-sm text-muted">{{ $import->filename }} · {{ number_format($import->size / 1024, 1, ',', '.') }} KB · {{ $import->total_rows }} righe dati · {{ count($import->headers) }} colonne · {{ $import->settings['encoding'] }} · delimitatore {{ $import->settings['delimiter'] === "\t" ? 'TAB' : $import->settings['delimiter'] }}</p>
    @if($errors->any())<x-alert variant="danger" title="Controlla questi dati"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif
    @if($import->purged_at)<x-alert title="File temporaneo scaduto">Il CSV e i dati temporanei sono stati eliminati. Storico e report restano disponibili. Per una nuova anteprima, carica nuovamente il file dalla pagina delle importazioni.</x-alert>@endif
    @if(($import->settings['blank_rows'] ?? 0)>0)<p class="my-3 text-sm text-muted">{{ $import->settings['blank_rows'] }} righe vuote ignorate. Il report usa il numero di riga originale del CSV.</p>@endif
    @if(!($import->settings['template_compatible'] ?? true))<x-alert title="Struttura del CSV cambiata">Le colonne non corrispondono al modello salvato. Conferma una nuova associazione.</x-alert>@endif
    @if($step === 'upload')
        <x-card class="my-5"><h2>Opzioni di lettura</h2><p class="my-3 text-muted">Puoi rileggere lo stesso file senza caricarlo nuovamente. Le associazioni e l’anteprima precedenti saranno da riconfermare.</p>
        <form method="POST" action="{{ route('retailer.imports.options', $import) }}" class="space-y-5">@csrf<div class="grid gap-5 sm:grid-cols-2">@include('inventory-imports.options', ['settings'=>$import->settings])</div><x-button type="submit">Rileggi questo CSV</x-button></form></x-card>
    @endif
    @if(in_array($step, ['upload', 'mapping']))
        <x-card class="my-5"><h2>Prime 10 righe</h2><div class="mt-4 max-h-80 overflow-auto" tabindex="0" aria-label="Anteprima CSV scorrevole"><table class="w-full min-w-[40rem] text-left text-sm"><thead><tr><th class="p-3" scope="col">Riga CSV</th>@foreach($import->headers as $header)<th scope="col" class="p-3">{{ $header ?: 'Colonna '.$loop->iteration }}</th>@endforeach</tr></thead><tbody>@foreach($sample as $row)<tr class="border-t border-line"><th class="p-3" scope="row">{{ $row->source_line }}</th>@foreach($row->cells ?? [] as $cell)<td class="p-3"><div class="max-w-64 whitespace-pre-wrap break-words">{{ mb_substr($cell, 0, 250) }}</div></td>@endforeach</tr>@endforeach</tbody></table></div></x-card>
    @endif
    @if($step === 'mapping')
        <x-card class="my-5"><h2>2. Associazione colonne</h2><p class="my-3 text-muted">Le proposte basate sulle intestazioni sono suggerimenti. Controlla ogni campo, i valori di esempio e le associazioni prima di continuare.</p>
        <form method="POST" action="{{ route('retailer.imports.mapping', $import) }}" class="space-y-4">@csrf
        @foreach($fields as $field=>$label)
            @php($mapping = $import->settings['mapping'][$field] ?? null)
            @php($source = old('sources.'.$field, $mapping ? (isset($mapping['column']) ? (string)$mapping['column'] : 'fixed') : ($suggestions[$field] ?? '')))
            <fieldset class="min-w-0 rounded-xl border border-line p-4" x-data="{ source: @js((string)$source) }"><legend class="px-2 font-semibold">{{ $label }} <span class="text-sm font-normal text-muted">{{ in_array($field,$required) ? '(obbligatorio)' : '(facoltativo)' }}</span></legend>
                <div class="grid min-w-0 gap-4 sm:grid-cols-2"><div class="min-w-0"><label class="field-label" for="source-{{ $field }}">Origine di {{ $label }}</label><select class="field min-w-0 max-w-full" name="sources[{{ $field }}]" id="source-{{ $field }}" x-model="source"><option value="">{{ $field==='category' ? 'Categoria unica nel prossimo passaggio' : (in_array($field,$required) ? 'Seleziona un’origine' : 'Non importare') }}</option>@foreach($import->headers as $i=>$header)<option value="{{ $i }}" @selected((string)$source===(string)$i)>{{ $i+1 }}. {{ $header ?: 'Colonna '.($i+1) }} — {{ mb_substr($sample->first()?->cells[$i] ?? '',0,60) }}</option>@endforeach @if($field!=='category')<option value="fixed" @selected($source==='fixed')>Valore fisso</option>@endif</select></div>
                @if($field!=='category')<div x-show="source === 'fixed'"><label class="field-label" for="fixed-{{ $field }}">Valore fisso di {{ $label }}</label>@php($fixedValue = old('fixed.'.$field, $mapping['fixed'] ?? ''))
                    @if($field==='condition' || str_ends_with($field,'_available') || in_array($field,['vat_treatment','shipping_vat_treatment']))
                        @php($choices = $field==='condition' ? ['new'=>'Nuovo','end_of_line'=>'Fine serie','old_stock'=>'Fondo magazzino','damaged_packaging'=>'Imballo danneggiato'] : (str_ends_with($field,'_available') ? ['sì'=>'Sì','no'=>'No'] : config('marketplace.vat_treatments')))
                        <select class="field" id="fixed-{{ $field }}" name="fixed[{{ $field }}]"><option value="">Seleziona un valore</option>@foreach($choices as $value=>$choice)<option value="{{ $value }}" @selected((string)$fixedValue===(string)$value)>{{ $choice }}</option>@endforeach</select>
                    @elseif(in_array($field,['description','delivery_notes']))
                        <textarea class="field" id="fixed-{{ $field }}" name="fixed[{{ $field }}]" rows="3" maxlength="{{ $field==='description' ? 10000 : 2000 }}">{{ $fixedValue }}</textarea>
                    @else
                        <input class="field" id="fixed-{{ $field }}" name="fixed[{{ $field }}]" value="{{ $fixedValue }}" maxlength="2000">
                    @endif</div>@endif</div>
            </fieldset>
        @endforeach
        <x-alert title="Valori ammessi">Condizione: nuovo, fine serie, fondo magazzino, imballo danneggiato. Unità: ad esempio piece, kg, m, m2 (massimo 32 caratteri). Booleani: sì/no, yes/no, true/false o 1/0. Per i valori fiscali fissi scegli il trattamento IVA dal menu; nelle colonne CSV sono ammessi included (inclusa), excluded (esclusa), not_applicable (non applicabile). Aliquote esplicite tra 0 e 100. Provincia: come nel form delle giacenze (ad esempio VV o Roma). Non vengono inventati descrizioni, costi o dati fiscali.</x-alert>
        <x-checkbox name="confirmed" label="Ho controllato e confermo le associazioni e i valori fissi" required />
        <x-button type="submit">Conferma colonne e continua</x-button>
        </form></x-card>
    @endif
    @if($step === 'configuration')
        <x-card class="my-5"><h2>3. Valori e categorie</h2>
        <form method="POST" action="{{ route('retailer.imports.configuration', $import) }}" class="mt-4 space-y-5" x-data @submit="$refs.links.value = JSON.stringify(Array.from($el.querySelectorAll('[data-category-source]')).map(el => ({ source: el.dataset.categorySource, id: el.value || null })))">@csrf
            <input type="hidden" name="category_links_json" x-ref="links" value="[]">
            <x-select name="mode" label="Prodotti già presenti"><option value="new" @selected(($import->settings['mode']??'new')==='new')>Importa solo nuovi prodotti (salta duplicati)</option><option value="update" @selected(($import->settings['mode']??'')==='update')>Aggiorna prodotti esistenti tramite SKU univoco</option></x-select>
            <p class="text-sm text-muted">Gli articoli pubblicati restano invariati fino all’approvazione della proposta modifica. Gli SKU archiviati o eliminati restano riservati.</p>
            <p class="text-sm font-semibold">{{ $numberSuggestion }}</p>
            <x-select name="number_format" label="Conferma il formato dei numeri"><option value="italian" @selected(($import->settings['number_format']??'italian')==='italian')>Italiano: 1.234,50</option><option value="international" @selected(($import->settings['number_format']??'')==='international')>Internazionale: 1,234.50</option></x-select>
            <p class="text-sm text-muted">Controlla gli esempi delle colonne: quantità fino a tre decimali, prezzi fino a due. I separatori delle migliaia devono essere gruppi di tre cifre. Separa i formati misti nel gestionale prima dell’importazione.</p>
            <x-select name="category_mode" label="Modalità categorie"><option value="single" @selected(($import->settings['category_mode']??'single')==='single')>Categoria unica per tutti</option>@if(isset($import->settings['mapping']['category']))<option value="csv" @selected(($import->settings['category_mode']??'')==='csv')>Categorie dalla colonna CSV</option>@endif</x-select>
            <x-select name="category_id" label="Categoria unica"><option value="">Seleziona una categoria attiva</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string)($import->settings['category_id']??'')===(string)$category->id)>{{ $category->name }}</option>@endforeach</x-select>
            @if($categoryValues)<h3>Associa le categorie del CSV</h3><p class="text-sm text-muted">Le associazioni sotto sono usate solo nella modalità “Categorie dalla colonna CSV”. Le categorie mancanti saranno segnalate nell’anteprima.</p>
            @foreach($categoryValues as $value)
                @php($selected = collect($import->settings['category_links']??[])->firstWhere('source', (string)$value)['id']??null)
                <div><label for="category-link-{{ $loop->index }}" class="field-label">{{ $value === '' ? '(categoria vuota)' : $value }}</label><select class="field" id="category-link-{{ $loop->index }}" data-category-source="{{ $value }}"><option value="">Non associata: segnala errore</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string)$selected===(string)$category->id)>{{ $category->name }}</option>@endforeach</select></div>
            @endforeach @endif
            <x-button type="submit">Valida tutte le righe e mostra anteprima</x-button>
        </form></x-card>
    @endif
    @if($import->status==='validating')
        <x-card class="my-5"><h2>Validazione completa in corso</h2><p class="my-3" role="status" x-data x-init="setTimeout(() => window.location.reload(), 5000)">Stiamo controllando tutte le righe senza modificare le giacenze. Al termine potrai rivedere l’anteprima e confermare. La pagina si aggiorna ogni 5 secondi.</p>@if(!$workerReady)<x-alert title="Worker non rilevato">La validazione attende un worker operativo. Nessun articolo è stato importato.</x-alert>@endif</x-card>
    @endif
    @if(in_array($step, ['preview','results']))
        @include('inventory-imports.summary')
        @if($step==='preview' && $import->status==='validated')
            <x-card class="my-5"><h2>4. Conferma l’importazione</h2><p class="my-3">Solo le righe valide saranno elaborate. L’anteprima non ha creato o modificato alcuna giacenza. Quote, disponibilità e autorizzazioni saranno ricontrollate al momento del salvataggio.</p>
                @if(!$workerReady)<x-alert variant="danger" title="Worker importazioni non operativo">Non è possibile avviare l’importazione. Il gestore deve avviare la coda inventory-imports; poi ricarica questa pagina.</x-alert>@endif
                <form method="POST" action="{{ route('retailer.imports.confirm',$import) }}" class="mt-4 space-y-4">@csrf<input type="hidden" name="token" value="{{ $import->preview_token }}">
                <x-select name="intent" label="Destinazione dei prodotti"><option value="draft">Salva come bozze</option><option value="pending">Invia in approvazione</option></x-select><x-checkbox name="confirmed" label="Confermo questa anteprima e autorizzo il salvataggio delle sole righe valide" required /><x-button type="submit" :disabled="!$workerReady || !$counts['valid']">Avvia importazione</x-button></form>
            </x-card>
        @endif
        @if($step==='results')
            <x-card class="my-5"><h2>5. Elaborazione e report finale</h2><p class="my-3 font-semibold">@include('inventory-imports.status',['status'=>$import->status])</p>
                @php($done = $import->total_rows - $counts['valid'] - $counts['unvalidated'])
                <label for="import-progress">{{ $done }} di {{ $import->total_rows }} righe elaborate o escluse ({{ intdiv($done * 100, max(1,$import->total_rows)) }}%)</label><progress id="import-progress" class="mt-3 w-full" max="{{ $import->total_rows }}" value="{{ $done }}">{{ $done }}</progress>
                @if(in_array($import->status,['queued','processing']))<p class="mt-3" role="status" x-data x-init="setTimeout(() => window.location.reload(), 5000)">Elaborazione in corso. Questa pagina si aggiorna ogni 5 secondi.</p>@if(!$workerReady)<x-alert title="Worker non rilevato">La coda non risponde al momento. Le righe già completate sono conservate; l’importazione non è dichiarata completata.</x-alert>@endif @endif
                @if($import->failure)<x-alert variant="danger">{{ $import->failure }}</x-alert>@endif
                @if($import->status==='failed' || in_array($import->status,['queued','processing']) && $import->heartbeat_at?->lt(now()->subMinutes(5)))<form method="POST" action="{{ route('retailer.imports.resume',$import) }}" class="mt-4">@csrf<x-button type="submit" :disabled="!$workerReady">Riprendi dalle righe non elaborate</x-button></form>@endif
                <div class="mt-4 flex flex-wrap gap-3"><x-button :href="route('retailer.stock')">Le mie giacenze</x-button><x-button variant="secondary" :href="route('retailer.imports.report',$import)">Scarica report errori CSV</x-button></div>
            </x-card>
        @endif
        @if(isset($import->settings['category_mode']) && !$import->purged_at)<x-card class="my-5"><h2>Salva modello di importazione</h2><form method="POST" action="{{ route('retailer.imports.template',$import) }}" class="mt-3 flex flex-wrap items-end gap-4">@csrf<x-field name="name" label="Nome del modello" maxlength="120" required /><x-button type="submit" variant="secondary">Salva modello</x-button></form></x-card>@endif
        @include('inventory-imports.rows')
    @endif
</x-layouts.retailer>
