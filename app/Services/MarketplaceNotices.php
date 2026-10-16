<?php

namespace App\Services;

use App\Enums\MarketplaceNoticeKind as Kind;
use App\Jobs\DeliverMarketplaceNotice;
use App\Models\Exchange;
use App\Models\MarketplaceNoticeOutbox;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\User;
use App\Models\VendorOrder;
use App\Notifications\MarketplaceNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class MarketplaceNotices
{
    public function publish(int $userId, string $key, Kind $kind, string $path, int $resourceId): MarketplaceNoticeOutbox
    {
        return DB::transaction(function () use ($userId, $key, $kind, $path, $resourceId) {
            $existing = MarketplaceNoticeOutbox::where('user_id', $userId)->where('event_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $user = User::findOrFail($userId);
            $channels = config('marketplace.notifications.channels');
            $data = ['kind' => $kind->value, 'title' => $kind->title(), 'message' => 'Aggiornamento relativo alla pratica #'.$resourceId.'. Accedi al tuo account per i dettagli.', 'path' => $path, 'resource_id' => $resourceId];
            $n = MarketplaceNoticeOutbox::create(['notification_uuid' => (string) Str::uuid(), 'user_id' => $userId, 'event_key' => $key, 'kind' => $kind->value, 'data' => $data, 'database_enabled' => in_array('database', $channels, true), 'email_enabled' => in_array('mail', $channels, true) && $user->marketplace_email_notifications]);
            if ($n->database_enabled) {
                Notification::sendNow($user, new MarketplaceNotice($data, $n->notification_uuid), ['database']);
                $n->update(['database_delivered_at' => now()]);
            }
            if ($n->email_enabled) {
                $n->update(['last_enqueued_at' => now()]);
                if (config('marketplace.notifications.queue_connection') === 'sync') {
                    throw new \LogicException('Marketplace emails require an asynchronous queue connection.');
                }
                DeliverMarketplaceNotice::dispatch($n->id)->onConnection(config('marketplace.notifications.queue_connection'))->onQueue(config('marketplace.notifications.queue'))->afterCommit();
            } else {
                $n->update(['completed_at' => now()]);
            }

            return $n;
        }, 5);
    }

    public function orderReceived(Order $order): void
    {
        foreach ($order->vendorOrders()->get() as $vendor) {
            $this->publish(Retailer::findOrFail($vendor->retailer_id)->user_id, 'vendor:'.$vendor->id.':received', Kind::OrderReceived, route('retailer.sales.show', $vendor, false), $vendor->id);
        }
    }

    public function orderEvent(Order $order, Kind $kind): void
    {
        $this->publish($order->user_id, 'order:'.$order->id.':'.$kind->value, $kind, route('orders.show', $order, false), $order->id);
        foreach ($order->vendorOrders()->get() as $vendor) {
            $this->publish(Retailer::findOrFail($vendor->retailer_id)->user_id, 'vendor:'.$vendor->id.':'.$kind->value, $kind, route('retailer.sales.show', $vendor, false), $vendor->id);
        }
    }

    public function vendorEvent(VendorOrder $vendor, Kind $kind): void
    {
        $this->publish($vendor->order->user_id, 'vendor:'.$vendor->id.':buyer:'.$kind->value, $kind, route('customer.deliveries.show', $vendor, false), $vendor->id);
        $this->publish(Retailer::findOrFail($vendor->retailer_id)->user_id, 'vendor:'.$vendor->id.':seller:'.$kind->value, $kind, route('retailer.sales.show', $vendor, false), $vendor->id);
    }

    public function exchangeEvent(Exchange $e, string $action, ?User $actor): void
    {
        if (! in_array($action, ['proposed', 'countered', 'accepted', 'rejected', 'expired', 'awaiting_difference_payment', 'difference_paid', 'cancelled', 'difference_failed', 'difference_cancelled', 'eligibility_lost', 'completed'], true)) {
            return;
        }
        $e->loadMissing('retailerA', 'retailerB');
        $v = $e->currentVersion()->firstOrFail();
        $a = $e->retailerA->user_id;
        $b = $e->retailerB->user_id;
        [$kind,$recipients] = match ($action) {
            'proposed' => [Kind::ExchangeProposed, [$b]],'countered' => [Kind::ExchangeCountered, [$actor?->id === $a ? $b : $a]],
            'accepted' => [Kind::ExchangeAccepted, [$a, $b]],'rejected' => [Kind::ExchangeRejected, [$a, $b]],'expired' => [Kind::ExchangeExpired, [$a, $b]],
            'awaiting_difference_payment' => [Kind::DifferenceDue, [Retailer::findOrFail($v->payer_retailer_id)->user_id]],
            'difference_paid' => [Kind::DifferenceReceived, [Retailer::findOrFail($v->payee_retailer_id)->user_id]],
            'completed' => [Kind::DeliveryCompleted, [$a, $b]],
            'cancelled','difference_failed','difference_cancelled','eligibility_lost' => [Kind::Cancelled, [$a, $b]],default => [null, []],
        };
        if (! $kind) {
            return;
        }
        if ($action === 'difference_paid') {
            $this->publish(Retailer::findOrFail($v->payer_retailer_id)->user_id, 'exchange:'.$e->id.':v:'.$v->id.':payment_confirmed', Kind::PaymentConfirmed, route('retailer.exchanges.show', $e, false), $e->id);
        }
        foreach (array_unique($recipients) as $id) {
            $this->publish($id, 'exchange:'.$e->id.':v:'.$v->id.':'.$kind->value, $kind, route('retailer.exchanges.show', $e, false), $e->id);
        }
    }

    public function recover(): int
    {
        $count = 0;
        MarketplaceNoticeOutbox::whereNull('completed_at')->where('attempts', '<', 5)->where(fn ($q) => $q->whereNull('last_enqueued_at')->orWhere('last_enqueued_at', '<=', now()->subMinutes(5)))->where(fn ($q) => $q->whereNull('processing_at')->orWhere('processing_at', '<=', now()->subMinutes(2)))->orderBy('id')->limit(100)->get()->each(function ($n) use (&$count) {
            $n->update(['last_enqueued_at' => now()]);
            DeliverMarketplaceNotice::dispatch($n->id)->onConnection(config('marketplace.notifications.queue_connection'))->onQueue(config('marketplace.notifications.queue'));
            $count++;
        });

        return $count;
    }
}
