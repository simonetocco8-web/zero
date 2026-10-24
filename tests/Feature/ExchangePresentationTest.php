<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Services\ExchangeWorkflow;
use App\Services\Payments\StartExchangeDifferencePayment;
use App\Support\DisplayMoney;
use Database\Seeders\PlanSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class ExchangePresentationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function retailer(string $name): Retailer
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create(['company_name' => $name]);
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id]);

        return $r;
    }

    private function fixture(bool $equal = false, bool $provisional = false, bool $shipping = false): array
    {
        $a = $this->retailer('Villaggio Tramonto');
        $b = $this->retailer('Sunset s.a.s. di Tocco Simone & C.');
        $products = [];
        foreach ([$a, $b] as $i => $r) {
            $products[] = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'name' => $i === 0 ? 'Cemento Portland' : 'Stucco per esterni', 'unit' => 'bancale', 'quantity' => '5', 'exchange_available' => true, 'pickup_available' => true, 'shipping_available' => true, 'vat_treatment' => $provisional && $i === 0 ? null : 'not_applicable']);
        }
        $input = ['key' => (string) Str::uuid(), 'lines' => ['a' => [['inventory_item_id' => $products[0]->id, 'quantity' => '1', 'value' => '599']], 'b' => [['inventory_item_id' => $products[1]->id, 'quantity' => '1', 'value' => $equal ? '599' : '900']]], 'delivery' => ['a' => ['mode' => $shipping ? 'shipping' : 'pickup', 'cost' => $shipping ? '100' : '0', 'destination' => 'Ricadi (VV)', 'payer_side' => 'b', 'settlement' => 'external'], 'b' => ['mode' => 'pickup', 'cost' => '0']], 'notes' => 'Accordi della prima versione'];
        $exchange = app(ExchangeWorkflow::class)->create($a->user, $products[1], $input);

        return [$a, $b, $exchange, $input];
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($doc);
    }

    private function page(Retailer $r, Exchange $e): DOMXPath
    {
        return $this->xpath($this->actingAs($r->user)->get(route('retailer.exchanges.show', $e))->assertOk()->getContent());
    }

    private function accepted(Retailer $a, Retailer $b, Exchange $e): Exchange
    {
        $v = $e->fresh()->currentVersion;
        foreach ([$a, $b] as $r) {
            app(ExchangeWorkflow::class)->accept($r->user, $e, $v->id, $v->summary_hash);
        }

        return $e->fresh();
    }

    private function postForm(DOMXPath $xpath, string $route, Exchange $e): void
    {
        $form = $xpath->query('//main//form[@action="'.route($route, $e).'"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame('POST', $form->getAttribute('method'));
        $payload = [];
        foreach ($xpath->query('.//input', $form) as $input) {
            $payload[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertArrayHasKey('_token', $payload);
        $this->assertSame((string) $e->fresh()->current_version_id, $payload['version_id']);
        $this->assertSame($e->fresh()->currentVersion->summary_hash, $payload['summary_hash']);
        $this->post(route($route, $e), $payload)->assertSessionHasNoErrors()->assertRedirect(route('retailer.exchanges.show', $e));
    }

    public function test_party_comparison_always_starts_with_viewers_goods_and_snapshot_values(): void
    {
        [$a, $b, $e] = $this->fixture();
        foreach ([[$a, 'a', 'Cemento Portland', 'Stucco per esterni'], [$b, 'b', 'Stucco per esterni', 'Cemento Portland']] as [$viewer, $side, $ours, $theirs]) {
            $xpath = $this->page($viewer, $e);
            $this->assertSame('1', $xpath->query('//*[@data-current-version]')->item(0)->getAttribute('data-current-version'));
            $cards = $xpath->query('//*[@data-current-version]//*[@data-exchange-side]');
            $this->assertSame(2, $cards->length);
            $this->assertSame($side, $cards->item(0)->getAttribute('data-exchange-side'));
            $this->assertSame('Tu offri', $cards->item(0)->getAttribute('data-party-label'));
            $this->assertStringContainsString($ours, $cards->item(0)->textContent);
            $this->assertStringNotContainsString($theirs, $cards->item(0)->textContent);
            $this->assertStringContainsString($theirs, $cards->item(1)->textContent);
            $this->assertSame('301,00 €', trim($xpath->query('//*[@data-difference]')->item(0)->textContent));
        }
    }

    public function test_previous_versions_remain_collapsed_readable_and_have_no_action_forms(): void
    {
        [$a, $b, $e, $input] = $this->fixture();
        $v = $e->currentVersion;
        app(ExchangeWorkflow::class)->accept($a->user, $e, $v->id, $v->summary_hash);
        $input['key'] = (string) Str::uuid();
        $input['notes'] = 'Accordi della versione corrente';
        $input['base_version_id'] = $v->id;
        $input['base_summary_hash'] = $v->summary_hash;
        $e = app(ExchangeWorkflow::class)->counter($b->user, $e, $input);
        $xpath = $this->page($b, $e);
        $previous = $xpath->query('//details[@data-previous-version="1"]')->item(0);
        $this->assertNotNull($previous);
        $this->assertFalse($previous->hasAttribute('open'));
        $this->assertStringContainsString('Accordi della prima versione', $previous->textContent);
        $this->assertStringContainsString('non valide per la versione corrente', $previous->textContent);
        $this->assertSame(0, $xpath->query('.//form', $previous)->length);
        $this->assertSame('b', $xpath->query('.//*[@data-exchange-side]', $previous)->item(0)->getAttribute('data-exchange-side'));
        $this->assertSame(1, $xpath->query('//*[@data-current-version="2"]')->length);
        $this->assertSame(0, $xpath->query('//details[@data-previous-version="2"]')->length);
        $this->assertSame(0, $e->currentVersion->acceptances()->count());
    }

    public function test_acceptance_next_step_and_form_payload_follow_current_version(): void
    {
        [$a, $b, $e] = $this->fixture();
        $xpath = $this->page($a, $e);
        $this->assertStringContainsString('accetta la versione 1', $xpath->query('//*[@data-next-action]')->item(0)->textContent);
        $this->assertSame(2, $xpath->query('//*[@data-acceptance-side]//p[text()="In attesa"]')->length);
        $this->postForm($xpath, 'retailer.exchanges.accept', $e);
        $xpath = $this->page($a, $e);
        $this->assertStringContainsString($b->company_name.' accetti la versione 1', $xpath->query('//*[@data-next-action]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//*[@data-acceptance-side="a"]//p[text()="Accettato"]')->length);
        $this->assertSame(0, $xpath->query('//form[@action="'.route('retailer.exchanges.accept', $e).'"]')->length);
        $this->postForm($this->page($b, $e), 'retailer.exchanges.accept', $e);
        $this->assertSame('awaiting_difference_payment', $e->fresh()->status->value);
    }

    public function test_payment_receipt_and_completion_forms_keep_the_existing_workflow(): void
    {
        [$a, $b, $e] = $this->fixture();
        $e = $this->accepted($a, $b, $e);
        app(StartExchangeDifferencePayment::class)->handle($e->currentVersion);
        $xpath = $this->page($a, $e);
        $this->assertStringContainsString('Paga il conguaglio di 301,00 €', $xpath->query('//*[@data-next-action]')->item(0)->textContent);
        $this->assertStringContainsString('Modalità simulazione', $xpath->query('//main')->item(0)->textContent);
        $this->assertStringContainsString('Nessun pagamento reale verrà effettuato.', $xpath->query('//main')->item(0)->textContent);
        $other = $this->page($b, $e);
        $this->assertSame(0, $other->query('//form[@action="'.route('retailer.exchanges.simulate', $e).'"]')->length);
        $this->assertStringContainsString('Attendi il pagamento', $other->query('//*[@data-next-action]')->item(0)->textContent);
        $this->postForm($this->page($a, $e), 'retailer.exchanges.simulate', $e);
        $this->assertSame('confirmed', $e->fresh()->status->value);
        $this->postForm($this->page($a, $e), 'retailer.exchanges.complete', $e);
        $waiting = $this->page($a, $e);
        $this->assertStringContainsString('Hai confermato la ricezione', $waiting->query('//*[@data-next-action]')->item(0)->textContent);
        $this->postForm($this->page($b, $e), 'retailer.exchanges.complete', $e);
        $completed = $this->page($a, $e);
        $this->assertSame('completed', $e->fresh()->status->value);
        $this->assertStringContainsString('Lo scambio è completato', $completed->query('//*[@data-next-action]')->item(0)->textContent);
        $this->assertSame(0, $completed->query('//*[@data-primary-actions]//form')->length);
        $this->assertStringContainsString('Scambio completato', $completed->query('//*[@aria-labelledby="timeline-title"]')->item(0)->textContent);
    }

    public function test_existing_stripe_link_is_presented_without_fake_action(): void
    {
        [$a, $b, $e] = $this->fixture();
        $e = $this->accepted($a, $b, $e);
        app(StartExchangeDifferencePayment::class)->handle($e->currentVersion);
        $e->currentVersion->payment()->update(['provider' => 'stripe_connect', 'simulated' => false, 'checkout_url' => 'https://checkout.stripe.test/example']);
        $xpath = $this->page($a, $e);
        $this->assertSame(1, $xpath->query('//a[@href="https://checkout.stripe.test/example"]')->length);
        $this->assertSame(0, $xpath->query('//form[@action="'.route('retailer.exchanges.simulate', $e).'"]')->length);
        $this->assertStringNotContainsString('Modalità simulazione', $xpath->query('//main')->item(0)->textContent);
    }

    public function test_provisional_payment_remains_disabled_and_zero_provisional_is_not_final(): void
    {
        [$a, $b, $e] = $this->fixture(provisional: true);
        $e = $this->accepted($a, $b, $e);
        app(StartExchangeDifferencePayment::class)->handle($e->currentVersion);
        $xpath = $this->page($a, $e);
        $this->assertStringContainsString('non è determinata', $xpath->query('//*[@data-next-action]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('retailer.exchanges.simulate', $e).'"]//button[@disabled]')->length);
        $this->assertStringContainsString('Il conguaglio è provvisorio e non può ancora essere pagato.', $xpath->query('//main')->item(0)->textContent);
        [$a, $b, $zero] = $this->fixture(equal: true, provisional: true);
        $xpath = $this->page($a, $zero);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('retailer.exchanges.accept', $zero).'"]//button[@disabled]')->length);
        $this->assertStringContainsString('Valori provvisori uguali', $xpath->query('//main')->item(0)->textContent);
    }

    public function test_no_difference_confirms_without_payment_and_keeps_receipt_available_after_downgrade(): void
    {
        [$a, $b, $e] = $this->fixture(equal: true);
        $e = $this->accepted($a, $b, $e);
        $a->activeSubscription()->first()->update(['plan_id' => Plan::where('code', 'free')->sole()->id]);
        $xpath = $this->page($a, $e);
        $this->assertStringContainsString('Nessun conguaglio', $xpath->query('//main')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('retailer.exchanges.complete', $e).'"]')->length);
        $this->assertSame(0, $xpath->query('//form[@action="'.route('retailer.exchanges.simulate', $e).'"]')->length);
        $this->assertDatabaseCount('exchange_difference_payments', 0);
    }

    public function test_closed_and_expired_states_have_no_actions_and_get_is_read_only(): void
    {
        foreach (['rejected', 'cancelled', 'expired'] as $state) {
            [$a, $b, $e] = $this->fixture();
            if ($state === 'expired') {
                $this->travel(49)->hours();
            } else {
                $v = $e->currentVersion;
                app(ExchangeWorkflow::class)->stop($a->user, $e, $v->id, $v->summary_hash, $state);
            }
            $before = $e->fresh()->getAttributes();
            $events = $e->events()->count();
            $xpath = $this->page($a, $e);
            $this->assertSame(0, $xpath->query('//main//form')->length);
            $this->assertStringContainsString('Nessuna azione richiesta', $xpath->query('//main')->item(0)->textContent);
            $this->assertSame($before, $e->fresh()->getAttributes());
            $this->assertSame($events, $e->events()->count());
            $this->travelBack();
        }
    }

    public function test_delivery_and_fiscal_information_remain_available_without_product_queries(): void
    {
        [$a, $b, $e] = $this->fixture(shipping: true);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $xpath = $this->page($a, $e);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $card = $xpath->query('//*[@data-current-version]//*[@data-exchange-side="a"]')->item(0)->textContent;
        foreach (['Spedizione', 'Ricadi (VV)', '100,00 €', $b->company_name] as $text) {
            $this->assertStringContainsString($text, $card);
        }
        $financial = $xpath->query('//*[@data-current-version]//details')->item(0);
        $this->assertFalse($financial->hasAttribute('open'));
        $this->assertStringContainsString('Commissione sul solo conguaglio', $financial->textContent);
        $this->assertStringContainsString('Ciascun rivenditore documenta la propria cessione', $financial->textContent);
        $this->assertStringContainsString(DisplayMoney::euros($e->currentVersion->commission_snapshot['amount_cents']), $financial->textContent);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/from ["`]?inventory_(items|images)/i', $query['query']);
        }
    }
}
