<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\StockReservation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ExchangeWorkflow;
use App\Services\Payments\ApplyExchangePaymentResult;
use App\Services\Payments\SimulateExchangeDifferencePayment;
use App\Services\Payments\StartExchangeDifferencePayment;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class ExchangeDomainTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function retailer(string $plan = 'pro'): Retailer
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', $plan)->sole()->id, 'starts_at' => now()->subDay()]);

        return $r;
    }

    private function product(Retailer $r, array $data = []): InventoryItem
    {
        return InventoryItem::factory()->published()->create(array_replace(['retailer_id' => $r->id, 'exchange_available' => true, 'quantity' => '2', 'pickup_available' => true, 'shipping_available' => true, 'vat_treatment' => 'not_applicable'], $data));
    }

    private function fixture(string $a = '10', string $b = '13'): array
    {
        $ra = $this->retailer();
        $rb = $this->retailer();
        $pa = $this->product($ra);
        $pb = $this->product($rb);
        $input = $this->input($pa, $pb, $a, $b);
        $e = app(ExchangeWorkflow::class)->create($ra->user, $pb, $input);

        return [$ra, $rb, $pa, $pb, $e, $input];
    }

    private function input(InventoryItem $a, InventoryItem $b, string $va = '10', string $vb = '13'): array
    {
        return ['key' => (string) Str::uuid(), 'lines' => ['a' => [['inventory_item_id' => $a->id, 'quantity' => '1', 'value' => $va]], 'b' => [['inventory_item_id' => $b->id, 'quantity' => '1', 'value' => $vb]]], 'delivery' => ['a' => ['mode' => 'pickup', 'cost' => '0'], 'b' => ['mode' => 'pickup', 'cost' => '0']], 'notes' => 'Riepilogo concordato'];
    }

    private function accept(Retailer $r, Exchange $e): Exchange
    {
        $v = $e->fresh()->currentVersion;

        return app(ExchangeWorkflow::class)->accept($r->user, $e, $v->id, $v->summary_hash);
    }

    private function dual(array $f): Exchange
    {
        [$a,$b,$pa,$pb,$e] = $f;
        $this->accept($a, $e);

        return $this->accept($b, $e);
    }

    private function expectInvalid(callable $operation, string $field = 'exchange'): void
    {
        try {
            $operation();
            $this->fail('Invalid exchange operation accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_pro_to_pro_proposal_is_snapshotted_and_requires_two_explicit_acceptances(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $v = $e->currentVersion;
        $this->assertSame('proposed', $e->status->value);
        $this->assertSame(1000, $v->value_a_cents);
        $this->assertSame(1300, $v->value_b_cents);
        $this->assertSame(300, $v->difference_cents);
        $this->assertSame($a->id, $v->payer_retailer_id);
        $this->assertSame($b->id, $v->payee_retailer_id);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('exchange_acceptances', 0);
        $this->actingAs($a->user)->get(route('retailer.exchanges.show', $e))->assertOk()->assertSee('Storico versioni')->assertSee('3,00');
        $pa->update(['name' => 'Nome cambiato', 'zero_price_cents' => 1]);
        $this->assertNotSame('Nome cambiato', $v->lines->first()->product_snapshot['name']);
    }

    public function test_free_customer_unapproved_expired_or_counterparty_free_cannot_propose(): void
    {
        $free = $this->retailer('free');
        $pro = $this->retailer();
        $a = $this->product($free);
        $b = $this->product($pro);
        $this->actingAs($free->user)->post(route('retailer.exchanges.store'), $this->input($a, $b) + ['requested_item_id' => $b->id])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get(route('retailer.exchanges.index'))->assertForbidden();
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($pro->user, $a, $this->input($b, $a)));
        $pro->activeSubscription->update(['ends_at' => now()->subSecond()]);
        $this->actingAs($pro->user->fresh())->get(route('retailer.exchanges.create', $a))->assertForbidden();
        $this->assertDatabaseCount('exchanges', 0);
    }

    public function test_non_exchangeable_pending_own_or_unavailable_product_is_rejected(): void
    {
        $a = $this->retailer();
        $b = $this->retailer();
        $pa = $this->product($a);
        $pb = $this->product($b, ['exchange_available' => false]);
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($a->user, $pb, $this->input($pa, $pb)), 'lines');
        $pb->update(['exchange_available' => true, 'status' => 'pending']);
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($a->user, $pb, $this->input($pa, $pb)), 'lines');
        $pb->update(['status' => 'published', 'quantity' => '0']);
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($a->user, $pb, $this->input($pa, $pb)), 'lines');
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($a->user, $pa, $this->input($pa, $pa)));
    }

    public function test_counterproposal_creates_immutable_version_and_old_acceptance_does_not_count(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $old = $e->currentVersion;
        $this->accept($a, $e);
        $input = $this->input($pa, $pb, '11', '13') + ['base_version_id' => $old->id, 'base_summary_hash' => $old->summary_hash];
        $updated = app(ExchangeWorkflow::class)->counter($b->user, $e, $input);
        $v = $updated->currentVersion;
        $this->assertSame(2, $v->number);
        $this->assertSame(200, $v->difference_cents);
        $this->assertSame('countered', $updated->status->value);
        $this->assertSame(1, $old->acceptances()->count());
        $this->assertSame(0, $v->acceptances()->count());
        $this->assertSame(1000, $old->fresh()->value_a_cents);
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->accept($b->user, $e, $old->id, $old->summary_hash), 'version');
        $this->accept($b, $updated);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->accept($a, $updated);
        $this->assertSame(2, StockReservation::where('status', 'active')->count());
    }

    public function test_duplicate_acceptance_does_not_count_as_second_party_and_retries_keep_one_proposal(): void
    {
        [$a,$b,$pa,$pb,$e,$input] = $this->fixture();
        $this->accept($a, $e);
        $this->accept($a, $e);
        $this->assertDatabaseCount('exchange_acceptances', 1);
        $this->assertDatabaseCount('stock_reservations', 0);
        $retry = app(ExchangeWorkflow::class)->create($a->user, $pb, $input);
        $this->assertSame($e->id, $retry->id);
        $this->assertDatabaseCount('exchanges', 1);
        $this->assertDatabaseCount('exchange_versions', 1);
        $input['notes'] = 'Changed';
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($a->user, $pb, $input), 'key');
    }

    public function test_equal_values_confirm_and_consume_stock_atomically_without_payment_then_both_complete(): void
    {
        $f = $this->fixture('10', '10');
        [$a,$b,$pa,$pb,$e] = $f;
        $e = $this->dual($f);
        $this->assertSame('confirmed', $e->status->value);
        $this->assertSame(0, $e->currentVersion->difference_cents);
        $this->assertNull($e->currentVersion->payer_retailer_id);
        $this->assertDatabaseCount('exchange_difference_payments', 0);
        $this->assertSame(2, StockReservation::where('status', 'consumed')->count());
        $this->assertSame(1000, $pa->fresh()->quantity_milliunits);
        $this->assertSame(1000, $pb->fresh()->quantity_milliunits);
        $v = $e->currentVersion;
        app(ExchangeWorkflow::class)->complete($a->user, $e, $v->id, $v->summary_hash);
        $this->assertSame('confirmed', $e->fresh()->status->value);
        app(ExchangeWorkflow::class)->complete($b->user, $e, $v->id, $v->summary_hash);
        $this->assertSame('completed', $e->fresh()->status->value);
        app(ExchangeWorkflow::class)->complete($b->user, $e, $v->id, $v->summary_hash);
        $this->assertDatabaseCount('exchange_completions', 2);
    }

    public function test_difference_a_to_b_reserves_both_sides_and_only_fake_success_confirms_once(): void
    {
        $f = $this->fixture('1000', '1300');
        [$a,$b,$pa,$pb,$e] = $f;
        $e = $this->dual($f);
        $v = $e->currentVersion;
        $this->assertSame('awaiting_difference_payment', $e->status->value);
        $this->assertSame(30000, $v->difference_cents);
        $this->assertSame(2, StockReservation::where('status', 'active')->count());
        $this->assertSame(2000, $pa->fresh()->quantity_milliunits);
        $this->assertSame(1000, $pa->fresh()->available_quantity_milliunits);
        $outcome = app(SimulateExchangeDifferencePayment::class)->handle($a->user, $e, $v->id, $v->summary_hash);
        $this->assertSame('confirmed', $outcome);
        $this->assertSame('confirmed', $e->fresh()->status->value);
        $this->assertSame('paid', $v->payment()->sole()->status);
        $this->assertSame(1000, $pb->fresh()->quantity_milliunits);
        $this->assertSame('confirmed', app(SimulateExchangeDifferencePayment::class)->handle($a->user, $e, $v->id, $v->summary_hash));
        $this->assertSame(1000, $pa->fresh()->quantity_milliunits);
        $this->assertDatabaseCount('exchange_payment_events', 1);
    }

    public function test_difference_b_to_a_computes_payer_without_including_transport_or_taxes(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture('15', '10');
        $v = $e->currentVersion;
        $this->assertSame($b->id, $v->payer_retailer_id);
        $this->assertSame($a->id, $v->payee_retailer_id);
        $this->assertSame(500, $v->difference_cents);
        $input = $this->input($pa, $pb, '15', '10') + ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
        $input['delivery']['a'] = ['mode' => 'shipping', 'cost' => '7.25', 'destination' => 'Magazzino B, Via Roma 1'];
        $e = app(ExchangeWorkflow::class)->counter($a->user, $e, $input);
        $v = $e->currentVersion;
        $this->assertSame(500, $v->difference_cents);
        $this->assertSame(725, $v->delivery_a['shipping_cost_cents']);
        $this->assertTrue($v->payment_provisional);
        $this->assertSame(50, $v->commission_snapshot['basis_points']);
        $this->assertSame(3, $v->commission_snapshot['amount_cents']);
    }

    public function test_b_pays_a_with_zero_transport_and_fake_receipts_reject_tampering(): void
    {
        $f = $this->fixture('15', '10');
        [$a,$b,$pa,$pb,$e] = $f;
        $e = $this->dual($f);
        $v = $e->currentVersion;
        $this->assertSame('confirmed', app(SimulateExchangeDifferencePayment::class)->handle($b->user, $e, $v->id, $v->summary_hash));
        $p = $v->payment()->sole();
        try {
            app(ApplyExchangePaymentResult::class)->handle($v, 'bad-amount', $p->reference, 'paid', 1, 'EUR');
            $this->fail('Wrong amount accepted');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('exchange_payment_events', 1);
        }
        try {
            app(ApplyExchangePaymentResult::class)->handle($v, 'fake-exchange-paid:'.$v->id, $p->reference, 'failed', $p->amount_cents, 'EUR');
            $this->fail('Conflicting event replay accepted');
        } catch (\LogicException) {
            $this->assertSame('paid', $p->fresh()->status);
        }
        $this->assertSame(1000, $pa->fresh()->quantity_milliunits);
    }

    public function test_suspended_counterparty_and_future_pro_subscription_are_rejected(): void
    {
        $a = $this->retailer();
        $b = $this->retailer();
        $pa = $this->product($a);
        $pb = $this->product($b);
        $b->update(['status' => 'suspended']);
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($a->user, $pb, $this->input($pa, $pb)));
        $b->update(['status' => 'approved']);
        $b->activeSubscription->update(['starts_at' => now()->addDay()]);
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($a->user, $pb, $this->input($pa, $pb)));
        $this->assertDatabaseCount('exchanges', 0);
    }

    public function test_expired_pending_payment_and_stale_counter_requests_cannot_confirm_or_overwrite(): void
    {
        $f = $this->fixture();
        [$a,$b,$pa,$pb,$e] = $f;
        $e = $this->dual($f);
        $old = $e->currentVersion;
        app(StartExchangeDifferencePayment::class)->handle($old);
        $body = $this->input($pa, $pb) + ['base_version_id' => $old->id, 'base_summary_hash' => $old->summary_hash];
        $this->travel(1441)->minutes();
        $payment = $old->payment;
        $outcome = app(ApplyExchangePaymentResult::class)->handle($old, 'late-payment', $payment->reference, 'paid', $payment->amount_cents, 'EUR');
        $this->assertSame('reconciliation_required', $outcome);
        $this->assertSame('expired', $e->fresh()->status->value);
        $this->assertSame(2000, $pa->fresh()->quantity_milliunits);
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->counter($b->user, $e, $body));
    }

    public function test_multi_product_offer_exact_fractional_quantities_and_values(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $extra = $this->product($a);
        $v = $e->currentVersion;
        $input = $this->input($pa, $pb, '10.01', '13.25') + ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
        $input['lines']['a'][0]['quantity'] = '0.500';
        $input['lines']['a'][] = ['inventory_item_id' => $extra->id, 'quantity' => '1.250', 'value' => '2.00'];
        $e = app(ExchangeWorkflow::class)->counter($a->user, $e, $input);
        $this->assertSame(751, $e->currentVersion->value_a_cents);
        $this->assertSame(1325, $e->currentVersion->value_b_cents);
        $this->assertSame(574, $e->currentVersion->difference_cents);
        $this->accept($a, $e);
        $this->accept($b, $e);
        $this->assertDatabaseCount('stock_reservations', 3);
    }

    public function test_reject_and_cancel_release_all_reserved_quantities_and_preserve_history(): void
    {
        foreach (['rejected', 'cancelled'] as $state) {
            $f = $this->fixture();
            [$a,$b,$pa,$pb,$e] = $f;
            $e = $this->dual($f);
            $v = $e->currentVersion;
            app(ExchangeWorkflow::class)->stop($b->user, $e, $v->id, $v->summary_hash, $state);
            $this->assertSame($state, $e->fresh()->status->value);
            $this->assertSame(2000, $pa->fresh()->available_quantity_milliunits);
            $this->assertSame(2, $e->stockReservations()->where('status', 'released')->count());
            $this->assertSame(2, $v->acceptances()->count());
        }
    }

    public function test_proposal_and_reserved_exchange_expire_idempotently_via_both_commands(): void
    {
        config(['marketplace.exchange_proposal_hours' => 1]);
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $this->travel(61)->minutes();
        $this->artisan('marketplace:expire-exchanges')->assertExitCode(0);
        $this->assertSame('expired', $e->fresh()->status->value);
        $this->artisan('marketplace:expire-exchanges')->assertExitCode(0);
        $this->assertSame(1, $e->events()->where('action', 'expired')->count());
        $this->travelBack();
        $f = $this->fixture();
        [$a,$b,$pa,$pb,$e] = $f;
        $e = $this->dual($f);
        $this->travel(1441)->minutes();
        $this->assertSame(2000, $pa->fresh()->available_quantity_milliunits);
        $this->artisan('marketplace:expire-reservations')->assertExitCode(0);
        $this->assertSame('expired', $e->fresh()->status->value);
        $this->assertSame(2, $e->stockReservations()->where('status', 'expired')->count());
    }

    public function test_counter_after_dual_acceptance_releases_old_holds_and_invalidates_old_payment(): void
    {
        $f = $this->fixture();
        [$a,$b,$pa,$pb,$e] = $f;
        $e = $this->dual($f);
        $old = $e->currentVersion;
        app(StartExchangeDifferencePayment::class)->handle($old);
        $reference = $old->payment->reference;
        $input = $this->input($pa, $pb, '12', '13') + ['base_version_id' => $old->id, 'base_summary_hash' => $old->summary_hash];
        $e = app(ExchangeWorkflow::class)->counter($b->user, $e, $input);
        $this->assertSame(2, $e->currentVersion->number);
        $this->assertSame(0, $e->currentVersion->acceptances()->count());
        $this->assertSame('cancelled', $old->payment()->sole()->status);
        $this->assertSame(2000, $pa->fresh()->available_quantity_milliunits);
        $result = app(ApplyExchangePaymentResult::class)->handle($old, 'old-paid', $reference, 'paid', $old->difference_cents, 'EUR');
        $this->assertSame('reconciliation_required', $result);
        $this->assertSame('countered', $e->fresh()->status->value);
        $this->assertSame(2000, $pb->fresh()->quantity_milliunits);
    }

    public function test_unknown_vat_prevents_fake_capture_and_failed_payment_releases_stock(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $pa->update(['vat_treatment' => null]);
        $v = $e->currentVersion;
        $input = $this->input($pa, $pb) + ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
        $e = app(ExchangeWorkflow::class)->counter($a->user, $e, $input);
        $this->accept($a, $e);
        $e = $this->accept($b, $e);
        $v = $e->currentVersion;
        $this->assertSame('tax_unresolved', app(SimulateExchangeDifferencePayment::class)->handle($a->user, $e, $v->id, $v->summary_hash));
        $this->assertSame('awaiting_difference_payment', $e->fresh()->status->value);
        $this->assertSame(2000, $pa->fresh()->quantity_milliunits);
        $p = $v->payment()->sole();
        $this->assertSame('released', app(ApplyExchangePaymentResult::class)->handle($v, 'failed-1', $p->reference, 'failed', $p->amount_cents, 'EUR'));
        $this->assertSame(2000, $pa->fresh()->available_quantity_milliunits);
    }

    public function test_third_party_cannot_view_accept_counter_or_simulate_and_payee_cannot_pay(): void
    {
        $f = $this->fixture();
        [$a,$b,$pa,$pb,$e] = $f;
        $v = $e->currentVersion;
        $outsider = $this->retailer();
        $body = ['version_id' => $v->id, 'summary_hash' => $v->summary_hash];
        $this->actingAs($outsider->user)->get(route('retailer.exchanges.show', $e))->assertForbidden();
        foreach (['accept', 'reject', 'cancel', 'simulate', 'complete'] as $action) {
            $this->post(route('retailer.exchanges.'.$action, $e), $body)->assertForbidden();
        }
        $e = $this->dual($f);
        $this->actingAs($b->user)->post(route('retailer.exchanges.simulate', $e), $body)->assertForbidden();
        $this->assertSame(0, $e->events()->where('action', 'difference_paid')->count());
    }

    public function test_foreign_or_duplicate_products_and_invalid_delivery_are_rejected(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $third = $this->product($this->retailer());
        $v = $e->currentVersion;
        $input = $this->input($pa, $pb) + ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
        $input['lines']['a'][0]['inventory_item_id'] = $third->id;
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->counter($a->user, $e, $input), 'lines');
        $input = $this->input($pa, $pb) + ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
        $input['lines']['a'][] = $input['lines']['a'][0];
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->counter($a->user, $e, $input), 'lines');
        $input = $this->input($pa, $pb) + ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
        $input['delivery']['a']['cost'] = '1';
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->counter($a->user, $e, $input), 'delivery');
        $this->assertDatabaseCount('exchange_versions', 1);
    }

    public function test_versions_lines_and_acceptances_are_immutable_in_database(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $this->accept($a, $e);
        foreach (['exchange_versions', 'exchange_lines', 'exchange_acceptances', 'exchange_events'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(fn () => $operation === 'update' ? DB::table($table)->update(['created_at' => now()->subDay()]) : DB::table($table)->delete());
                    $this->fail('Immutable record modified');
                } catch (QueryException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    public function test_sealed_versions_cannot_acquire_extra_lines_even_when_superseded(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $old = $e->currentVersion;
        $extra = $this->product($a);
        $input = $this->input($pa, $pb) + ['base_version_id' => $old->id, 'base_summary_hash' => $old->summary_hash];
        app(ExchangeWorkflow::class)->counter($b->user, $e, $input);
        $row = $old->lines()->first()->getAttributes();
        unset($row['id']);
        $row['inventory_item_id'] = $extra->id;
        try {
            DB::transaction(fn () => DB::table('exchange_lines')->insert($row));
            $this->fail('Sealed version acquired another line');
        } catch (QueryException) {
            $this->assertSame(2, $old->lines()->count());
        }
    }

    public function test_server_revalidates_stock_and_plan_at_second_acceptance_and_rolls_back_acceptance(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $this->accept($a, $e);
        $pb->update(['quantity' => '0']);
        $this->expectInvalid(fn () => $this->accept($b, $e), 'lines');
        $this->assertDatabaseCount('exchange_acceptances', 1);
        $this->assertDatabaseCount('stock_reservations', 0);
        $pb->update(['quantity' => '2']);
        $b->activeSubscription->update(['ends_at' => now()->subSecond()]);
        $this->expectInvalid(fn () => $this->accept($b, $e)); // The remaining party must still be eligible at the second acceptance.
    }

    public function test_unselected_checkbox_rows_are_ignored_even_without_javascript(): void
    {
        $a = $this->retailer();
        $b = $this->retailer();
        $pa = $this->product($a);
        $pb = $this->product($b);
        $extra = $this->product($a);
        $input = $this->input($pa, $pb);
        $input['selection_mode'] = 'checkboxes';
        $input['lines']['a'][0]['selected'] = '1';
        $input['lines']['b'][0]['selected'] = '1';
        $input['lines']['a'][] = ['inventory_item_id' => $extra->id, 'quantity' => '10000', 'value' => '0'];
        $this->actingAs($a->user)->post(route('retailer.exchanges.store'), $input + ['requested_item_id' => $pb->id])->assertSessionHasNoErrors();
        $v = Exchange::sole()->currentVersion;
        $this->assertSame(2, $v->lines()->count());
        $this->assertFalse($v->lines->contains('inventory_item_id', $extra->id));
    }

    public function test_full_version_history_has_fixed_query_count_without_n_plus_one(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        foreach (range(1, 8) as $i) {
            $v = $e->fresh()->currentVersion;
            $input = $this->input($pa, $pb) + ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
            $e = app(ExchangeWorkflow::class)->counter($b->user, $e, $input);
        }
        $this->actingAs($a->user->fresh());
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->get(route('retailer.exchanges.show', $e))->assertOk()->assertSee('Versione 9');
            $this->assertLessThanOrEqual(16, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_pro_plan_without_active_subscription_is_not_eligible(): void
    {
        $a = $this->retailer();
        $b = $this->retailer();
        $b->activeSubscription->update(['status' => 'pending', 'starts_at' => null]);
        $pa = $this->product($a);
        $pb = $this->product($b);
        $this->expectInvalid(fn () => app(ExchangeWorkflow::class)->create($a->user->fresh(), $pb, $this->input($pa, $pb)));
        $this->assertDatabaseCount('exchanges', 0);
    }

    public function test_http_create_list_tabs_counter_accept_and_simulate_use_exact_version(): void
    {
        $a = $this->retailer();
        $b = $this->retailer();
        $pa = $this->product($a);
        $pb = $this->product($b);
        $input = $this->input($pa, $pb);
        $this->actingAs($a->user)->get(route('marketplace.exchange', $pb))->assertOk()->assertSee('Valore unitario merci');
        $this->post(route('retailer.exchanges.store'), $input + ['requested_item_id' => $pb->id])->assertSessionHasNoErrors();
        $e = Exchange::sole();
        $v = $e->currentVersion;
        $body = ['version_id' => $v->id, 'summary_hash' => $v->summary_hash];
        $this->get(route('retailer.exchanges.index', ['tab' => 'sent']))->assertSee('Scambio #'.$e->id);
        $this->post(route('retailer.exchanges.accept', $e), $body)->assertSessionHasNoErrors();
        $this->get(route('retailer.exchanges.index', ['tab' => 'to_accept']))->assertDontSee('Apri scambio #'.$e->id);
        $this->actingAs($b->user)->get(route('retailer.exchanges.index', ['tab' => 'received']))->assertSee('Scambio #'.$e->id);
        $this->post(route('retailer.exchanges.accept', $e), $body)->assertSessionHasNoErrors();
        $this->actingAs($a->user)->post(route('retailer.exchanges.simulate', $e), $body)->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $e->fresh()->status->value);
    }
}
