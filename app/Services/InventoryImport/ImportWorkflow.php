<?php

namespace App\Services\InventoryImport;

use App\Actions\SaveInventoryItem;
use App\Http\Requests\InventoryRequest;
use App\Jobs\ProcessInventoryImport;
use App\Jobs\ValidateInventoryImport;
use App\Models\Category;
use App\Models\InventoryImport;
use App\Models\InventoryImportRow;
use App\Models\InventoryImportTemplate;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\User;
use App\Services\InventoryCapacity;
use App\Services\InventoryRules;
use App\Services\Shipping\ShippingPackaging;
use Brick\Math\BigInteger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportWorkflow
{
    public function __construct(private CsvReader $reader, private ImportFields $fields, private InventoryRules $rules) {}

    public function upload(User $user, UploadedFile $file, array $settings): InventoryImport
    {
        Gate::forUser($user)->authorize('create', InventoryImport::class);
        $template = isset($settings['template_id']) ? InventoryImportTemplate::where('retailer_id', $user->retailer->id)->findOrFail($settings['template_id']) : null;
        if ($template) {
            $settings = array_replace($settings, $template->settings);
        }
        $bytes = $file->get();
        $parsed = $this->reader->read($bytes, $settings);
        $compatible = ! $template || ($template->settings['headers'] ?? []) === $parsed['headers'];
        if (! $compatible) {
            unset($settings['mapping'], $settings['category_links']);
        }
        $settings = array_replace($settings, ['shipping_packaging_required' => (bool) config('shipping.enabled'), 'delimiter' => $parsed['delimiter'], 'encoding' => $parsed['encoding'], 'blank_rows' => $parsed['blank_rows'], 'template_compatible' => $compatible]);
        unset($settings['template_id'], $settings['headers']);
        $path = 'inventory-imports/'.Str::uuid().'.csv';
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new \RuntimeException('Salvataggio del CSV privato non riuscito.');
        }
        try {
            return DB::transaction(function () use ($user, $file, $bytes, $path, $parsed, $settings) {
                $import = InventoryImport::create(['retailer_id' => $user->retailer->id, 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'size' => strlen($bytes), 'path' => $path, 'file_hash' => hash('sha256', $bytes), 'settings' => $settings, 'headers' => $parsed['headers'], 'total_rows' => count($parsed['records'])]);
                foreach (array_chunk($parsed['records'], 100, true) as $records) {
                    $rows = [];
                    foreach ($records as $i => $record) {
                        $rows[] = ['inventory_import_id' => $import->id, 'position' => $i + 1, 'source_line' => $record['line'], 'cells' => json_encode($record['cells'], JSON_THROW_ON_ERROR)];
                    }
                    InventoryImportRow::insert($rows);
                }

                return $import;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function reread(User $user, InventoryImport $import, array $settings): void
    {
        DB::transaction(function () use ($user, $import, $settings) {
            $import = InventoryImport::lockForUpdate()->findOrFail($import->id);
            Gate::forUser($user)->authorize('update', $import);
            abort_unless($import->editable() && $import->path, 409);
            $parsed = $this->reader->read(Storage::disk('local')->get($import->path), $settings);
            $settings = array_replace($settings, ['shipping_packaging_required' => $import->settings['shipping_packaging_required'] ?? false, 'delimiter' => $parsed['delimiter'], 'encoding' => $parsed['encoding'], 'blank_rows' => $parsed['blank_rows']]);
            $import->rows()->delete();
            foreach (array_chunk($parsed['records'], 100, true) as $records) {
                $rows = [];
                foreach ($records as $i => $record) {
                    $rows[] = ['inventory_import_id' => $import->id, 'position' => $i + 1, 'source_line' => $record['line'], 'cells' => json_encode($record['cells'], JSON_THROW_ON_ERROR)];
                }
                InventoryImportRow::insert($rows);
            }
            $import->update(['settings' => $settings, 'headers' => $parsed['headers'], 'total_rows' => count($parsed['records']), 'status' => 'uploaded', 'preview_token' => null, 'summary' => null]);
        });
    }

    public function configure(User $user, InventoryImport $import, string $stage, array $input): void
    {
        DB::transaction(function () use ($user, $import, $stage, $input) {
            $import = InventoryImport::lockForUpdate()->findOrFail($import->id);
            Gate::forUser($user)->authorize('update', $import);
            abort_unless($import->editable(), 409, 'Questa importazione è già avviata o scaduta.');
            $settings = $import->settings;
            if ($stage === 'mapping') {
                $settings['mapping'] = $this->fields->mapping($input['sources'] ?? [], $input['fixed'] ?? [], count($import->headers));
                $status = 'mapped';
            } else {
                abort_unless(isset($settings['mapping']), 409);
                $validated = Validator::make($input, ['category_mode' => ['required', 'in:single,csv'], 'category_id' => ['nullable', 'integer'], 'category_links' => ['sometimes', 'array', 'max:10000'], 'category_links.*.source' => ['present', 'nullable', 'string', 'max:10000', 'distinct:strict'], 'category_links.*.id' => ['nullable', 'integer'], 'mode' => ['required', 'in:new,update'], 'number_format' => ['required', 'in:italian,international']])->validate();
                if ($validated['category_mode'] === 'single' && ! Category::where('is_active', true)->whereKey($validated['category_id'] ?? null)->exists()) {
                    $this->fail('category_id', 'Seleziona una categoria attiva.');
                }
                if ($validated['category_mode'] === 'csv' && ! isset($settings['mapping']['category']['column'])) {
                    $this->fail('category_mode', 'Associa prima una colonna alla categoria.');
                }
                if ($validated['mode'] === 'update' && ! isset($settings['mapping']['sku']['column'])) {
                    $this->fail('mode', 'Per aggiornare è necessaria una colonna SKU univoca.');
                }
                $settings = array_replace($settings, $validated);
                $status = 'configured';
            }
            $import->update(['settings' => $settings, 'status' => $status, 'preview_token' => null, 'summary' => null]);
            $import->rows()->update(['status' => 'unvalidated', 'payload' => null, 'errors' => null, 'target_id' => null, 'processed_at' => null]);
        });
    }

    public function requestPreview(User $user, InventoryImport $import): void
    {
        if ($import->total_rows < 500) {
            $this->preview($user, $import);

            return;
        }
        if (! $this->workerReady()) {
            $this->fail('worker', 'Per validare file grandi è necessario un worker operativo sulla coda inventory-imports. Nessuna giacenza è stata modificata.');
        }
        DB::transaction(function () use ($user, $import) {
            $import = InventoryImport::lockForUpdate()->findOrFail($import->id);
            Gate::forUser($user)->authorize('update', $import);
            abort_unless($import->editable(), 409);
            $import->update(['status' => 'validating', 'heartbeat_at' => now()]);
            $this->enqueueValidation($import->id);
        });
    }

    public function enqueueValidation(int $id): void
    {
        ValidateInventoryImport::dispatch($id)->onConnection(config('inventory_imports.queue_connection'))->onQueue(config('inventory_imports.queue'))->afterCommit();
    }

    public function preview(User $user, InventoryImport $import): void
    {
        DB::transaction(function () use ($user, $import) {
            $import = InventoryImport::lockForUpdate()->findOrFail($import->id);
            Gate::forUser($user)->authorize('update', $import);
            abort_unless(($import->editable() || $import->status === 'validating') && isset($import->settings['category_mode']), 409);
            $retailer = $user->retailer;
            $items = $retailer->inventoryItems()->withTrashed()->get();
            $subscription = $retailer->activeSubscription()->with('plan')->first();
            $plan = $subscription?->plan;
            if (! $plan) {
                $this->fail('plan', 'È necessario un piano attivo.');
            }
            $snapshot = new ValidationSnapshot($subscription, $plan, Category::all()->keyBy('id'));
            $capacity = new InventoryCapacity($plan, $items);
            $beforeUsage = $capacity->usage();
            $categoryIndex = array_column($import->settings['category_links'] ?? [], 'id', 'source');
            // Resolve SKU once. Include proposed SKU and archived/deleted rows in ambiguity checks.
            $index = [];
            foreach ($items as $item) {
                foreach (array_unique(array_filter([$item->sku, $item->proposed_data['sku'] ?? null], fn ($s) => $s !== null && $s !== '')) as $sku) {
                    $index[InventoryCapacity::skuKey($sku)][$item->id] = $item;
                }
            }
            $seen = [];
            $value = BigInteger::zero();
            $import->rows()->orderBy('id')->chunkById(100, function ($rows) use ($user, $retailer, $import, $capacity, $index, $categoryIndex, $snapshot, &$seen, &$value) {
                foreach ($rows as $row) {
                    $data = array_filter(['sku' => $this->fields->value($row->cells, $import->settings['mapping']['sku'] ?? null), 'name' => $this->fields->value($row->cells, $import->settings['mapping']['name'] ?? null)], fn ($v) => $v !== null);
                    $target = null;
                    $status = 'valid';
                    $errors = [];
                    try {
                        $data = $this->fields->normalize($row->cells, $import->settings, $categoryIndex);
                        $sku = $data['sku'] ?? null;
                        if ($sku !== null && isset($seen[InventoryCapacity::skuKey($sku)])) {
                            $this->fail('sku', 'SKU duplicato nel CSV: importata soltanto la prima occorrenza valida.');
                        }
                        if ($sku !== null) {
                            $seen[InventoryCapacity::skuKey($sku)] = true;
                        }
                        $matches = $sku !== null ? array_values($index[InventoryCapacity::skuKey($sku)] ?? []) : [];
                        if ($sku !== null && mb_strlen($sku) <= 191) {
                            $persisted = $retailer->inventoryItems()->withTrashed()->where('sku', $sku)->get();
                            $matches = collect($matches)->merge($persisted)->unique('id')->values()->all();
                        }
                        if ($matches) {
                            if ($import->settings['mode'] === 'new') {
                                $status = 'duplicate';
                                $this->fail('sku', 'SKU già presente nel tuo magazzino, anche tra gli archiviati o eliminati.');
                            }
                            if (count($matches) !== 1 || $matches[0]->trashed() || ! $user->can('update', $matches[0])) {
                                $this->fail('sku', 'SKU non univoco oppure articolo archiviato o eliminato: aggiornamento non consentito.');
                            }
                            $target = $matches[0];
                        }
                        $this->validateInput($user, $data, $target, $snapshot->categories, $import->settings['shipping_packaging_required'] ?? false);
                        $normalized = $this->rules->data($data, $snapshot->categories->get($data['category_id']), $import->settings['shipping_packaging_required'] ?? false);
                        $this->rules->validate($retailer, $normalized, $target, $capacity, $snapshot);
                        $capacity->accept($normalized, $target);
                        if (! $target) {
                            $value = $value->plus(InventoryCapacity::value($normalized));
                        }
                    } catch (ValidationException $e) {
                        $errors = $e->errors();
                        if ($status !== 'duplicate') {
                            $status = isset($errors['plan']) || str_contains(implode(' ', $errors['quantity'] ?? []), 'limite del piano') ? 'limit' : (isset($errors['sku']) && str_contains(implode(' ', $errors['sku']), 'duplicato nel CSV') ? 'duplicate' : 'error');
                        }
                    }
                    $row->update(['payload' => $status === 'valid' ? $data : null, 'sku' => isset($data['sku']) ? mb_substr($data['sku'], 0, 191) : null, 'name' => mb_substr($data['name'] ?? '', 0, 255), 'status' => $status, 'errors' => $errors ?: null, 'target_id' => $target?->id]);
                }
            });
            $summary = $this->counts($import);
            $summary['new_value_millicents'] = (string) $value;
            $summary['quota_before'] = $beforeUsage;
            $summary['quota_after'] = $capacity->usage();
            $summary['plan_name'] = $plan->name;
            $summary['max_items'] = $plan->max_items;
            $summary['max_inventory_value_cents'] = $plan->max_inventory_value_cents;
            $import->update(['status' => 'validated', 'preview_token' => bin2hex(random_bytes(32)), 'summary' => $summary]);
        });
    }

    public function validateInput(User $user, array $data, ?InventoryItem $target, ?Collection $categories = null, ?bool $packagingRequired = null): void
    {
        $rules = array_replace(InventoryRequest::productRules($user->retailer->id, $target), ShippingPackaging::rules($packagingRequired ?? (bool) config('shipping.enabled')));
        // Preview already resolves persisted SKU through a collation-aware query and the pending-SKU index.
        // Writes always keep the live database validators.
        if ($categories !== null) {
            $rules['sku'] = ['nullable', 'string', 'max:191'];
            $rules['category_id'] = ['required', 'integer'];
        }
        $validator = Validator::make($data, $rules);
        $validator->after(function ($validator) use ($data, $categories) {
            if (! ($data['pickup_available'] ?? false) && ! ($data['shipping_available'] ?? false)) {
                $validator->errors()->add('pickup_available', 'Seleziona almeno una modalità di consegna.');
            }
            if (! ($categories !== null ? $categories->get($data['category_id'] ?? null)?->is_active : Category::whereKey($data['category_id'] ?? null)->where('is_active', true)->exists())) {
                $validator->errors()->add('category_id', 'Categoria non associata, inesistente o non attiva.');
            }
        });
        $validator->validate();
    }

    public function confirm(User $user, InventoryImport $import, string $token, string $intent): void
    {
        if (! $this->workerReady()) {
            $this->fail('worker', 'Worker importazioni non operativo. Chiedi al gestore di avviare la coda inventory-imports e riprova. Nessuna importazione è stata avviata.');
        }
        DB::transaction(function () use ($user, $import, $token, $intent) {
            $import = InventoryImport::lockForUpdate()->findOrFail($import->id);
            Gate::forUser($user)->authorize('update', $import);
            if (in_array($import->status, ['queued', 'processing', 'completed', 'completed_errors']) && hash_equals($import->preview_token ?? '', $token)) {
                return;
            }
            abort_unless($import->status === 'validated' && $import->editable() && hash_equals($import->preview_token ?? '', $token), 409, 'Anteprima cambiata o scaduta: ripeti la validazione.');
            Retailer::lockForUpdate()->findOrFail($import->retailer_id);
            if (InventoryImport::where('retailer_id', $import->retailer_id)->where('operation_hash', $import->file_hash)->whereKeyNot($import->id)->exists()) {
                $this->fail('file', 'Questo file è già stato confermato. Consulta lo storico o riprendi l’importazione originale.');
            }
            abort_unless(in_array($intent, ['draft', 'pending']), 422);
            $import->update(['intent' => $intent, 'operation_hash' => $import->file_hash, 'status' => 'queued', 'heartbeat_at' => now()]);
            $this->enqueue($import->id);
        });
    }

    public function process(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $import = InventoryImport::lockForUpdate()->findOrFail($id);
            if (! in_array($import->status, ['queued', 'processing'])) {
                return false;
            }
            $retailer = Retailer::lockForUpdate()->findOrFail($import->retailer_id);
            $user = $retailer->user;
            Gate::forUser($user)->authorize('create', InventoryItem::class);
            $import->update(['status' => 'processing', 'heartbeat_at' => now(), 'failure' => null]);
            $plan = $retailer->activeSubscription()->with('plan')->lockForUpdate()->first()?->plan;
            if (! $plan) {
                $this->fail('plan', 'Piano non attivo: riprendi dopo averlo riattivato.');
            }
            $capacity = new InventoryCapacity(Plan::sharedLock()->findOrFail($plan->id), $retailer->inventoryItems()->withTrashed()->lockForUpdate()->get());
            $rows = $import->rows()->where('status', 'valid')->whereNull('processed_at')->orderBy('id')->limit(config('inventory_imports.chunk_size'))->lockForUpdate()->get();
            foreach ($rows as $row) {
                try {
                    DB::transaction(function () use ($row, $user, $import, $capacity) {
                        $target = $row->target_id ? InventoryItem::lockForUpdate()->findOrFail($row->target_id) : null;
                        $data = array_replace($row->payload, ['intent' => $import->intent]);
                        if ($target && ! in_array(InventoryCapacity::skuKey($data['sku'] ?? ''), array_map(InventoryCapacity::skuKey(...), [$target->sku ?? '', $target->proposed_data['sku'] ?? '']), true)) {
                            $this->fail('sku', 'Il codice dell’articolo è cambiato dopo l’anteprima. Ripeti l’importazione con i dati aggiornati.');
                        }
                        $this->validateInput($user, $data, $target, packagingRequired: $import->settings['shipping_packaging_required'] ?? false);
                        $item = app(SaveInventoryItem::class)->handle($user, $data, [], $target, $capacity, $import->settings['shipping_packaging_required'] ?? false);
                        $row->update(['inventory_item_id' => $item->id, 'status' => $target ? 'updated' : 'created', 'processed_at' => now(), 'errors' => null]);
                        $capacity->accept($this->rules->data($data, packagingRequired: $import->settings['shipping_packaging_required'] ?? false), $target);
                    });
                } catch (ValidationException $e) {
                    $row->update(['status' => 'error', 'errors' => $e->errors(), 'processed_at' => now()]);
                } catch (AuthorizationException|ModelNotFoundException $e) {
                    $row->update(['status' => 'error', 'errors' => ['item' => ['Articolo non più disponibile o aggiornamento non autorizzato.']], 'processed_at' => now()]);
                }
            }
            $remaining = $import->rows()->where('status', 'valid')->whereNull('processed_at')->exists();
            $counts = $this->counts($import);
            $import->update(['summary' => array_replace($import->summary ?? [], $counts), 'status' => $remaining ? 'processing' : ($counts['error'] + $counts['limit'] > 0 ? 'completed_errors' : 'completed'), 'completed_at' => $remaining ? null : now(), 'heartbeat_at' => now()]);

            return $remaining;
        }, 3);
    }

    public function counts(InventoryImport $import): array
    {
        $counts = array_fill_keys(['valid', 'duplicate', 'error', 'limit', 'created', 'updated', 'unvalidated'], 0);
        foreach ($import->rows()->selectRaw('status, count(*) as aggregate')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->aggregate;
        }

        $counts['new_valid'] = $import->rows()->where('status', 'valid')->whereNull('target_id')->count();
        $counts['update_valid'] = $counts['valid'] - $counts['new_valid'];

        return $counts;
    }

    public function enqueue(int $id): void
    {
        ProcessInventoryImport::dispatch($id)->onConnection(config('inventory_imports.queue_connection'))->onQueue(config('inventory_imports.queue'))->afterCommit();
    }

    public function workerReady(): bool
    {
        return ! in_array(config('inventory_imports.queue_connection'), ['sync', 'null']) && Cache::get('inventory-import-worker:'.config('inventory_imports.queue_connection').':'.config('inventory_imports.queue'), 0) >= now()->subSeconds(180)->getTimestamp();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
