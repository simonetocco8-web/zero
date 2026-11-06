<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryImportOptionsRequest;
use App\Http\Requests\InventoryImportUploadRequest;
use App\Models\Category;
use App\Models\InventoryImport;
use App\Models\InventoryImportTemplate;
use App\Services\InventoryImport\ImportFields;
use App\Services\InventoryImport\ImportWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class InventoryImportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('create', InventoryImport::class);

        return view('inventory-imports.index', ['imports' => InventoryImport::where('retailer_id', $request->user()->retailer->id)->latest()->paginate(15), 'templates' => InventoryImportTemplate::where('retailer_id', $request->user()->retailer->id)->orderBy('name')->get()]);
    }

    public function store(InventoryImportUploadRequest $request, ImportWorkflow $workflow)
    {
        $import = $workflow->upload($request->user(), $request->file('file'), $request->safe()->except('file'));

        return to_route('retailer.imports.show', [$import, 'step' => 'mapping']);
    }

    public function show(Request $request, InventoryImport $import, ImportWorkflow $workflow, ImportFields $fields)
    {
        Gate::authorize('view', $import);
        $step = $import->editable() ? $request->query('step', $import->status === 'validated' ? 'preview' : ($import->status === 'mapped' ? 'configuration' : 'mapping')) : ($import->status === 'validating' ? 'preview' : 'results');
        abort_unless(in_array($step, ['upload', 'mapping', 'configuration', 'preview', 'results']), 404);
        if (in_array($step, ['configuration', 'preview']) && ! isset($import->settings['mapping'])) {
            return to_route('retailer.imports.show', [$import, 'step' => 'mapping']);
        }
        $query = $import->rows()->orderBy('position');
        $filter = $request->query('status');
        if (in_array($filter, ['valid', 'duplicate', 'error', 'limit', 'created', 'updated'])) {
            $query->where('status', $filter);
        }
        $categories = Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $categoryValues = [];
        if (isset($import->settings['mapping']['category']['column']) && $step === 'configuration') {
            foreach ($import->rows()->cursor() as $row) {
                $v = $fields->value($row->cells, $import->settings['mapping']['category']);
                $categoryValues[$v ?? ''] = true;
            }
        }

        return view('inventory-imports.show', ['import' => $import, 'step' => $step, 'rows' => $query->paginate(30)->withQueryString(), 'sample' => $import->rows()->orderBy('position')->limit(10)->get(), 'fields' => ImportFields::LABELS, 'required' => ImportFields::REQUIRED, 'suggestions' => $fields->suggestions($import->headers), 'categories' => $categories, 'categoryValues' => array_keys($categoryValues), 'counts' => $workflow->counts($import), 'numberSuggestion' => $fields->numberSuggestion($import->rows()->orderBy('position')->limit(10)->get(), $import->settings['mapping'] ?? []), 'workerReady' => $workflow->workerReady()]);
    }

    public function options(InventoryImportOptionsRequest $request, InventoryImport $import, ImportWorkflow $workflow)
    {
        Gate::authorize('update', $import);
        $workflow->reread($request->user(), $import, $request->validated());

        return to_route('retailer.imports.show', [$import, 'step' => 'mapping']);
    }

    public function mapping(Request $request, InventoryImport $import, ImportWorkflow $workflow)
    {
        Gate::authorize('update', $import);
        $data = $request->validate(['sources' => ['required', 'array'], 'fixed' => ['sometimes', 'array'], 'confirmed' => ['accepted']]);
        $workflow->configure($request->user(), $import, 'mapping', $data);

        return to_route('retailer.imports.show', [$import, 'step' => 'configuration']);
    }

    public function configuration(Request $request, InventoryImport $import, ImportWorkflow $workflow)
    {
        Gate::authorize('update', $import);
        $input = $request->all();
        if ($request->filled('category_links_json')) {
            $request->validate(['category_links_json' => ['string', 'json', 'max:2000000']]);
            $input['category_links'] = json_decode($request->input('category_links_json'), true, 512, JSON_THROW_ON_ERROR);
        }
        $workflow->configure($request->user(), $import, 'configuration', $input);
        $workflow->requestPreview($request->user(), $import->fresh());

        return to_route('retailer.imports.show', [$import, 'step' => 'preview']);
    }

    public function confirm(Request $request, InventoryImport $import, ImportWorkflow $workflow)
    {
        Gate::authorize('update', $import);
        $data = $request->validate(['token' => ['required', 'string', 'size:64'], 'intent' => ['required', 'in:draft,pending'], 'confirmed' => ['accepted']]);
        $workflow->confirm($request->user(), $import, $data['token'], $data['intent']);

        return to_route('retailer.imports.show', $import);
    }

    public function resume(Request $request, InventoryImport $import, ImportWorkflow $workflow)
    {
        Gate::authorize('update', $import);
        abort_unless($workflow->workerReady(), 422, 'Worker importazioni non operativo.');
        DB::transaction(function () use ($import, $workflow) {
            $import = InventoryImport::lockForUpdate()->findOrFail($import->id);
            abort_unless($import->status === 'failed' || in_array($import->status, ['validating', 'queued', 'processing']) && $import->heartbeat_at?->lt(now()->subMinutes(5)), 409);
            $import->update(['status' => $import->operation_hash ? 'queued' : 'validating', 'failure' => null, 'heartbeat_at' => now()]);
            $import->operation_hash ? $workflow->enqueue($import->id) : $workflow->enqueueValidation($import->id);
        });

        return to_route('retailer.imports.show', $import);
    }

    public function template(Request $request, InventoryImport $import)
    {
        Gate::authorize('update', $import);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        abort_unless(isset($import->settings['category_mode']), 409);
        InventoryImportTemplate::create(['retailer_id' => $import->retailer_id, 'name' => $data['name'], 'settings' => array_merge($import->settings, ['headers' => $import->headers])]);

        return back()->with('status', 'Modello di importazione salvato.');
    }

    public function download(InventoryImport $import)
    {
        Gate::authorize('view', $import);
        abort_unless($import->path && Storage::disk('local')->exists($import->path), 410, 'Il file temporaneo è stato eliminato; il report resta disponibile.');

        return Storage::disk('local')->download($import->path, 'importazione-'.$import->id.'.csv', ['Content-Type' => 'text/csv', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function report(InventoryImport $import)
    {
        Gate::authorize('view', $import);

        return response()->streamDownload(function () use ($import) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Riga originale', 'Codice articolo', 'Nome articolo', 'Campo', 'Motivo', 'Esito'], ';', '"', '');
            foreach ($import->rows()->whereIn('status', ['duplicate', 'error', 'limit'])->orderBy('position')->cursor() as $row) {
                foreach ($row->errors ?? [] as $field => $messages) {
                    foreach ($messages as $message) {
                        $cells = [$row->source_line, $row->sku, $row->name, $field, $message, $row->status];
                        $cells = array_map(fn ($value) => preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', (string) $value) || preg_match('/^[\t\r\n]/', (string) $value) ? "'".$value : (string) $value, $cells);
                        fputcsv($out, $cells, ';', '"', '');
                    }
                }
            }
            fclose($out);
        }, 'report-importazione-'.$import->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
