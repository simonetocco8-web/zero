<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceCommission;
use App\Services\MarketplaceEconomicRules;
use App\Services\MarketplaceLedger;
use App\Services\MarketplaceQuote;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\StartMarketplacePayment;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplaceEconomicRulesTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Missing/unsupported economic rule accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('economic_rules', $exception->errors());
        }
    }

    public function test_approved_pdf_defaults_do_not_activate_payments(): void
    {
        $production = require base_path('config/marketplace.php');
        $this->assertSame('disabled', $production['economic_rules']['execution_mode']);
        $this->assertSame('stripe_connect_separate_charges_transfers', $production['economic_rules']['funds_flow']);
        $this->assertSame(50, $production['economic_rules']['exchange_commission_basis_points']);
        $this->assertSame(30, $production['reservation_minutes']);
        $this->assertSame(1440, $production['exchange_difference_payment_minutes']);
        config(['marketplace' => $production]);
        $this->assertSame(200, app(MarketplaceCommission::class)->saleRate('free'));
        $this->assertSame(50, app(MarketplaceCommission::class)->saleRate('pro'));
        $this->rejects(fn () => app(MarketplaceEconomicRules::class)->sale());
        $this->rejects(fn () => app(MarketplaceCommission::class)->difference(30000, 1));
    }

    private function withNumericEnvironment(array $values, callable $check): void
    {
        $original = [];
        foreach ($values as $key => $value) {
            $original[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        try {
            $check();
        } finally {
            foreach ($original as $key => [$process, $environment, $server]) {
                $process === false ? putenv($key) : putenv($key.'='.$process);
                if ($environment === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $environment;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }

    public function test_numeric_environment_strings_produce_integer_configuration(): void
    {
        $this->withNumericEnvironment(['MARKETPLACE_EXCHANGE_COMMISSION_BASIS_POINTS' => '75', 'MARKETPLACE_RESERVATION_MINUTES' => '45', 'EXCHANGE_DIFFERENCE_PAYMENT_MINUTES' => '720', 'MARKETPLACE_EXCHANGE_PROPOSAL_HOURS' => '24', 'MARKETPLACE_COMMISSION_VAT_BASIS_POINTS' => '2200'], function () {
            $config = require base_path('config/marketplace.php');
            $this->assertSame(75, $config['economic_rules']['exchange_commission_basis_points']);
            $this->assertSame(45, $config['reservation_minutes']);
            $this->assertSame(720, $config['exchange_difference_payment_minutes']);
            $this->assertSame(24, $config['exchange_proposal_hours']);
            $this->assertSame(2200, $config['commission_tax']['rate_basis_points']);
        });
    }

    public function test_optional_commission_vat_preserves_unconfigured_and_explicit_zero(): void
    {
        foreach ([null, '', 'null', '0'] as $value) {
            $this->withNumericEnvironment(['MARKETPLACE_COMMISSION_VAT_BASIS_POINTS' => $value], function () use ($value) {
                $config = require base_path('config/marketplace.php');
                $this->assertSame($value === '0' ? 0 : null, $config['commission_tax']['rate_basis_points']);
            });
        }
    }

    public function test_invalid_numeric_environment_cannot_silently_become_zero(): void
    {
        foreach (['MARKETPLACE_EXCHANGE_COMMISSION_BASIS_POINTS', 'MARKETPLACE_RESERVATION_MINUTES', 'EXCHANGE_DIFFERENCE_PAYMENT_MINUTES', 'MARKETPLACE_EXCHANGE_PROPOSAL_HOURS', 'MARKETPLACE_COMMISSION_VAT_BASIS_POINTS'] as $key) {
            foreach (['invalid', '0.5', '1e2', '99999999999999999999999', 'false'] as $value) {
                $this->withNumericEnvironment([$key => $value], function () use ($key) {
                    try {
                        require base_path('config/marketplace.php');
                        $this->fail('Malformed economic configuration accepted.');
                    } catch (\InvalidArgumentException $e) {
                        $this->assertSame('Invalid integer configuration: '.$key, $e->getMessage());
                    }
                });
            }
        }
    }

    public function test_every_indispensable_rule_is_required_even_with_fake_gateway(): void
    {
        $fixture = require base_path('tests/Fixtures/marketplace-rules.php');
        foreach (['funds_flow', 'sale_commission_base', 'vat_rules', 'invoicing_rules', 'shipping_rules', 'cancellation_rules', 'refund_rules', 'execution_mode'] as $key) {
            config($fixture);
            config(['marketplace.economic_rules.'.$key => null]);
            $this->rejects(fn () => app(MarketplaceEconomicRules::class)->sale());
        }
        foreach (['exchange_commission_basis_points', 'exchange_commission_base', 'exchange_commission_charged_to'] as $key) {
            config($fixture);
            config(['marketplace.economic_rules.'.$key => null]);
            $this->rejects(fn () => app(MarketplaceEconomicRules::class)->exchange());
        }
        foreach (['marketplace.reservation_minutes', 'marketplace.exchange_difference_payment_minutes'] as $key) {
            config($fixture);
            config([$key => null]);
            $this->rejects(fn () => app(MarketplaceEconomicRules::class)->exchange());
        }
    }

    public function test_arbitrary_profile_real_funds_flow_and_invalid_commission_cannot_enable_payments(): void
    {
        $fixture = require base_path('tests/Fixtures/marketplace-rules.php');
        foreach (['platform_collects_then_pays_retailer', 'direct_payment_to_retailer', 'stripe_connect', 'anything'] as $flow) {
            config($fixture);
            config(['marketplace.economic_rules.funds_flow' => $flow]);
            $this->rejects(fn () => app(MarketplaceEconomicRules::class)->sale());
        }
        foreach ([null, false, -1, 10001, '0.5', '1e2'] as $rate) {
            config($fixture);
            config(['marketplace.economic_rules.exchange_commission_basis_points' => $rate]);
            $this->rejects(fn () => app(MarketplaceEconomicRules::class)->exchange());
        }
    }

    public function test_explicit_zero_exchange_commission_is_valid_but_is_not_a_missing_rule_fallback(): void
    {
        config(['marketplace.economic_rules.exchange_commission_basis_points' => 0]);
        $this->assertSame(0, app(MarketplaceCommission::class)->difference(30000, 7)['amount_cents']);
        config(['marketplace.economic_rules.exchange_commission_basis_points' => 100]);
        $this->assertSame(300, app(MarketplaceCommission::class)->difference(30000, 7)['amount_cents']);
    }

    public function test_missing_rules_leave_cart_intact_and_create_no_order_reservation_payment_or_ledger(): void
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);
        $p = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '1', 'pickup_available' => true, 'vat_treatment' => 'not_applicable']);
        $u = User::factory()->create(['role' => 'customer']);
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $quotes = app(MarketplaceQuote::class);
        $hash = $quotes->hash($quotes->build($u, []));
        config(['marketplace.economic_rules.vat_rules' => null]);
        $this->rejects(fn () => app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $hash));
        foreach (['orders', 'vendor_orders', 'marketplace_payments', 'stock_reservations', 'wallet_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
        $this->actingAs($u)->post(route('checkout.review'), ['modes' => [$r->id => 'pickup']])->assertSessionHasErrors('economic_rules');
        $this->get(route('cart.index'))->assertOk();
    }

    public function test_payment_cannot_start_or_consume_existing_stock_when_rules_are_removed_but_expiry_still_runs(): void
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);
        $p = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '1', 'pickup_available' => true, 'vat_treatment' => 'not_applicable']);
        $u = User::factory()->create(['role' => 'customer']);
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $q = app(MarketplaceQuote::class);
        $o = app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $q->hash($q->build($u, [])));
        config(['marketplace.economic_rules.funds_flow' => null]);
        $this->rejects(fn () => app(StartMarketplacePayment::class)->handle($o));
        $this->assertNull($o->payment->fresh()->reference);
        config(require base_path('tests/Fixtures/marketplace-rules.php'));
        app(StartMarketplacePayment::class)->handle($o);
        config(['marketplace.economic_rules.refund_rules' => null]);
        $this->rejects(fn () => app(ApplyMarketplacePaymentResult::class)->handle($o, 'blocked-rules', $o->payment->fresh()->reference, 'paid', $o->total_cents, 'EUR'));
        $this->assertSame('payment_pending', $o->fresh()->status->value);
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
        $this->assertDatabaseCount('marketplace_payment_events', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-reservations')->assertSuccessful();
        $this->assertSame('cancelled', $o->fresh()->status->value);
        $this->assertSame(1000, $p->fresh()->available_quantity_milliunits);
    }

    public function test_simulation_configuration_cannot_create_withdrawable_marketplace_credit(): void
    {
        $r = Retailer::factory()->approved()->create();
        $this->rejects(fn () => app(MarketplaceLedger::class)->append($r, 'unsafe-real-credit', 'pending_credit', 'available', 100, [], false));
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
