<?php

namespace App\Services;

use App\Data\FinancialEventData;
use App\Models\IntegrationEvent;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\VendorOrder;
use App\Models\WalletTransaction;
use App\Support\MoneyMath;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Trusted adapter entry point for confirmed paid sales; no public unsigned webhook endpoint. */
class SaleAccounting
{
    public function recordSale(array|FinancialEventData $input): Sale
    {
        $dto = $input instanceof FinancialEventData ? $input : FinancialEventData::fromArray('order.paid', $input);
        if ($dto->type !== 'order.paid') {
            $this->invalid('type', 'Atteso evento vendita.');
        }
        $data = $dto->toArray();

        return DB::transaction(function () use ($data) {
            $event = $this->receipt($data, 'sale', $data['external_order_id']);
            $this->lockRetailers(array_column($data['items'], 'retailer_id'));
            $existing = Sale::where('provider', $data['provider'])->where('external_order_id', $data['external_order_id'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status->value !== 'payment_pending' && $event->status->value !== 'processed') {
                    $this->invalid('external_order_id', 'Ordine già presente senza una ricevuta economica coerente.');
                }

                if ($existing->paid_at) {
                    return $existing;
                }
                if ($existing->status->value !== 'payment_pending') {
                    $this->invalid('status', 'Ordine chiuso.');
                }
            }
            if ($existing) {
                $expected = $existing->vendorOrders()->where('status', '!=', 'cancelled')->pluck('retailer_id')->sort()->values()->all();
                $actual = array_values(array_unique(array_column($data['items'], 'retailer_id')));
                sort($actual);
                if ($expected !== $actual) {
                    $this->invalid('items', 'I rivenditori non corrispondono all’ordine in attesa.');
                }
            }
            $sale = $existing ?? Sale::create(['provider' => $data['provider'], 'external_order_id' => $data['external_order_id'], 'status' => 'paid', 'currency' => 'EUR', 'total_cents' => 0, 'ordered_at' => $data['timestamp'], 'paid_at' => $data['timestamp']]);
            $sale->update(['status' => 'paid', 'paid_at' => $data['timestamp']]);
            $total = BigInteger::zero();
            foreach ($data['items'] as $line) {
                $product = InventoryItem::whereKey($line['inventory_item_id'])->where('retailer_id', $line['retailer_id'])->lockForUpdate()->first();
                if (! $product || $product->currency !== 'EUR') {
                    $this->invalid('items', 'Prodotto e rivenditore non corrispondono, oppure valuta non supportata.');
                }
                $order = $sale->vendorOrders()->firstOrCreate(['retailer_id' => $line['retailer_id']], ['status' => 'paid', 'fulfillment_status' => 'paid', 'stock_rule' => config('orders.stock_rule'), 'delivery_method' => $line['delivery_method'] ?? 'shipping']);
                if ($order->status === 'cancelled') {
                    $this->invalid('status', 'Sottoordine annullato.');
                }
                if ($order->delivery_method !== ($line['delivery_method'] ?? 'shipping')) {
                    $this->invalid('delivery_method', 'Modalità incompatibili nello stesso sottoordine.');
                }
                $order->update(['status' => 'paid', 'fulfillment_status' => 'paid']);
                if (! in_array($order->stock_rule, ['external', 'deduct_only', 'restore_on_full_refund'], true)) {
                    $this->invalid('stock', 'Regola stock non valida.');
                }
                $deducted = 0;
                if ($order->stock_rule !== 'external') {
                    if ($product->quantity_milliunits < $line['quantity_milliunits']) {
                        $this->invalid('stock', 'Stock insufficiente.');
                    }
                    $deducted = $line['quantity_milliunits'];
                    $product->update(['quantity_milliunits' => $product->quantity_milliunits - $deducted]);
                }
                $retailer = Retailer::findOrFail($line['retailer_id']);
                $subscription = $retailer->subscriptions()->whereIn('status', ['active', 'cancelled', 'expired'])->where('starts_at', '<=', $data['timestamp'])->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $data['timestamp']))->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $data['timestamp']))->orderByDesc('starts_at')->orderByDesc('id')->lockForUpdate()->first();
                if (! $subscription) {
                    $this->invalid('plan', 'Nessun piano valido alla data della vendita.');
                }
                $plan = Plan::whereKey($subscription->plan_id)->sharedLock()->firstOrFail();
                $gross = MoneyMath::lineTotalCents($line['unit_price_cents'], $line['quantity_milliunits']) - $line['discount_cents'];
                if ($gross < 0) {
                    $this->invalid('discount_cents', 'Lo sconto supera il valore della riga.');
                }
                $commission = MoneyMath::commissionCents($gross, $plan->commission_basis_points);
                $item = $sale->items()->create([...array_diff_key($line, ['delivery_method' => true]), 'vendor_order_id' => $order->id, 'stock_deducted_milliunits' => $deducted, 'name' => $product->name, 'sku' => $product->sku, 'unit' => $product->unit, 'currency' => 'EUR', 'line_total_cents' => $gross, 'commission_basis_points' => $plan->commission_basis_points, 'commission_cents' => $commission, 'net_cents' => $gross - $commission]);
                $available = CarbonImmutable::parse($data['timestamp'])->addDays(config('finance.credit_maturation_days', 0));
                $this->movement($item, $event, 'sale_credit', $gross, 'sale:'.$item->id.':credit', $available, 'Vendita');
                $this->movement($item, $event, 'commission', -$commission, 'sale:'.$item->id.':commission', $available, 'Commissione vendita');
                $total = $total->plus($gross);
            }
            $sale->update(['total_cents' => $total->toInt()]);
            foreach ($sale->vendorOrders()->where('status', 'paid')->get() as $order) {
                app(OperationalLifecycle::class)->record($order, 'payment_pending', 'paid');
            }
            $this->processed($event);

            return $sale->load('items');
        }, 5);
    }

    /** Trusted adapter entry point; pending orders never credit the wallet. */
    public function recordPending(array $input): Sale
    {
        $data = FinancialEventData::fromArray('order.paid', $input)->toArray();

        return DB::transaction(function () use ($data) {
            $event = $this->receipt($data, 'pending', $data['external_order_id']);
            $this->processed($event);
            $this->lockRetailers(array_column($data['items'], 'retailer_id'));
            $sale = Sale::where('provider', $data['provider'])->where('external_order_id', $data['external_order_id'])->lockForUpdate()->first();
            if ($sale) {
                return $sale;
            }
            $sale = Sale::create(['provider' => $data['provider'], 'external_order_id' => $data['external_order_id'], 'status' => 'payment_pending', 'total_cents' => 0, 'ordered_at' => $data['timestamp']]);
            foreach ($data['items'] as $line) {
                if (! InventoryItem::whereKey($line['inventory_item_id'])->where('retailer_id', $line['retailer_id'])->exists()) {
                    $this->invalid('items', 'Prodotto e rivenditore non corrispondono.');
                }
                $sale->vendorOrders()->firstOrCreate(['retailer_id' => $line['retailer_id']], ['status' => 'payment_pending', 'fulfillment_status' => 'payment_pending', 'delivery_method' => $line['delivery_method'] ?? 'shipping', 'stock_rule' => config('orders.stock_rule')]);
            }

            return $sale;
        }, 5);
    }

    public function refund(array|FinancialEventData $input): Sale
    {
        $dto = $input instanceof FinancialEventData ? $input : FinancialEventData::fromArray('refund', $input);
        if ($dto->type !== 'refund') {
            $this->invalid('type', 'Atteso evento rimborso.');
        }
        $data = $dto->toArray();

        return DB::transaction(function () use ($data) {
            $event = $this->receipt($data, 'refund', $data['external_refund_id']);
            $sale = Sale::where('provider', $data['provider'])->where('external_order_id', $data['external_order_id'])->first();
            if (! $sale) {
                $this->invalid('external_order_id', 'Vendita non trovata.');
            }
            $lines = $sale->items()->get();
            $this->lockRetailers($lines->pluck('retailer_id')->all());
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($event->status->value === 'processed') {
                return $sale;
            }
            if (! $sale->paid_at) {
                $this->invalid('external_order_id', 'La vendita non è stata confermata.');
            }
            if (! config('orders.allow_partial_refunds')) {
                foreach ($sale->vendorOrders()->lockForUpdate()->get() as $order) {
                    $requested = collect($data['items'])->keyBy('external_line_id');
                    $items = $order->items()->lockForUpdate()->get();
                    if (! $items->contains(fn ($item) => $requested->has($item->external_line_id))) {
                        continue;
                    }
                    foreach ($items as $item) {
                        $remaining = $item->line_total_cents + (int) $item->walletTransactions()->where('type', 'refund')->lockForUpdate()->get()->sum('amount_cents');
                        if (($requested->get($item->external_line_id)['amount_cents'] ?? 0) !== $remaining) {
                            $this->invalid('items', 'È richiesto il rimborso totale del sottoordine.');
                        }
                    }
                }
            }
            foreach ($data['items'] as $line) {
                $item = $sale->items()->where('external_line_id', $line['external_line_id'])->lockForUpdate()->first();
                if (! $item) {
                    $this->invalid('items', 'Riga non presente nella vendita.');
                }
                $order = VendorOrder::whereKey($item->vendor_order_id)->lockForUpdate()->firstOrFail();
                if ($order->status === 'cancelled') {
                    $this->invalid('status', 'Sottoordine annullato.');
                }
                $entries = $item->walletTransactions()->lockForUpdate()->get();
                $refunded = BigInteger::zero();
                $returned = BigInteger::zero();
                foreach ($entries as $entry) {
                    if ($entry->type->value === 'refund') {
                        $refunded = $refunded->minus($entry->amount_cents);
                    }
                    if ($entry->type->value === 'adjustment' && str_starts_with($entry->idempotency_key, 'refund:')) {
                        $returned = $returned->plus($entry->amount_cents);
                    }
                }
                $cumulative = $refunded->plus($line['amount_cents']);
                if ($cumulative->isGreaterThan($item->line_total_cents)) {
                    $this->invalid('amount_cents', 'Il rimborso supera il lordo residuo.');
                }
                $target = BigDecimal::of($cumulative->multipliedBy($item->commission_cents))->dividedBy($item->line_total_cents, 0, RoundingMode::HalfUp)->toBigInteger();
                $commission = $target->minus($returned)->toInt();
                $credit = $entries->first(fn ($e) => $e->type->value === 'sale_credit');
                $available = CarbonImmutable::parse($data['timestamp']);
                if ($credit?->available_at && $credit->available_at > $available) {
                    $available = $credit->available_at;
                }
                if ($cumulative->isEqualTo($item->line_total_cents)) {
                    app(OrderStock::class)->restore($item, $order);
                }
                $key = 'refund:'.$event->id.':'.$item->id;
                $this->movement($item, $event, 'refund', -$line['amount_cents'], $key.':gross', $available, 'Rimborso vendita');
                $this->movement($item, $event, 'adjustment', $commission, $key.':commission', $available, 'Rimborso commissione');
            }
            foreach ($sale->vendorOrders()->lockForUpdate()->get() as $order) {
                $ids = $order->items()->pluck('id');
                $amount = -(int) WalletTransaction::whereIn('sale_item_id', $ids)->where('type', 'refund')->lockForUpdate()->get()->sum('amount_cents');
                if ($amount === 0 || ! $order->items()->whereIn('external_line_id', array_column($data['items'], 'external_line_id'))->exists()) {
                    continue;
                }
                $status = $amount === (int) $order->items()->lockForUpdate()->get()->sum('line_total_cents') ? 'refunded' : 'partially_refunded';
                if ($status === 'refunded') {
                    foreach ($order->items()->where('line_total_cents', 0)->lockForUpdate()->get() as $freeItem) {
                        app(OrderStock::class)->restore($freeItem, $order);
                    }
                }
                $before = $order->status;
                $order->update(['status' => $status]);
                app(OperationalLifecycle::class)->record($order, $before, $status, metadata: ['integration_event_id' => $event->id, 'refunded_cents' => $amount]);
            }
            app(OperationalLifecycle::class)->aggregate($sale);
            $this->processed($event);

            return $sale;
        }, 5);
    }

    private function receipt(array $data, string $type, string $businessId): IntegrationEvent
    {
        $payload = $data;
        unset($payload['external_event_id']);
        $hash = hash('sha256', json_encode(['type' => $type, ...$payload], JSON_THROW_ON_ERROR));
        $keys = ['accounting:'.$type.':'.hash('sha256', $businessId)];
        if (isset($data['external_event_id'])) {
            $keys[] = 'incoming:'.hash('sha256', $data['external_event_id']);
        }
        sort($keys);
        $canonical = null;
        foreach ($keys as $key) {
            $new = new IntegrationEvent(['provider' => $data['provider'], 'external_event_id' => $key, 'event_type' => 'accounting.'.$type, 'payload_hash' => $hash, 'payload' => $payload, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $new->setCreatedAt(now());
            $new->setUpdatedAt(now());
            IntegrationEvent::upsert([$new->getAttributes()], ['provider', 'external_event_id'], ['external_event_id']);
            $event = IntegrationEvent::where('provider', $data['provider'])->where('external_event_id', $key)->lockForUpdate()->firstOrFail();
            if (! hash_equals($event->payload_hash, $hash)) {
                $this->invalid('event', 'Identificativo già utilizzato con dati differenti.');
            }
            if (str_starts_with($key, 'accounting:')) {
                $canonical = $event;
            } else {
                $this->processed($event);
            }
        }

        return $canonical;
    }

    private function lockRetailers(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        foreach ($ids as $id) {
            if (! Retailer::whereKey($id)->lockForUpdate()->first()) {
                $this->invalid('retailer', 'Rivenditore non trovato.');
            }
        }
    }

    private function movement(SaleItem $item, IntegrationEvent $event, string $type, int $amount, string $key, mixed $available, string $description): void
    {
        if ($amount === 0) {
            return;
        }
        $item->walletTransactions()->create(['retailer_id' => $item->retailer_id, 'integration_event_id' => $event->id, 'currency' => 'EUR', 'type' => $type, 'amount_cents' => $amount, 'idempotency_key' => $key, 'available_at' => $available, 'description' => $description]);
    }

    private function processed(IntegrationEvent $event): void
    {
        if ($event->status->value !== 'processed') {
            $event->update(['status' => 'processed', 'processed_at' => now(), 'attempts' => 1]);
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
