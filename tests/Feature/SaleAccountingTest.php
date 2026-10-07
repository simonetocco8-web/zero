<?php

namespace Tests\Feature;

use App\Actions\RequestPayout;
use App\Actions\ReviewPayout;
use App\Models\IntegrationEvent;
use App\Models\InventoryItem;
use App\Models\PayoutRequest;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SaleAccounting;
use App\Services\WalletBalance;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Retailer $retailer;

    private InventoryItem $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(PlanSeeder::class);
        $this->retailer = Retailer::factory()->approved()->create(['iban' => 'IT60X0542811101000000123456']);
        Subscription::factory()->active()->create(['retailer_id' => $this->retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);
        $this->product = InventoryItem::factory()->create(['retailer_id' => $this->retailer->id]);
    }

    private function sale(array $changes = []): array
    {
        return array_replace_recursive(['provider' => 'fake', 'external_order_id' => 'order-1', 'external_event_id' => 'paid-1', 'timestamp' => now()->toIso8601String(), 'items' => [['external_line_id' => 'line-1', 'inventory_item_id' => $this->product->id, 'retailer_id' => $this->retailer->id, 'quantity' => '2', 'unit_price_cents' => 10000, 'discount_cents' => 1000]]], $changes);
    }

    private function refund(int $amount = 19000, string $id = 'refund-1'): array
    {
        return ['provider' => 'fake', 'external_order_id' => 'order-1', 'external_refund_id' => $id, 'external_event_id' => $id, 'timestamp' => now()->toIso8601String(), 'items' => [['external_line_id' => 'line-1', 'amount_cents' => $amount]]];
    }

    private function balance(): int
    {
        return app(WalletBalance::class)->availableCents($this->retailer);
    }

    private function invalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_free_sale_records_gross_discount_commission_snapshot_and_net(): void
    {
        $sale = app(SaleAccounting::class)->recordSale($this->sale());
        $line = $sale->items->sole();
        $this->assertSame(19000, $sale->total_cents);
        $this->assertSame(1000, $line->discount_cents);
        $this->assertSame(200, $line->commission_basis_points);
        $this->assertSame(380, $line->commission_cents);
        $this->assertSame(18620, $line->net_cents);
        $this->assertSame(18620, $this->balance());
    }

    public function test_pro_sale_and_plan_change_do_not_reprice_past_sales(): void
    {
        $first = app(SaleAccounting::class)->recordSale($this->sale());
        $this->retailer->activeSubscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        Subscription::factory()->active()->create(['retailer_id' => $this->retailer->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id, 'starts_at' => now()]);
        $second = app(SaleAccounting::class)->recordSale($this->sale(['external_order_id' => 'order-2', 'external_event_id' => 'paid-2']));
        $this->assertSame(95, $second->items->sole()->commission_cents);
        $this->assertSame(50, $second->items->sole()->commission_basis_points);
        $replay = app(SaleAccounting::class)->recordSale($this->sale());
        $this->assertSame($first->id, $replay->id);
        $this->assertSame(380, $first->items->sole()->fresh()->commission_cents);
        $this->assertSame(37525, $this->balance());
    }

    public function test_duplicate_event_and_order_alias_have_no_duplicate_credit(): void
    {
        $payload = $this->sale();
        $service = app(SaleAccounting::class);
        $sale = $service->recordSale($payload);
        $this->assertSame($sale->id, $service->recordSale($payload)->id);
        $payload['external_event_id'] = 'alias';
        $service->recordSale($payload);
        $this->assertSame(1, Sale::count());
        $this->assertSame(2, WalletTransaction::count());
        $this->assertSame(18620, $this->balance());
        $payload['items'][0]['unit_price_cents'] = 20000;
        $this->invalid(fn () => $service->recordSale($payload));
    }

    public function test_partial_refunds_restore_commission_cumulatively_and_are_idempotent(): void
    {
        $service = app(SaleAccounting::class);
        $service->recordSale($this->sale());
        $payload = $this->refund(6333);
        $service->refund($payload);
        $count = WalletTransaction::count();
        $service->refund($payload);
        $payload['external_event_id'] = 'refund-alias';
        $service->refund($payload);
        $this->assertSame($count, WalletTransaction::count());
        $this->assertSame('partially_refunded', Sale::sole()->status->value);
        $service->refund($this->refund(6333, 'refund-2'));
        $service->refund($this->refund(6334, 'refund-3'));
        $this->assertSame(0, $this->balance());
        $this->assertSame('refunded', Sale::sole()->status->value);
        $this->assertSame(380, (int) WalletTransaction::where('type', 'adjustment')->sum('amount_cents'));
        $this->assertSame(18620, Sale::sole()->items()->sole()->net_cents);
    }

    public function test_refund_over_remaining_gross_rolls_back_and_event_collision_is_rejected(): void
    {
        $service = app(SaleAccounting::class);
        $service->recordSale($this->sale());
        $count = IntegrationEvent::count();
        $this->invalid(fn () => $service->refund($this->refund(19001)));
        $this->assertSame($count, IntegrationEvent::count());
        $this->assertSame(18620, $this->balance());
        $service->refund($this->refund(100));
        $this->invalid(fn () => $service->refund($this->refund(101)));
    }

    public function test_no_floats_bad_ownership_or_excess_discount_are_accepted(): void
    {
        $service = app(SaleAccounting::class);
        foreach ([['unit_price_cents' => 1.5], ['quantity' => 1.5], ['discount_cents' => 20001], ['quantity' => '0'], ['retailer_id' => Retailer::factory()->approved()->create()->id]] as $line) {
            $this->invalid(fn () => $service->recordSale($this->sale(['items' => [$line]])));
        }
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_rounding_fractional_quantity_and_small_commission(): void
    {
        $sale = app(SaleAccounting::class)->recordSale($this->sale(['items' => [['quantity' => '0.500', 'unit_price_cents' => 51, 'discount_cents' => 0]]]));
        $this->assertSame(26, $sale->total_cents);
        $this->assertSame(1, $sale->items->sole()->commission_cents);
        $this->assertSame(25, $this->balance());
    }

    public function test_credit_maturation_prevents_early_payout_and_refund_matures_with_sale(): void
    {
        config(['finance.credit_maturation_days' => 7]);
        $service = app(SaleAccounting::class);
        $service->recordSale($this->sale());
        $service->refund($this->refund(10000));
        $this->assertSame(0, $this->balance());
        $this->invalid(fn () => app(RequestPayout::class)->handle($this->retailer->user));
        $this->travel(8)->days();
        $this->assertSame(8820, $this->balance());
    }

    public function test_payout_reserves_all_credit_duplicate_is_rejected_and_rejection_releases_it(): void
    {
        app(SaleAccounting::class)->recordSale($this->sale());
        $action = app(RequestPayout::class);
        $payout = $action->handle($this->retailer->user);
        $this->assertSame(18620, $payout->amount_cents);
        $this->assertSame(0, $this->balance());
        $this->invalid(fn () => $action->handle($this->retailer->user));
        $admin = User::factory()->create(['role' => 'admin']);
        app(ReviewPayout::class)->handle($admin, $payout, 'rejected', 'Dati da verificare', null);
        $this->assertSame(18620, $this->balance());
        $this->assertSame(0, app(WalletBalance::class)->reservedCents($this->retailer));
        $this->invalid(fn () => app(ReviewPayout::class)->handle($admin, $payout, 'paid', null, 'repeat'));
        $this->assertSame(18620, $action->handle($this->retailer->user)->amount_cents);
    }

    public function test_paid_payout_does_not_double_debit_and_refund_after_payment_records_debt(): void
    {
        $service = app(SaleAccounting::class);
        $service->recordSale($this->sale());
        $payout = app(RequestPayout::class)->handle($this->retailer->user);
        app(ReviewPayout::class)->handle(User::factory()->create(['role' => 'admin']), $payout, 'paid', null, 'bank-1');
        $this->assertSame(0, $this->balance());
        $this->assertSame(18620, app(WalletBalance::class)->netEarnedCents($this->retailer));
        $service->refund($this->refund());
        $this->assertSame(-18620, $this->balance());
        $this->invalid(fn () => app(RequestPayout::class)->handle($this->retailer->user));
    }

    public function test_refund_while_reserved_blocks_paid_and_rejection_releases_remaining_credit(): void
    {
        $service = app(SaleAccounting::class);
        $service->recordSale($this->sale());
        $payout = app(RequestPayout::class)->handle($this->retailer->user);
        $service->refund($this->refund(10000));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->invalid(fn () => app(ReviewPayout::class)->handle($admin, $payout, 'paid', null, 'bank'));
        app(ReviewPayout::class)->handle($admin, $payout, 'rejected', 'Rimborso', null);
        $this->assertSame(8820, $this->balance());
    }

    public function test_payout_requires_valid_iban_and_positive_balance(): void
    {
        $this->invalid(fn () => app(RequestPayout::class)->handle($this->retailer->user));
        app(SaleAccounting::class)->recordSale($this->sale());
        $this->retailer->update(['iban' => 'IT00INVALID']);
        $this->invalid(fn () => app(RequestPayout::class)->handle($this->retailer->user->fresh()));
        $this->assertDatabaseCount('payout_requests', 0);
    }

    public function test_credit_page_ownership_and_http_input_cannot_choose_owner_or_amount(): void
    {
        app(SaleAccounting::class)->recordSale($this->sale());
        $other = Retailer::factory()->approved()->create();
        $foreign = InventoryItem::factory()->create(['retailer_id' => $other->id, 'name' => 'Segreto finanziario']);
        $this->actingAs($this->retailer->user)->get(route('retailer.credit'))->assertOk()->assertSee($this->product->name)->assertDontSee('Segreto finanziario')->assertDontSee($this->retailer->iban);
        $this->post(route('retailer.credit.payout'), ['retailer_id' => $other->id, 'amount_cents' => 1])->assertSessionHasErrors(['retailer_id', 'amount_cents']);
        $this->post(route('retailer.credit.payout'))->assertRedirect(route('retailer.credit'));
        $this->assertSame($this->retailer->id, PayoutRequest::sole()->retailer_id);
        $this->actingAs($other->user)->get(route('retailer.credit'))->assertOk()->assertDontSee($this->product->name);
    }

    public function test_pending_retailer_and_retailer_review_are_forbidden(): void
    {
        $pending = Retailer::factory()->create();
        $this->actingAs($pending->user)->get(route('retailer.credit'))->assertForbidden();
        $this->post(route('retailer.credit.payout'))->assertForbidden();
        app(SaleAccounting::class)->recordSale($this->sale());
        $payout = app(RequestPayout::class)->handle($this->retailer->user);
        $this->actingAs($this->retailer->user)->patch(route('admin.payouts.review', $payout), ['decision' => 'paid', 'payment_reference' => 'bad'])->assertForbidden();
    }

    public function test_multi_retailer_order_keeps_ledgers_and_refunds_separate(): void
    {
        $other = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $other->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id, 'starts_at' => now()->subDay()]);
        $product = InventoryItem::factory()->create(['retailer_id' => $other->id]);
        $payload = $this->sale();
        $payload['items'][] = ['external_line_id' => 'line-2', 'inventory_item_id' => $product->id, 'retailer_id' => $other->id, 'quantity' => '1', 'unit_price_cents' => 10000];
        app(SaleAccounting::class)->recordSale($payload);
        $this->assertSame(18620, $this->balance());
        $this->assertSame(9950, app(WalletBalance::class)->availableCents($other));
        app(SaleAccounting::class)->refund($this->refund());
        $this->assertSame(0, $this->balance());
        $this->assertSame(9950, app(WalletBalance::class)->availableCents($other));
        $this->assertSame('partially_refunded', Sale::sole()->status->value);
    }

    public function test_database_rejects_duplicate_pending_payout_and_incoherent_net(): void
    {
        app(SaleAccounting::class)->recordSale($this->sale());
        app(RequestPayout::class)->handle($this->retailer->user);
        foreach ([fn () => PayoutRequest::factory()->create(['retailer_id' => $this->retailer->id]), fn () => DB::table('sale_items')->update(['net_cents' => 1])] as $operation) {
            try {
                $operation();
                $this->fail('Expected database constraint violation.');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertDatabaseCount('payout_requests', 1);
        $this->assertSame(18620, Sale::sole()->items()->sole()->net_cents);
    }
}
