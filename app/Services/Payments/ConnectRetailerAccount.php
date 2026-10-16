<?php

namespace App\Services\Payments;

use App\Models\Retailer;
use App\Services\MarketplaceEconomicRules;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class ConnectRetailerAccount
{
    private function client(): StripeClient
    {
        app(MarketplaceEconomicRules::class)->stripeReady();

        return new StripeClient(['api_key' => config('marketplace.stripe.secret'), 'max_network_retries' => 2]);
    }

    public function begin(Retailer $retailer): string
    {
        $client = $this->client();
        $stored = DB::table('marketplace_connected_accounts')->where('retailer_id', $retailer->id)->first();
        if (! $stored) {
            $account = $client->accounts->create(['type' => 'express', 'country' => config('marketplace.stripe.connected_account_country'), 'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]], 'metadata' => ['marketplace_retailer_id' => (string) $retailer->id]], ['idempotency_key' => 'marketplace-retailer:'.$retailer->id]);
            if (! is_string($account->id) || ! str_starts_with($account->id, 'acct_')) {
                throw new \RuntimeException('Invalid connected account.');
            }
            DB::transaction(function () use ($retailer, $account) {
                Retailer::lockForUpdate()->findOrFail($retailer->id);
                $stored = DB::table('marketplace_connected_accounts')->where('retailer_id', $retailer->id)->first();
                if ($stored && $stored->stripe_account_id !== $account->id) {
                    throw new \LogicException('Connected account idempotency mismatch.');
                }
                if (! $stored) {
                    DB::table('marketplace_connected_accounts')->insert(['retailer_id' => $retailer->id, 'stripe_account_id' => $account->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }, 5);
            $stored = DB::table('marketplace_connected_accounts')->where('retailer_id', $retailer->id)->first();
        }
        $link = $client->accountLinks->create(['account' => $stored->stripe_account_id, 'refresh_url' => route('retailer.connect.show'), 'return_url' => route('retailer.connect.refresh'), 'type' => 'account_onboarding']);
        if (! is_string($link->url) || ! str_starts_with($link->url, 'https://connect.stripe.com/')) {
            throw new \RuntimeException('Invalid onboarding URL.');
        }

        return $link->url;
    }

    public function refresh(Retailer $retailer): void
    {
        $stored = DB::table('marketplace_connected_accounts')->where('retailer_id', $retailer->id)->first();
        if (! $stored) {
            return;
        }
        $account = $this->client()->accounts->retrieve($stored->stripe_account_id);
        if ($account->id !== $stored->stripe_account_id || ($account->metadata->marketplace_retailer_id ?? null) !== (string) $retailer->id) {
            throw new \LogicException('Connected account ownership mismatch.');
        }
        DB::table('marketplace_connected_accounts')->where('id', $stored->id)->update(['charges_enabled' => (bool) $account->charges_enabled, 'payouts_enabled' => (bool) $account->payouts_enabled, 'details_submitted' => (bool) $account->details_submitted, 'transfers_active' => ($account->capabilities->transfers ?? null) === 'active', 'verified_at' => now(), 'updated_at' => now()]);
    }
}
