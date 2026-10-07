<?php

namespace Tests\Feature;

use App\Actions\RecordAdministrativeAction;
use App\Enums\AdministrativeAction;
use App\Enums\InventoryCondition;
use App\Enums\InventoryStatus;
use App\Enums\RetailerStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\AvailabilityRequest;
use App\Models\IntegrationEvent;
use App\Models\InventoryImage;
use App\Models\InventoryItem;
use App\Models\PayoutRequest;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletBalance;
use App\Support\MoneyMath;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_and_relations_cover_the_domain(): void
    {
        $retailer = Retailer::factory()->approved()->create();
        $subscription = Subscription::factory()->active()->for($retailer)->create();
        $item = InventoryItem::factory()->published()->for($retailer)->create(['quantity' => '12.125']);
        $image = InventoryImage::factory()->for($item)->create();
        $request = AvailabilityRequest::factory()->closed()->for($item)->create();
        $sale = Sale::factory()->create();
        $line = SaleItem::factory()->for($sale)->for($item)->create();
        $payout = PayoutRequest::factory()->paid()->for($retailer)->create();
        $event = IntegrationEvent::factory()->processed()->create();
        $transaction = WalletTransaction::factory()->saleCredit()->for($line, 'saleItem')->for($event)->create();
        $audit = AuditLog::factory()->create();

        $this->assertTrue($retailer->user->retailer->is($retailer));
        $this->assertTrue($retailer->activeSubscription->is($subscription));
        $this->assertTrue($subscription->plan->subscriptions->contains($subscription));
        $this->assertTrue($retailer->subscriptions->contains($subscription));
        $this->assertTrue($retailer->inventoryItems->contains($item));
        $this->assertTrue($item->images->contains($image));
        $this->assertTrue($image->inventoryItem->is($item));
        $this->assertTrue($item->availabilityRequests->contains($request));
        $this->assertTrue($request->retailer->is($retailer));
        $this->assertTrue($retailer->availabilityRequests->contains($request));
        $this->assertTrue($sale->items->contains($line));
        $this->assertTrue($line->sale->is($sale));
        $this->assertTrue($line->inventoryItem->is($item));
        $this->assertTrue($retailer->saleItems->contains($line));
        $this->assertTrue($item->saleItems->contains($line));
        $this->assertTrue($line->walletTransactions->contains($transaction));
        $this->assertTrue($retailer->walletTransactions->contains($transaction));
        $this->assertTrue($transaction->integrationEvent->is($event));
        $this->assertTrue($event->walletTransactions->contains($transaction));
        $this->assertTrue($retailer->payoutRequests->contains($payout));
        $this->assertTrue($audit->subject instanceof Retailer);
        $this->assertTrue($audit->actor->auditLogs->contains($audit));
        $this->assertSame(RetailerStatus::Approved, $retailer->fresh()->status);
        $this->assertSame(InventoryStatus::Published, $item->fresh()->status);
        $this->assertSame(InventoryCondition::New, $item->fresh()->condition);
        $this->assertSame('12.125', $item->fresh()->quantity);
        $this->assertSame(12125, $item->fresh()->quantity_milliunits);
    }

    public function test_plan_seeding_is_repeatable_and_preserves_configured_plans(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->assertSame(2, Plan::count());
        $free = Plan::where('code', 'free')->sole();
        $pro = Plan::where('code', 'pro')->sole();
        $this->assertSame(0, $free->monthly_price_cents);
        $this->assertSame(5, $free->max_items);
        $this->assertSame(1000000, $free->max_inventory_value_cents);
        $this->assertFalse($free->exchange_available);
        $this->assertSame(200, $free->commission_basis_points);
        $this->assertSame(6900, $pro->monthly_price_cents);
        $this->assertSame(49900, $pro->annual_price_cents);
        $this->assertNull($pro->max_items);
        $this->assertNull($pro->max_inventory_value_cents);
        $this->assertTrue($pro->exchange_available);
        $this->assertSame(50, $pro->commission_basis_points);
        $pro->update(['monthly_price_cents' => 7500]);
        $this->seed(PlanSeeder::class);
        $this->assertSame(7500, $pro->fresh()->monthly_price_cents);
    }

    public function test_money_and_quantity_arithmetic_is_exact_and_rounds_half_up(): void
    {
        $item = InventoryItem::factory()->create(['quantity' => '1.250', 'zero_price_cents' => 1999]);
        $this->assertSame(2499, $item->inventoryValueCents());
        $this->assertSame(1, MoneyMath::commissionCents(100, 50));
        $this->assertSame(138, MoneyMath::commissionCents(6900, 200));
        $this->assertSame(PHP_INT_MAX, MoneyMath::commissionCents(PHP_INT_MAX, 10000));
        $this->assertSame(0, MoneyMath::commissionCents(1, 50));
        $plan = Plan::factory()->create(['commission_basis_points' => 50]);
        $this->assertSame(50, $plan->commissionCents(10000));
        $large = InventoryItem::factory()->create(['zero_price_cents' => '9007199254740993']);
        $this->assertSame(9007199254740993, $large->fresh()->zero_price_cents);
    }

    public static function invalidMoney(): array
    {
        // Floats are deliberate invalid inputs, never valid monetary fixtures.
        return [[1.5], ['1.50'], [true], ['1e3'], ['9223372036854775808']];
    }

    #[DataProvider('invalidMoney')]
    public function test_money_cast_rejects_non_integer_or_overflowing_values(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InventoryItem(['zero_price_cents' => $value]);
    }

    public function test_fractional_float_quantities_are_not_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InventoryItem(['quantity' => 1.25]);
    }

    public function test_decimal_quantity_precision_is_not_silently_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InventoryItem(['quantity' => '1.0001']);
    }

    public function test_user_can_have_only_one_retailer_profile(): void
    {
        $retailer = Retailer::factory()->create();
        $this->expectException(QueryException::class);
        Retailer::factory()->for($retailer->user)->create();
    }

    public function test_vat_number_is_unique(): void
    {
        $retailer = Retailer::factory()->create();
        $this->expectException(QueryException::class);
        Retailer::factory()->create(['vat_number' => $retailer->vat_number]);
    }

    public function test_unknown_user_is_rejected_by_foreign_key(): void
    {
        $this->expectException(QueryException::class);
        Retailer::factory()->create(['user_id' => 999999999]);
    }

    public function test_sku_is_unique_within_a_retailer_but_reusable_by_other_retailers(): void
    {
        $item = InventoryItem::factory()->create(['sku' => 'SKU-1']);
        InventoryItem::factory()->create(['sku' => 'SKU-1']);
        $this->expectException(QueryException::class);
        InventoryItem::factory()->for($item->retailer)->create(['sku' => 'SKU-1']);
    }

    public function test_multiple_items_can_have_no_sku(): void
    {
        $retailer = Retailer::factory()->create();
        InventoryItem::factory()->count(2)->for($retailer)->create(['sku' => null]);
        $this->assertSame(2, $retailer->inventoryItems()->count());
    }

    public function test_only_one_active_subscription_is_allowed_while_history_is_retained(): void
    {
        $retailer = Retailer::factory()->create();
        Subscription::factory()->count(2)->for($retailer)->create(['status' => 'expired']);
        Subscription::factory()->active()->for($retailer)->create();
        $this->expectException(QueryException::class);
        Subscription::factory()->active()->for($retailer)->create();
    }

    public function test_changing_plan_keeps_history_without_duplicate_active_subscription(): void
    {
        $retailer = Retailer::factory()->create();
        $old = Subscription::factory()->active()->for($retailer)->create();
        $old->update(['status' => 'expired']);
        $new = Subscription::factory()->active()->for($retailer)->create();
        $this->assertTrue($retailer->activeSubscription->is($new));
        $this->assertSame(2, $retailer->subscriptions()->count());
    }

    public function test_request_cannot_be_assigned_to_a_different_retailer(): void
    {
        $item = InventoryItem::factory()->create();
        $other = Retailer::factory()->create();
        $this->expectException(QueryException::class);
        AvailabilityRequest::factory()->for($item)->create(['retailer_id' => $other->id]);
    }

    public function test_sale_item_cannot_be_assigned_to_a_different_retailer(): void
    {
        $item = InventoryItem::factory()->create();
        $other = Retailer::factory()->create();
        $this->expectException(QueryException::class);
        SaleItem::factory()->for($item)->create(['retailer_id' => $other->id]);
    }

    public function test_sale_item_currency_must_match_the_sale(): void
    {
        $sale = Sale::factory()->create(['currency' => 'EUR']);
        $this->expectException(QueryException::class);
        SaleItem::factory()->for($sale)->create(['currency' => 'USD']);
    }

    public function test_sale_snapshot_survives_inventory_price_and_name_changes(): void
    {
        $line = SaleItem::factory()->create();
        $name = $line->name;
        $line->inventoryItem->update(['name' => 'Nuovo titolo', 'zero_price_cents' => 9999]);
        $this->assertSame($name, $line->fresh()->name);
        $this->assertSame(2500, $line->fresh()->unit_price_cents);
    }

    public function test_financial_records_prevent_deleting_their_inventory_source(): void
    {
        $line = SaleItem::factory()->create();
        $this->expectException(QueryException::class);
        $line->inventoryItem->delete();
    }

    public function test_inventory_image_positions_are_unique(): void
    {
        $image = InventoryImage::factory()->create();
        $this->expectException(QueryException::class);
        InventoryImage::factory()->for($image->inventoryItem)->create(['position' => $image->position]);
    }

    public function test_provider_order_ids_are_unique_per_provider(): void
    {
        Sale::factory()->create(['provider' => 'one', 'external_order_id' => 'order-1']);
        Sale::factory()->create(['provider' => 'two', 'external_order_id' => 'order-1']);
        $this->expectException(QueryException::class);
        Sale::factory()->create(['provider' => 'one', 'external_order_id' => 'order-1']);
    }

    public function test_provider_event_ids_are_unique_per_provider(): void
    {
        IntegrationEvent::factory()->create(['provider' => 'one', 'external_event_id' => 'event-1']);
        IntegrationEvent::factory()->create(['provider' => 'two', 'external_event_id' => 'event-1']);
        $this->expectException(QueryException::class);
        IntegrationEvent::factory()->create(['provider' => 'one', 'external_event_id' => 'event-1']);
    }

    public function test_external_identifiers_are_case_sensitive(): void
    {
        IntegrationEvent::factory()->create(['provider' => 'one', 'external_event_id' => 'ABC']);
        IntegrationEvent::factory()->create(['provider' => 'one', 'external_event_id' => 'abc']);
        $this->assertSame(2, IntegrationEvent::count());
    }

    public function test_a_sale_line_cannot_be_imported_twice(): void
    {
        $line = SaleItem::factory()->create();
        $this->expectException(QueryException::class);
        SaleItem::factory()->for($line->sale)->create(['external_line_id' => $line->external_line_id]);
    }

    public function test_wallet_balance_is_reconstructed_and_scoped_to_owner_currency_and_maturity(): void
    {
        $this->freezeTime();
        $retailer = Retailer::factory()->create();
        WalletTransaction::factory()->for($retailer)->create(['amount_cents' => 10000]);
        WalletTransaction::factory()->for($retailer)->create(['amount_cents' => -200]);
        WalletTransaction::factory()->for($retailer)->create(['amount_cents' => 500, 'available_at' => now()->addDay()]);
        WalletTransaction::factory()->for($retailer)->create(['amount_cents' => 300, 'available_at' => null]);
        WalletTransaction::factory()->for($retailer)->create(['amount_cents' => 777, 'currency' => 'USD']);
        WalletTransaction::factory()->create(['amount_cents' => 999999]);
        $balance = app(WalletBalance::class);
        $this->assertSame(9800, $balance->availableCents($retailer));
        $this->assertSame(10600, $balance->totalCents($retailer));
        $this->assertSame(777, $balance->availableCents($retailer, 'USD'));
        $this->travel(2)->days();
        $this->assertSame(10300, $balance->availableCents($retailer));
    }

    public function test_payout_reservation_and_payment_do_not_double_subtract_credit(): void
    {
        $retailer = Retailer::factory()->create();
        $payout = PayoutRequest::factory()->for($retailer)->create(['amount_cents' => 6000]);
        WalletTransaction::factory()->for($retailer)->create(['amount_cents' => 10000]);
        WalletTransaction::factory()->for($retailer)->for($payout)->create(['type' => 'payout_reservation', 'amount_cents' => -6000]);
        $this->assertSame(4000, app(WalletBalance::class)->availableCents($retailer));
        $this->assertSame(10000, app(WalletBalance::class)->totalCents($retailer));
        $this->assertSame(6000, app(WalletBalance::class)->reservedCents($retailer));
        // The future payout action must post both entries in one transaction.
        DB::transaction(function () use ($retailer, $payout): void {
            WalletTransaction::factory()->for($retailer)->for($payout)->create(['type' => 'payout_release', 'amount_cents' => 6000]);
            WalletTransaction::factory()->for($retailer)->for($payout)->create(['type' => 'payout', 'amount_cents' => -6000]);
        });
        $this->assertSame(4000, app(WalletBalance::class)->availableCents($retailer));
        $this->assertSame(3, $payout->walletTransactions()->count());
        $this->assertSame(4000, app(WalletBalance::class)->totalCents($retailer));
        $this->assertSame(0, app(WalletBalance::class)->reservedCents($retailer));
        WalletTransaction::factory()->for($retailer)->for($payout)->create(['type' => 'payout_reversal', 'amount_cents' => 6000]);
        $this->assertSame(10000, app(WalletBalance::class)->availableCents($retailer));
        $this->assertSame(10000, app(WalletBalance::class)->totalCents($retailer));
    }

    public function test_rejected_payout_releases_the_reservation(): void
    {
        $reservation = WalletTransaction::factory()->reservation()->create();
        WalletTransaction::factory()->for($reservation->retailer)->create(['amount_cents' => 10000]);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($reservation->retailer));
        WalletTransaction::factory()->for($reservation->retailer)->for($reservation->payoutRequest)
            ->create(['type' => 'payout_release', 'amount_cents' => 10000]);
        $this->assertSame(10000, app(WalletBalance::class)->availableCents($reservation->retailer));
    }

    public function test_wallet_idempotency_key_prevents_duplicate_effects(): void
    {
        $entry = WalletTransaction::factory()->create();
        $this->expectException(QueryException::class);
        WalletTransaction::factory()->create(['idempotency_key' => $entry->idempotency_key]);
    }

    public function test_payout_cannot_be_reserved_twice_with_different_keys(): void
    {
        $entry = WalletTransaction::factory()->reservation()->create();
        $this->expectException(QueryException::class);
        WalletTransaction::factory()->for($entry->retailer)->for($entry->payoutRequest)
            ->create(['type' => 'payout_reservation', 'amount_cents' => -10000]);
    }

    public function test_wallet_payout_reference_cannot_cross_owners(): void
    {
        $payout = PayoutRequest::factory()->create();
        $other = Retailer::factory()->create();
        $this->expectException(QueryException::class);
        WalletTransaction::factory()->for($other)->for($payout)->create(['type' => 'payout', 'amount_cents' => -100]);
    }

    public function test_wallet_sale_reference_cannot_cross_owners(): void
    {
        $line = SaleItem::factory()->create();
        $other = Retailer::factory()->create();
        $this->expectException(QueryException::class);
        WalletTransaction::factory()->for($other)->for($line, 'saleItem')->create(['type' => 'sale_credit']);
    }

    public function test_wallet_payout_currency_cannot_differ_from_request(): void
    {
        $payout = PayoutRequest::factory()->create();
        $this->expectException(QueryException::class);
        WalletTransaction::factory()->for($payout->retailer)->for($payout)->create(['type' => 'payout', 'currency' => 'USD', 'amount_cents' => -100]);
    }

    public function test_ledger_is_append_only_at_model_level(): void
    {
        $entry = WalletTransaction::factory()->create();
        $this->expectException(LogicException::class);
        $entry->update(['amount_cents' => 1]);
    }

    public function test_ledger_is_append_only_even_for_query_builder_updates(): void
    {
        $entry = WalletTransaction::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('wallet_transactions')->where('id', $entry->id)->update(['amount_cents' => 1]);
    }

    public function test_ledger_cannot_be_deleted_through_query_builder(): void
    {
        $entry = WalletTransaction::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('wallet_transactions')->where('id', $entry->id)->delete();
    }

    public function test_iban_and_integration_payload_are_encrypted_and_hidden(): void
    {
        $retailer = Retailer::factory()->create(['iban' => 'SYNTHETIC-TEST-IBAN']);
        $payout = PayoutRequest::factory()->for($retailer)->create(['iban' => 'SYNTHETIC-TEST-IBAN']);
        $event = IntegrationEvent::factory()->create(['payload' => ['order_id' => 'test-order']]);
        $this->assertSame('SYNTHETIC-TEST-IBAN', $retailer->fresh()->iban);
        $this->assertNotSame($retailer->iban, DB::table('retailers')->value('iban'));
        $this->assertNotSame($payout->iban, DB::table('payout_requests')->value('iban'));
        $this->assertArrayNotHasKey('iban', $retailer->toArray());
        $this->assertArrayNotHasKey('iban', $payout->toArray());
        $this->assertSame(['order_id' => 'test-order'], $event->fresh()->payload);
        $this->assertArrayNotHasKey('payload', $event->toArray());
        $this->assertStringNotContainsString('test-order', DB::table('integration_events')->value('payload'));
    }

    public function test_administrative_audit_records_actor_subject_action_and_redacted_state(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $retailer = Retailer::factory()->create();
        $audit = app(RecordAdministrativeAction::class)->handle($admin, AdministrativeAction::RetailerApproved, $retailer,
            ['status' => 'pending', 'iban' => 'SYNTHETIC-TEST-IBAN'],
            ['status' => 'approved', 'password' => 'excluded']);
        $this->assertTrue($audit->actor->is($admin));
        $this->assertTrue($audit->subject->is($retailer));
        $this->assertSame(AdministrativeAction::RetailerApproved, $audit->action);
        $this->assertSame(['status' => 'pending'], $audit->before_state);
        $this->assertSame(['status' => 'approved'], $audit->after_state);
    }

    public function test_non_admin_cannot_record_administrative_actions(): void
    {
        $retailer = Retailer::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(RecordAdministrativeAction::class)->handle($retailer->user, AdministrativeAction::RetailerApproved, $retailer);
    }

    public function test_audit_logs_cannot_be_deleted_through_query_builder(): void
    {
        $audit = AuditLog::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $audit->id)->delete();
    }

    public static function invalidRows(): array
    {
        return [
            'negative inventory price' => [InventoryItem::class, ['zero_price_cents' => -1]],
            'negative inventory quantity' => [InventoryItem::class, ['quantity_milliunits' => -1]],
            'incomplete external reference' => [InventoryItem::class, ['external_product_id' => 'test']],
            'invalid inventory status' => [InventoryItem::class, ['status' => 'invalid']],
            'invalid condition' => [InventoryItem::class, ['condition' => 'invalid']],
            'published without timestamp' => [InventoryItem::class, ['status' => 'published']],
            'invalid retailer status' => [Retailer::class, ['status' => 'invalid']],
            'approved without timestamp' => [Retailer::class, ['status' => 'approved']],
            'negative plan price' => [Plan::class, ['monthly_price_cents' => -1]],
            'invalid plan commission' => [Plan::class, ['commission_basis_points' => 10001]],
            'negative plan limit' => [Plan::class, ['max_items' => -1]],
            'negative sale total' => [Sale::class, ['total_cents' => -1]],
            'zero payout' => [PayoutRequest::class, ['amount_cents' => 0]],
            'invalid payout status' => [PayoutRequest::class, ['status' => 'approved']],
            'paid without timestamp' => [PayoutRequest::class, ['status' => 'paid']],
            'zero ledger movement' => [WalletTransaction::class, ['amount_cents' => 0]],
            'unreferenced credit' => [WalletTransaction::class, ['type' => 'sale_credit']],
            'unreferenced reservation' => [WalletTransaction::class, ['type' => 'payout_reservation', 'amount_cents' => -1]],
            'wrong reservation sign' => [WalletTransaction::class, ['type' => 'payout_reservation']],
            'invalid request status' => [AvailabilityRequest::class, ['status' => 'pending']],
            'invalid event status' => [IntegrationEvent::class, ['status' => 'invalid']],
            'processed without timestamp' => [IntegrationEvent::class, ['status' => 'processed']],
            'negative attempt count' => [IntegrationEvent::class, ['attempts' => -1]],
            'empty event id' => [IntegrationEvent::class, ['external_event_id' => '']],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_database_rejects_invalid_rows_even_when_eloquent_is_bypassed(string $model, array $invalid): void
    {
        $instance = $model::factory()->create();
        $this->expectException(QueryException::class);
        if ($instance instanceof WalletTransaction) {
            // A fresh insert tests sign/source constraints, independently of update immutability.
            $attributes = $instance->getAttributes();
            unset($attributes['id']);
            $attributes['idempotency_key'] = 'invalid-new-entry';
            DB::table($instance->getTable())->insert(array_replace($attributes, $invalid));
        } else {
            DB::table($instance->getTable())->where('id', $instance->id)->update($invalid);
        }
    }

    public function test_domain_policies_protect_ownership_and_private_integration_records(): void
    {
        $owner = Retailer::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $item = InventoryItem::factory()->for($owner)->create();
        $line = SaleItem::factory()->for($item)->create();
        $resources = [
            $owner,
            $item,
            InventoryImage::factory()->for($item)->create(),
            AvailabilityRequest::factory()->for($item)->create(),
            $line,
            Subscription::factory()->for($owner)->create(),
            PayoutRequest::factory()->for($owner)->create(),
            WalletTransaction::factory()->for($owner)->create(),
        ];
        foreach ($resources as $resource) {
            $this->assertTrue(Gate::forUser($owner->user)->allows('view', $resource));
            $this->assertFalse(Gate::forUser($other)->allows('view', $resource));
            $this->assertTrue(Gate::forUser($admin)->allows('view', $resource));
        }
        // A whole order can contain other retailers' lines. Only own SaleItems are exposed.
        foreach ([$line->sale, IntegrationEvent::factory()->create(), AuditLog::factory()->create()] as $private) {
            $this->assertFalse(Gate::forUser($owner->user)->allows('view', $private));
            $this->assertTrue(Gate::forUser($admin)->allows('view', $private));
        }
        $plan = Plan::factory()->create();
        $this->assertTrue(Gate::forUser($owner->user)->allows('view', $plan));
        $plan->update(['is_active' => false]);
        $this->assertFalse(Gate::forUser($owner->user)->allows('view', $plan));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $plan));
    }

    public function test_audit_redaction_drops_nested_payloads_and_float_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $retailer = Retailer::factory()->create();
        $audit = app(RecordAdministrativeAction::class)->handle($admin, AdministrativeAction::RetailerApproved, $retailer, [], [
            'status' => ['secret' => 'excluded'], 'amount_cents' => 1.5, 'iban' => 'excluded', 'currency' => 'EUR',
        ]);
        $this->assertSame(['currency' => 'EUR'], $audit->after_state);
    }

    public function test_invalid_wallet_sign_is_rejected_on_insert_with_a_valid_payout_reference(): void
    {
        $payout = PayoutRequest::factory()->create();
        $this->expectException(QueryException::class);
        WalletTransaction::factory()->for($payout->retailer)->for($payout)->create(['type' => 'payout', 'amount_cents' => 1]);
    }
}
