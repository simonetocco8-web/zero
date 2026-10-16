<?php

namespace App\Services;

use App\Models\Exchange;
use App\Models\LifecycleEvent;
use App\Models\Retailer;
use App\Models\Sale;
use App\Models\User;
use App\Models\VendorOrder;
use App\Notifications\LifecycleChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OperationalLifecycle
{
    public function transition(User $actor, VendorOrder|Exchange $subject, array $input): Model
    {
        $data = Validator::make($input, [
            'status' => ['required', 'in:preparing,shipped,ready_for_pickup,completed,cancelled'],
            'carrier' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tracking_code' => ['sometimes', 'nullable', 'string', 'max:191'],
        ])->validate();

        return DB::transaction(function () use ($actor, $subject, $data) {
            // Match financial lock order: retailer, parent sale, then vendor order.
            Retailer::whereKey($subject->retailer_id)->lockForUpdate()->firstOrFail();
            if ($subject instanceof VendorOrder) {
                Sale::whereKey($subject->sale_id)->lockForUpdate()->firstOrFail();
            }
            $subject = $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $subject);
            $from = $subject instanceof VendorOrder ? $subject->fulfillment_status : $subject->status;
            $next = $data['status'];
            $map = [
                'payment_pending' => ['cancelled'],
                'paid' => ['preparing', 'cancelled'],
                'confirmed' => ['preparing'],
                'preparing' => array_merge([$subject->delivery_method === 'pickup' ? 'ready_for_pickup' : 'shipped'], $subject instanceof VendorOrder ? ['cancelled'] : []),
                'shipped' => ['completed'], 'ready_for_pickup' => ['completed'],
            ];
            if (! in_array($next, $map[$from] ?? [], true)) {
                $this->invalid('Transizione non consentita.');
            }
            if ($next !== 'shipped' && (array_key_exists('carrier', $data) || array_key_exists('tracking_code', $data))) {
                $this->invalid('Tracking consentito solo alla spedizione.');
            }
            if ($next === 'cancelled' && $subject instanceof VendorOrder && $subject->sale->paid_at) {
                $lines = [];
                foreach ($subject->items()->lockForUpdate()->get() as $item) {
                    $remaining = $item->line_total_cents + (int) $item->walletTransactions()->where('type', 'refund')->lockForUpdate()->get()->sum('amount_cents');
                    if ($item->line_total_cents === 0) {
                        app(OrderStock::class)->restore($item, $subject);
                    }
                    if ($remaining > 0) {
                        $lines[] = ['external_line_id' => $item->external_line_id, 'amount_cents' => $remaining];
                    }
                }
                if ($lines) {
                    app(SaleAccounting::class)->refund(['provider' => $subject->sale->provider, 'external_order_id' => $subject->sale->external_order_id, 'external_refund_id' => 'cancel-vendor-'.$subject->id, 'timestamp' => now()->toIso8601String(), 'items' => $lines]);
                }
            }
            $changes = $data;
            if (in_array($next, ['shipped', 'ready_for_pickup', 'completed'])) {
                $changes[$next === 'shipped' ? 'shipped_at' : ($next === 'completed' ? 'completed_at' : 'ready_for_pickup_at')] = now();
            }
            if ($subject instanceof VendorOrder) {
                if (in_array($subject->status, ['refunded', 'cancelled'])) {
                    $this->invalid('Sottoordine chiuso.');
                }
                $changes['fulfillment_status'] = $next;
                if ($subject->status === 'partially_refunded' && $next !== 'cancelled') {
                    unset($changes['status']);
                }
            }
            $subject->update($changes);
            $this->record($subject, $from, $next, $actor);
            if ($subject instanceof VendorOrder) {
                $this->aggregate($subject->sale);
            }

            return $subject;
        }, 5);
    }

    /** Trusted payment adapter only: never exposed as a retailer state update. */
    public function confirmExchangePayment(Exchange $exchange, string $reference): Exchange
    {
        Validator::make(['reference' => $reference], ['reference' => ['required', 'string', 'max:191']])->validate();

        return DB::transaction(function () use ($exchange, $reference) {
            $exchange = Exchange::whereKey($exchange->id)->lockForUpdate()->firstOrFail();
            if ($exchange->payment_reference === $reference) {
                return $exchange;
            }
            if ($exchange->status !== 'payment_pending' || $exchange->payment_reference !== null) {
                $this->invalid('Pagamento già confermato o scambio chiuso.');
            }
            $exchange->update(['status' => 'confirmed', 'payment_reference' => $reference]);
            $this->record($exchange, 'payment_pending', 'confirmed');

            return $exchange;
        }, 5);
    }

    public function aggregate(Sale $sale): void
    {
        $orders = $sale->vendorOrders()->lockForUpdate()->get();
        if ($orders->isEmpty()) {
            return;
        }
        $states = $orders->pluck('status')->all();
        $states = array_values(array_diff($states, ['cancelled'])) ?: ['cancelled'];
        if (count(array_unique($states)) === 1) {
            $status = $states[0];
        } elseif (in_array('partially_refunded', $states) || in_array('refunded', $states)) {
            $status = 'partially_refunded';
        } else {
            $active = array_values(array_diff($states, ['cancelled']));
            // Earliest active stage: a shipped vendor cannot complete a preparing sibling.
            $status = 'completed';
            foreach (['payment_pending', 'paid', 'preparing', 'ready_for_pickup', 'shipped'] as $stage) {
                if (in_array($stage, $active)) {
                    $status = $stage;
                    break;
                }
            }
        }
        $sale->refresh()->update(['status' => $status]);
    }

    public function record(Model $subject, ?string $from, string $to, ?User $actor = null, array $metadata = []): void
    {
        $event = LifecycleEvent::create(['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id, 'actor_user_id' => $actor?->id, 'from_status' => $from, 'to_status' => $to, 'metadata' => $metadata]);
        $ids = [$subject->retailer_id];
        if ($subject instanceof Exchange) {
            $ids[] = $subject->counterparty_retailer_id;
        }
        foreach (Retailer::whereIn('id', array_unique($ids))->with('user')->get() as $retailer) {
            // Database notifications and audit commit atomically with the transition.
            $retailer->user->notify(new LifecycleChanged($event->id, $to));
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['status' => $message]);
    }
}
