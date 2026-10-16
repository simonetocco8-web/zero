<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Separate platform-funded promotional ledger; never a retailer wallet or monetary top-up. */
class CustomerPromotionalBonus
{
    public function grant(User $admin, User $customer, int $cents, string $campaign, string $key): int
    {
        abort_unless($admin->role->value === 'admin' && $customer->role->value === 'customer', 403);
        if ($cents <= 0 || trim($campaign) === '' || $key === '') {
            throw new \InvalidArgumentException('Invalid promotional campaign grant.');
        }

        return DB::transaction(function () use ($admin, $customer, $cents, $campaign, $key) {
            User::lockForUpdate()->findOrFail($customer->id);
            $existing = DB::table('customer_bonus_grants')->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->user_id != $customer->id || $existing->amount_cents != $cents || $existing->campaign !== $campaign || $existing->granted_by != $admin->id) {
                    throw new \LogicException('Bonus grant key reused.');
                }

                return $existing->id;
            }
            $id = DB::table('customer_bonus_grants')->insertGetId(['user_id' => $customer->id, 'granted_by' => $admin->id, 'campaign' => $campaign, 'idempotency_key' => $key, 'amount_cents' => $cents, 'currency' => 'EUR', 'expires_at' => now()->addMonthsNoOverflow(config('marketplace.bonus.validity_months')), 'created_at' => now(), 'updated_at' => now()]);
            $this->entry($id, $customer->id, null, 'grant:'.$id, 'grant', 'available', $cents);

            return $id;
        }, 5);
    }

    public function available(User $user): int
    {
        if ($user->role->value !== 'customer') {
            return 0;
        }

        return (int) DB::table('customer_bonus_entries as e')->join('customer_bonus_grants as g', 'g.id', '=', 'e.grant_id')->where('e.user_id', $user->id)->where('e.bucket', 'available')->where('g.expires_at', '>', now())->sum('e.amount_cents');
    }

    /** Caller holds the order's stock/retailer locks; lock its customer after those locks. */
    public function reserve(Order $order, bool $requested): int
    {
        $this->requireTransaction();
        $user = User::lockForUpdate()->findOrFail($order->user_id);
        if (! $requested) {
            return 0;
        }
        abort_unless($user->role->value === 'customer', 403);
        if (! app(MarketplaceEconomicRules::class)->isStripe() && ! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages(['bonus' => 'Il bonus è utilizzabile soltanto nel pagamento marketplace abilitato.']);
        }
        $goods = $order->total_cents - $order->shipping_cents;
        $limit = BigInteger::of($goods)->multipliedBy(config('marketplace.bonus.maximum_goods_basis_points'))->quotient(10000)->toInt();
        $amount = min($limit, $this->available($user));
        $remaining = $amount;
        foreach (DB::table('customer_bonus_grants')->where('user_id', $user->id)->where('expires_at', '>', now())->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get() as $grant) {
            $balance = (int) DB::table('customer_bonus_entries')->where('grant_id', $grant->id)->where('bucket', 'available')->sum('amount_cents');
            $take = min($remaining, max(0, $balance));
            if ($take > 0) {
                $key = 'order:'.$order->id.':grant:'.$grant->id;
                $this->entry($grant->id, $user->id, $order->id, $key.':reserve-out', 'purchase_reservation', 'available', -$take);
                $this->entry($grant->id, $user->id, $order->id, $key.':reserve-in', 'purchase_reservation', 'reserved', $take);
                $remaining -= $take;
            }
        }
        if ($remaining !== 0) {
            throw new \LogicException('Promotional bonus changed under lock.');
        }

        return $amount;
    }

    public function active(Order $order): bool
    {
        $expected = (int) $order->payment()->value('bonus_cents');
        $reserved = (int) DB::table('customer_bonus_entries as e')->join('customer_bonus_grants as g', 'g.id', '=', 'e.grant_id')->where('e.order_id', $order->id)->where('e.bucket', 'reserved')->where('g.expires_at', '>', now())->sum('e.amount_cents');

        return $reserved === $expected;
    }

    public function finish(Order $order, bool $success): void
    {
        $this->requireTransaction();
        User::lockForUpdate()->findOrFail($order->user_id);
        $kind = $success ? 'purchase_spend' : 'purchase_release';
        $opposite = $success ? 'purchase_release' : 'purchase_spend';
        if (DB::table('customer_bonus_entries')->where('order_id', $order->id)->where('kind', $opposite)->exists()) {
            // A late success must go through reconciliation, never reconsume released bonus.
            if ($success) {
                throw new \LogicException('Promotional reservation already released.');
            }

            return;
        }
        foreach (DB::table('customer_bonus_entries')->where('order_id', $order->id)->where('kind', 'purchase_reservation')->where('bucket', 'reserved')->orderBy('id')->get() as $entry) {
            $key = 'bonus:'.$entry->id.':'.$kind;
            $this->entry($entry->grant_id, $order->user_id, $order->id, $key.':out', $kind, 'reserved', -$entry->amount_cents);
            $this->entry($entry->grant_id, $order->user_id, $order->id, $key.':in', $kind, $success ? 'spent' : 'available', $entry->amount_cents);
        }
    }

    /** Restore original lots, retaining their original validity; expired credit is recorded separately. */
    public function refund(Order $order, int $cents, string $refundKey): void
    {
        $this->requireTransaction();
        User::lockForUpdate()->findOrFail($order->user_id);
        $prefix = 'refund:'.hash('sha256', $refundKey).':';
        $prior = DB::table('customer_bonus_entries')->where('order_id', $order->id)->where('kind', 'refund_credit')->where('bucket', 'available')->where('idempotency_key', 'like', $prefix.'%')->sum('amount_cents');
        if ($prior > 0) {
            if ((int) $prior !== $cents) {
                throw new \LogicException('Bonus refund amount changed.');
            }

            return;
        }
        $left = $cents;
        foreach (DB::table('customer_bonus_grants')->where('user_id', $order->user_id)->orderBy('id')->get() as $grant) {
            $spent = (int) DB::table('customer_bonus_entries')->where('grant_id', $grant->id)->where('order_id', $order->id)->where('bucket', 'spent')->sum('amount_cents');
            $take = min($left, max(0, $spent));
            if ($take > 0) {
                $this->entry($grant->id, $order->user_id, $order->id, $prefix.$grant->id.':out', 'refund_credit', 'spent', -$take);
                $this->entry($grant->id, $order->user_id, $order->id, $prefix.$grant->id.':in', 'refund_credit', 'available', $take);
                $left -= $take;
            }
        }
        if ($left !== 0) {
            throw ValidationException::withMessages(['bonus' => 'Rimborso superiore al bonus utilizzato.']);
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Bonus mutations require a locked database transaction.');
        }
    }

    private function entry(int $grant, int $user, ?int $order, string $key, string $kind, string $bucket, int $amount): void
    {
        $data = ['grant_id' => $grant, 'user_id' => $user, 'order_id' => $order, 'idempotency_key' => $key, 'kind' => $kind, 'bucket' => $bucket, 'amount_cents' => $amount];
        $existing = DB::table('customer_bonus_entries')->where('idempotency_key', $key)->first();
        if ($existing) {
            foreach ($data as $field => $value) {
                if ($value != $existing->$field) {
                    throw new \LogicException('Bonus entry key reused.');
                }
            }

            return;
        }
        DB::table('customer_bonus_entries')->insert($data + ['created_at' => now()]);
    }
}
