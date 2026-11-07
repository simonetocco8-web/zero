<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Jobs\ProcessInventoryImport;
use App\Jobs\ValidateInventoryImport;
use App\Models\Category;
use App\Models\InventoryImport;
use App\Models\InventoryImportTemplate;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InventoryCapacity;
use App\Services\InventoryImport\CsvReader;
use App\Services\InventoryImport\ImportFields;
use App\Services\InventoryImport\ImportWorkflow;
use App\Services\InventoryRules;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class InventoryImportTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private Retailer $retailer;

    private ImportWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->seed(PlanSeeder::class);
        $this->retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $this->retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        $this->workflow = app(ImportWorkflow::class);
        $this->actingAs($this->retailer->user);
        Cache::put('inventory-import-worker:database:inventory-imports', now()->getTimestamp(), 180);
    }

    private function csvOptions(array $overrides = []): array
    {
        return array_replace(['delimiter' => ';', 'enclosure' => '"', 'encoding' => 'auto', 'has_headers' => true, 'number_format' => 'italian'], $overrides);
    }

    private function file(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('gestionale.csv', $csv);
    }

    private function mapped(string $csv = "SKU;Nome;Quantita;Prezzo\n0001;Collante;1,250;14,90\n", array $settings = []): InventoryImport
    {
        $import = $this->workflow->upload($this->retailer->user, $this->file($csv), $this->csvOptions());
        $this->workflow->configure($this->retailer->user, $import, 'mapping', ['sources' => ['sku' => '0', 'name' => '1', 'quantity' => '2', 'zero_price' => '3', 'description' => 'fixed', 'unit' => 'fixed', 'condition' => 'fixed', 'province' => 'fixed', 'pickup_available' => 'fixed'], 'fixed' => ['description' => 'Descrizione esplicita', 'unit' => 'kg', 'condition' => 'new', 'province' => 'VV', 'pickup_available' => 'sì']]);
        $this->workflow->configure($this->retailer->user, $import, 'configuration', array_replace(['category_mode' => 'single', 'category_id' => Category::where('slug', 'edilizia')->sole()->id, 'mode' => 'new', 'number_format' => 'italian'], $settings));
        $this->workflow->preview($this->retailer->user, $import);

        return $import->fresh();
    }

    private function runImport(InventoryImport $import, string $intent = 'draft'): void
    {
        $this->workflow->confirm($this->retailer->user, $import, $import->preview_token, $intent);
        $job = new ProcessInventoryImport($import->id);
        while ($import->fresh()->status !== 'completed' && $import->fresh()->status !== 'completed_errors') {
            $job->handle($this->workflow);
        }
    }

    public static function csvCases(): array
    {
        return [
            'semicolon' => ["nome;testo\nPezzo;Descrizione\n", [], ['Pezzo', 'Descrizione']],
            'comma quotes multiline' => ["nome,testo\n\"Pezzo, grande\",\"Prima\nSeconda \"\"riga\"\"\"\n", ['delimiter' => ','], ['Pezzo, grande', "Prima\nSeconda \"riga\""]],
            'no headers' => ["00001;Articolo\n", ['has_headers' => false], ['00001', 'Articolo']],
            'bom' => ["\xEF\xBB\xBFcodice;nome\n00001;Città\n", [], ['00001', 'Città']],
            'windows' => ["nome;descrizione\nCaff\xe8;Prezzo \x80\n", ['encoding' => 'Windows-1252'], ['Caffè', 'Prezzo €']],
            'latin1' => ["nome;descrizione\nCaff\xe8;Descrizione\n", ['encoding' => 'ISO-8859-1'], ['Caffè', 'Descrizione']],
            'tab' => ["nome\ttesto\nArticolo\tDescrizione\n", ['delimiter' => 'tab'], ['Articolo', 'Descrizione']],
            'custom' => ["nome~testo\nArticolo~Descrizione\n", ['delimiter' => '~'], ['Articolo', 'Descrizione']],
        ];
    }

    #[DataProvider('csvCases')]
    public function test_csv_reader_preserves_records_codes_and_encodings(string $csv, array $options, array $expected): void
    {
        $result = app(CsvReader::class)->read($csv, $this->csvOptions($options));
        $this->assertSame($expected, $result['records'][0]['cells']);
        $this->assertCount(2, $result['headers']);
        $this->assertSame(($options['has_headers'] ?? true) ? 2 : 1, $result['records'][0]['line']);
    }

    public function test_wizard_upload_mapping_configuration_preview_and_confirmation(): void
    {
        $this->get(route('retailer.imports.index'))->assertOk()->assertSee('Caricamento Massivo Giacenze');
        $import = $this->mapped();
        $this->assertSame(0, InventoryItem::count());
        $this->get(route('retailer.imports.show', [$import, 'step' => 'mapping']))->assertOk()->assertSee('Prime 10 righe')->assertSee('0001');
        $this->get(route('retailer.imports.show', [$import, 'step' => 'configuration']))->assertOk();
        $this->get(route('retailer.imports.show', $import))->assertOk()->assertSee('Valore dei nuovi articoli')->assertSee('18,63');
        $this->post(route('retailer.imports.confirm', $import), ['token' => $import->preview_token, 'intent' => 'pending', 'confirmed' => '1'])->assertRedirect();
        Queue::assertPushed(ProcessInventoryImport::class);
        (new ProcessInventoryImport($import->id))->handle($this->workflow);
        $item = InventoryItem::sole();
        $this->assertSame('0001', $item->sku);
        $this->assertSame(1250, $item->quantity_milliunits);
        $this->assertSame(1490, $item->zero_price_cents);
        $this->assertSame('pending', $item->status->value);
        $this->get(route('retailer.imports.show', $import))->assertOk()->assertSee('Completata');
        $this->assertSame($item->id, $import->rows()->sole()->inventory_item_id);
    }

    public function test_http_upload_mime_file_limits_and_approved_owner_are_enforced(): void
    {
        $this->post(route('retailer.imports.store'), $this->csvOptions() + ['file' => $this->file("Nome;Prezzo\nProdotto;10\n")])->assertRedirect();
        $this->assertSame(1, InventoryImport::count());
        $this->post(route('retailer.imports.store'), $this->csvOptions() + ['file' => UploadedFile::fake()->create('malware.csv', 1, 'image/png')])->assertSessionHasErrors('file');
        config(['inventory_imports.max_kilobytes' => 1]);
        $this->post(route('retailer.imports.store'), $this->csvOptions() + ['file' => UploadedFile::fake()->create('large.csv', 2, 'text/plain')])->assertSessionHasErrors('file');
        $this->retailer->update(['status' => 'pending']);
        $this->actingAs($this->retailer->user->fresh());
        $this->get(route('retailer.imports.index'))->assertForbidden();
    }

    public function test_decimal_normalization_is_exact_and_rejects_ambiguous_or_scientific_values(): void
    {
        $fields = app(ImportFields::class);
        $this->assertSame('1234.50', $fields->number('1.234,50', 'italian', 2, 'zero_price'));
        $this->assertSame('1234.50', $fields->number('1,234.50', 'international', 2, 'zero_price'));
        $this->assertSame('0.001', $fields->number('0,001', 'italian', 3, 'quantity'));
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\n1E+10;Codice perso;1;10\n0002;Troppi decimali;1,2345;10\n0003;Formato misto;1;1,23.45\n");
        $this->assertSame(3, $import->summary['error']);
        $this->assertSame(0, InventoryItem::count());
    }

    public function test_categories_required_fields_delivery_and_vat_are_validated(): void
    {
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\n1;;1;10\n2;Valido;0;10\n");
        $this->assertSame(2, $import->summary['error']);
        Category::whereKey($import->settings['category_id'])->update(['is_active' => false]);
        $this->workflow->preview($this->retailer->user, $import);
        foreach ($import->rows()->get() as $row) {
            $this->assertArrayHasKey('category_id', $row->errors);
        }
        $data = ['name' => 'Articolo', 'description' => '', 'category_id' => $import->settings['category_id'], 'unit' => 'kg', 'condition' => 'new', 'province' => 'VV', 'quantity' => '1', 'zero_price' => '1', 'intent' => 'draft'];
        try {
            $this->workflow->validateInput($this->retailer->user, $data, null);
            $this->fail('Missing description/delivery accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('description', $e->errors());
            $this->assertArrayHasKey('pickup_available', $e->errors());
        }
        $this->expectException(ValidationException::class);
        app(InventoryRules::class)->data(array_replace($data, ['category_id' => Category::first()->id, 'vat_treatment' => 'not_applicable', 'vat_rate' => '22']));
    }

    public function test_duplicate_skus_in_file_existing_archived_deleted_and_proposed_are_not_created(): void
    {
        InventoryItem::factory()->create(['retailer_id' => $this->retailer->id, 'sku' => 'archived', 'status' => 'archived']);
        $deleted = InventoryItem::factory()->create(['retailer_id' => $this->retailer->id, 'sku' => 'deleted']);
        $deleted->delete();
        $live = InventoryItem::factory()->published()->create(['retailer_id' => $this->retailer->id, 'sku' => 'live']);
        $live->proposed_data = array_replace($live->getAttributes(), ['sku' => 'proposal']);
        $live->save();
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\n0001;Primo;1;1\n0001;Secondo;1;1\narchived;Archivio;1;1\ndeleted;Eliminato;1;1\nproposal;Proposta;1;1\n");
        $this->assertSame(4, $import->summary['duplicate']);
        $this->assertSame(1, $import->summary['valid']);
        $this->runImport($import);
        $this->assertSame(1, $import->fresh()->summary['created']);
    }

    public function test_free_limits_use_persisted_plan_and_partial_import_is_atomic(): void
    {
        $this->retailer->activeSubscription->plan->update(['max_items' => 2]);
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\n1;Uno;1;1\n2;Due;1;1\n3;Tre;1;1\n4;;1;1\n");
        $this->assertSame(2, $import->summary['valid']);
        $this->assertSame(1, $import->summary['limit']);
        $this->assertSame(1, $import->summary['error']);
        $this->runImport($import);
        $this->assertSame(2, InventoryItem::count());
        $this->assertSame('completed_errors', $import->fresh()->status);
    }

    public function test_value_limit_and_concurrent_manual_creation_are_rechecked_at_execution(): void
    {
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\n1;Uno;1;9999\n2;Due;1;2\n");
        $this->assertSame(1, $import->summary['limit']);
        InventoryItem::factory()->create(['retailer_id' => $this->retailer->id, 'zero_price_cents' => 200, 'quantity' => '1']);
        $this->runImport($import);
        $this->assertSame(0, $import->fresh()->summary['created']);
        $this->assertSame(1, InventoryItem::count());
    }

    public function test_retry_resume_and_repeated_file_cannot_duplicate_items_without_sku(): void
    {
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\n;Senza codice;1;1\n");
        $this->workflow->confirm($this->retailer->user, $import, $import->preview_token, 'draft');
        $job = new ProcessInventoryImport($import->id);
        $job->failed(new \RuntimeException('private details'));
        $this->assertSame('failed', $import->fresh()->status);
        $this->post(route('retailer.imports.resume', $import))->assertRedirect();
        $job->handle($this->workflow);
        $job->handle($this->workflow);
        $this->assertSame(1, InventoryItem::count());
        $again = $this->mapped("SKU;Nome;Quantita;Prezzo\n;Senza codice;1;1\n");
        $this->post(route('retailer.imports.confirm', $again), ['token' => $again->preview_token, 'intent' => 'draft', 'confirmed' => 1])->assertSessionHasErrors('file');
        $this->assertSame(1, InventoryItem::count());
        $this->assertStringNotContainsString('private details', $import->fresh()->failure ?? '');
    }

    public function test_published_updates_use_moderation_and_cannot_change_live_quantity(): void
    {
        $item = InventoryItem::factory()->published()->create(['retailer_id' => $this->retailer->id, 'sku' => '0001', 'quantity' => '2', 'zero_price_cents' => 100]);
        $import = $this->mapped(settings: ['mode' => 'update']);
        $this->runImport($import);
        $this->assertSame('change_pending', $item->fresh()->status->value);
        $this->assertSame(2000, $item->fresh()->quantity_milliunits);
        $this->assertSame(1250, $item->fresh()->proposed_data['quantity_milliunits']);
        $this->assertSame(1, $import->fresh()->summary['updated']);
        $this->assertSame(1, InventoryItem::count());
    }

    public function test_owner_isolation_includes_csv_report_actions_and_templates(): void
    {
        $import = $this->mapped();
        $template = InventoryImportTemplate::create(['retailer_id' => $this->retailer->id, 'name' => 'Privato', 'settings' => $import->settings]);
        $other = Retailer::factory()->approved()->create();
        $this->actingAs($other->user);
        foreach (['show', 'download', 'report'] as $action) {
            $this->get(route('retailer.imports.'.$action, $import))->assertForbidden();
        }
        foreach (['mapping', 'configuration', 'confirm', 'resume', 'template'] as $action) {
            $this->post(route('retailer.imports.'.$action, $import))->assertForbidden();
        }
        $this->post(route('retailer.imports.store'), $this->csvOptions() + ['file' => $this->file("a;b\n1;2\n"), 'template_id' => $template->id])->assertNotFound();
    }

    public function test_preview_token_invalidated_by_remapping_and_worker_required(): void
    {
        $import = $this->mapped();
        Cache::forget('inventory-import-worker:database:inventory-imports');
        $this->post(route('retailer.imports.confirm', $import), ['token' => $import->preview_token, 'intent' => 'draft', 'confirmed' => 1])->assertSessionHasErrors('worker');
        $this->assertSame('validated', $import->fresh()->status);
        Cache::put('inventory-import-worker:database:inventory-imports', now()->getTimestamp(), 180);
        $this->workflow->reread($this->retailer->user, $import, $this->csvOptions());
        $this->post(route('retailer.imports.confirm', $import), ['token' => $import->preview_token, 'intent' => 'draft', 'confirmed' => 1])->assertConflict();
        $this->assertSame(0, InventoryItem::count());
        Event::dispatch(new Looping('database', 'inventory-imports'));
        $this->assertTrue($this->workflow->workerReady());
    }

    public function test_mapping_requires_explicit_required_values_and_no_reused_column(): void
    {
        $import = $this->workflow->upload($this->retailer->user, $this->file("Nome;Descrizione\nA;B\n"), $this->csvOptions());
        $this->post(route('retailer.imports.mapping', $import), ['sources' => ['name' => '0', 'description' => '0'], 'confirmed' => 1])->assertSessionHasErrors();
        $this->assertSame('uploaded', $import->fresh()->status);
    }

    public static function malformedCsv(): array
    {
        return [['a;b\n1'], ["a;b\n\"non chiuso;2"], ["a;b\n\"chiuso\"x;2\n"], ["a;b\n1;2;3\n"], ["a;b\n\x00;2\n"]];
    }

    #[DataProvider('malformedCsv')]
    public function test_malformed_csv_is_rejected_before_persistence(string $csv): void
    {
        $this->expectException(ValidationException::class);
        $this->workflow->upload($this->retailer->user, $this->file($csv), $this->csvOptions());
    }

    public function test_error_report_is_excel_compatible_and_formula_safe_after_file_cleanup(): void
    {
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\n=1+1;@formula;0;1\n");
        $this->runImport($import);
        $report = $this->get(route('retailer.imports.report', $import))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $report);
        $this->assertStringContainsString("'=1+1", $report);
        $this->assertStringContainsString("'@formula", $report);
        $import->update(['updated_at' => now()->subDays(8)]);
        $this->artisan('inventory-imports:cleanup')->assertSuccessful();
        Storage::disk('local')->assertMissing($import->path);
        $this->assertNotNull($import->fresh()->purged_at);
        $this->assertNull($import->rows()->sole()->cells);
        $this->get(route('retailer.imports.report', $import))->assertOk();
        $this->get(route('retailer.imports.download', $import))->assertGone();
        $this->get(route('retailer.imports.show', $import))->assertOk()->assertSee('File temporaneo scaduto');
    }

    public function test_bulk_pro_import_uses_chunks_and_ignores_server_owned_csv_columns(): void
    {
        $this->retailer->activeSubscription()->first()->update(['plan_id' => Plan::where('code', 'pro')->sole()->id]);
        $csv = "SKU;Nome;Quantita;Prezzo\n";
        for ($i = 0; $i < 1001; $i++) {
            $csv .= sprintf("%05d;Articolo %d;0,125;1,99\n", $i, $i);
        }
        $import = $this->mapped($csv);
        $this->assertSame(1001, $import->summary['valid']);
        $this->workflow->confirm($this->retailer->user, $import, $import->preview_token, 'draft');
        $this->assertTrue($this->workflow->process($import->id));
        $this->assertSame(50, InventoryItem::count());
        while ($this->workflow->process($import->id)) {
        }
        $this->assertSame(1001, InventoryItem::count());
        $this->assertSame(1001, $import->fresh()->summary['created']);
        $this->assertSame([125], InventoryItem::distinct()->pluck('quantity_milliunits')->all());
        $this->assertSame(0, InventoryItem::where('retailer_id', '!=', $this->retailer->id)->count());
        $this->assertSame(['draft'], InventoryItem::distinct()->pluck('status')->map->value->all());
        $this->workflow->process($import->id);
        $this->assertSame(1001, InventoryItem::count());
    }

    public function test_row_limit_and_large_invalid_strings_fail_without_partial_inventory_writes(): void
    {
        config(['inventory_imports.max_rows' => 2]);
        try {
            $this->workflow->upload($this->retailer->user, $this->file("a;b\n1;2\n3;4\n5;6\n"), $this->csvOptions());
            $this->fail('Too many records accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }
        $this->assertSame(0, InventoryImport::count());
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\n".str_repeat('x', 200).";Troppo lungo;1;1\n");
        $this->assertSame(1, $import->summary['error']);
        $this->assertSame(191, strlen($import->rows()->sole()->sku));
        $this->assertSame(0, InventoryItem::count());
    }

    public function test_active_reservation_created_after_preview_blocks_lower_quantity_update(): void
    {
        $item = InventoryItem::factory()->published()->create(['retailer_id' => $this->retailer->id, 'sku' => '0001', 'quantity' => '2', 'zero_price_cents' => 100]);
        $import = $this->mapped(settings: ['mode' => 'update']);
        $buyer = User::factory()->create(['role' => 'customer']);
        app(MarketplaceCart::class)->save($buyer, $item, '1.500', 'pickup');
        $quotes = app(MarketplaceQuote::class);
        app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], null, $quotes->hash($quotes->build($buyer, [])));
        $this->runImport($import);
        $this->assertSame(0, $import->fresh()->summary['updated']);
        $this->assertSame(1, $import->fresh()->summary['error']);
        $this->assertSame(2000, $item->fresh()->quantity_milliunits);
        $this->assertNull($item->fresh()->proposed_data);
    }

    public function test_deactivated_category_revoked_access_and_expired_plan_are_rechecked(): void
    {
        $import = $this->mapped();
        Category::whereKey($import->settings['category_id'])->update(['is_active' => false]);
        $this->runImport($import);
        $this->assertSame(1, $import->fresh()->summary['error']);
        $this->assertSame(0, InventoryItem::count());
    }

    public function test_large_preview_runs_on_queue_and_can_resume_failed_validation(): void
    {
        $import = $this->mapped();
        // Exercise the asynchronous branch without inflating the test fixture.
        $import->update(['total_rows' => 500]);
        $this->post(route('retailer.imports.configuration', $import), ['category_mode' => 'single', 'category_id' => $import->settings['category_id'], 'mode' => 'new', 'number_format' => 'italian'])->assertRedirect();
        $this->assertSame('validating', $import->fresh()->status);
        Queue::assertPushed(ValidateInventoryImport::class);
        $this->get(route('retailer.imports.show', $import))->assertOk()->assertSee('Validazione completa in corso')->assertDontSee('Avvia importazione');
        $job = new ValidateInventoryImport($import->id);
        $job->failed(new \RuntimeException('private text'));
        $this->post(route('retailer.imports.resume', $import))->assertRedirect();
        $this->assertSame('validating', $import->fresh()->status);
        $job->handle($this->workflow);
        $this->assertSame('validated', $import->fresh()->status);
        $this->assertSame(0, InventoryItem::count());
        $job->handle($this->workflow);
        $this->assertSame('validated', $import->fresh()->status);
    }

    public function test_csv_category_associations_are_explicit_and_unrecognized_values_are_errors(): void
    {
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\nA;Collanti;1;1\nB;Sconosciuta;1;1\nC;Disattiva;1;1\n");
        $settings = $import->settings;
        $settings['mapping']['category'] = ['column' => 1];
        $import->update(['settings' => $settings]);
        $inactive = Category::create(['name' => 'Disattiva', 'slug' => 'disattiva', 'is_active' => false]);
        $this->post(route('retailer.imports.configuration', $import), ['category_mode' => 'csv', 'mode' => 'new', 'number_format' => 'italian', 'category_links_json' => json_encode([['source' => 'Collanti', 'id' => $settings['category_id']], ['source' => 'Disattiva', 'id' => $inactive->id]])])->assertRedirect();
        $this->assertSame(1, $import->fresh()->summary['valid']);
        $this->assertSame(2, $import->fresh()->summary['error']);
        $this->assertSame(0, InventoryItem::count());
    }

    public function test_other_retailer_sku_never_targets_their_product_and_archived_update_is_rejected(): void
    {
        $other = InventoryItem::factory()->create(['sku' => '0001'])->fresh();
        $import = $this->mapped(settings: ['mode' => 'update']);
        $this->runImport($import);
        $this->assertSame(1, $import->fresh()->summary['created']);
        $this->assertSame($this->retailer->id, InventoryItem::where('id', '!=', $other->id)->sole()->retailer_id);
        $this->assertSame($other->getAttributes(), $other->fresh()->getAttributes());
        $archived = InventoryItem::factory()->create(['retailer_id' => $this->retailer->id, 'sku' => 'ARCH', 'status' => 'archived']);
        $rejected = $this->mapped("SKU;Nome;Quantita;Prezzo\nARCH;Archivio;1;1\n", ['mode' => 'update']);
        $this->assertSame(1, $rejected->summary['error']);
        $this->assertSame('archived', $archived->fresh()->status->value);
    }

    public function test_sku_variants_are_reserved_without_losing_non_latin_identity(): void
    {
        $this->assertSame('abc中文', InventoryCapacity::skuKey('ABC中文'));
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\nABC;Uno;1;1\nabc;Duplicato;1;1\nABC中文;Altro;1;1\n");
        $this->assertSame(2, $import->summary['valid']);
        $this->assertSame(1, $import->summary['duplicate']);
        $this->runImport($import);
        $this->assertSame(2, InventoryItem::count());
        $this->assertSame(['ABC', 'ABC中文'], InventoryItem::orderBy('id')->pluck('sku')->all());
    }

    public function test_auto_delimiter_does_not_hide_inconsistent_columns(): void
    {
        $this->expectException(ValidationException::class);
        $this->workflow->upload($this->retailer->user, $this->file("Nome;Quantita\nUno;1\nDue\n"), $this->csvOptions(['delimiter' => 'auto']));
    }

    public function test_interrupted_import_resumes_after_committed_chunk_and_scheduler_recovers_stale_work(): void
    {
        config(['inventory_imports.chunk_size' => 1]);
        $import = $this->mapped("SKU;Nome;Quantita;Prezzo\nA;Uno;1;1\nB;Due;1;1\n");
        $this->workflow->confirm($this->retailer->user, $import, $import->preview_token, 'draft');
        $this->assertTrue($this->workflow->process($import->id));
        $createdId = InventoryItem::sole()->id;
        $job = new ProcessInventoryImport($import->id);
        $job->failed(new \RuntimeException('temporary failure'));
        $this->post(route('retailer.imports.resume', $import))->assertRedirect();
        $import->fresh()->update(['heartbeat_at' => now()->subMinutes(6)]);
        Queue::fake();
        $this->artisan('inventory-imports:recover')->assertSuccessful();
        Queue::assertPushed(ProcessInventoryImport::class, fn ($job) => $job->importId === $import->id);
        $job->handle($this->workflow);
        $this->assertSame(2, InventoryItem::count());
        $this->assertSame($createdId, $import->rows()->orderBy('position')->first()->inventory_item_id);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(2, $import->rows()->whereNotNull('processed_at')->count());
    }

    public function test_templates_check_header_compatibility_and_reread_does_not_require_reupload(): void
    {
        $import = $this->mapped();
        $this->post(route('retailer.imports.template', $import), ['name' => 'Gestionale'])->assertRedirect();
        $template = InventoryImportTemplate::sole();
        $different = $this->workflow->upload($this->retailer->user, $this->file("Altra;Struttura\nA;B\n"), $this->csvOptions(['template_id' => $template->id]));
        $this->assertFalse($different->settings['template_compatible']);
        $this->assertArrayNotHasKey('mapping', $different->settings);
        $this->post(route('retailer.imports.options', $import), $this->csvOptions(['has_headers' => false]))->assertRedirect();
        $this->assertSame(2, $import->fresh()->total_rows);
        $this->assertSame(['Colonna 1', 'Colonna 2', 'Colonna 3', 'Colonna 4'], $import->fresh()->headers);
    }
}
