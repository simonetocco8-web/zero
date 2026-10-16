<?php

namespace App\Services;

use App\Models\Exchange;
use App\Models\ExchangeVersion;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

/** All operations require ExchangeWorkflow's ordered retailer/product/exchange locks. */
class ExchangeStock
{
    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Exchange stock operations require a transaction and ordered parent locks.');
        }
    }

    public function reserve(Exchange $exchange, ExchangeVersion $version, $lines, $products): void
    {
        $this->requireTransaction();
        app(MarketplaceEconomicRules::class)->exchange();
        $minutes = $version->difference_cents > 0 ? ($version->parties_snapshot['payment_terms']['difference_payment_minutes'] ?? config('marketplace.exchange_difference_payment_minutes')) : config('marketplace.reservation_minutes');
        if ((! is_string($minutes) && ! is_int($minutes)) || ! preg_match('/^[0-9]{1,5}$/D', (string) $minutes) || $minutes < 1 || $minutes > 10080) {
            throw new \LogicException('Invalid exchange payment reservation duration.');
        }
        // Payment window starts at double acceptance, independently of proposal/checkout TTL.
        $expiry = now()->addMinutes((int) $minutes);
        foreach ($lines as $line) {
            if ($line->quantity_milliunits > app(StockReservations::class)->available($products->get($line->inventory_item_id))) {
                app(ExchangeRules::class)->fail('quantity', 'Quantità insufficiente: le merci potrebbero essere già acquistate o prenotate.');
            }
            StockReservation::create(['inventory_item_id' => $line->inventory_item_id, 'user_id' => $products->get($line->inventory_item_id)->retailer->user_id, 'exchange_id' => $exchange->id, 'exchange_version_id' => $version->id, 'quantity_milliunits' => $line->quantity_milliunits, 'expires_at' => $expiry, 'idempotency_key' => 'exchange:'.$exchange->uuid.':v:'.$version->id.':item:'.$line->inventory_item_id]);
        }
        $exchange->update(['expires_at' => $expiry]);
    }

    public function rows(ExchangeVersion $version)
    {
        $this->requireTransaction();

        return StockReservation::where('exchange_version_id', $version->id)->orderBy('inventory_item_id')->lockForUpdate()->get();
    }

    public function active(Exchange $exchange, ExchangeVersion $version, $lines): bool
    {
        $rows = $this->rows($version);

        return $exchange->current_version_id === $version->id && $exchange->expires_at > now() && $rows->count() === $lines->count() && $lines->isNotEmpty() && $lines->every(function ($line) use ($rows, $exchange) {
            $r = $rows->firstWhere('inventory_item_id', $line->inventory_item_id);

            return $r && $r->exchange_id === $exchange->id && $r->order_id === null && $r->status->value === 'active' && $r->expires_at > now() && $r->quantity_milliunits === $line->quantity_milliunits;
        });
    }

    public function release(ExchangeVersion $version): void
    {
        foreach ($this->rows($version) as $r) {
            if ($r->status->value === 'active') {
                $r->update(['status' => $r->expires_at <= now() ? 'expired' : 'released', 'released_at' => now()]);
            }
        }
        app(RetailerCredit::class)->finish($version, false);
        $payment = $version->payment()->lockForUpdate()->first();
        if ($payment && $payment->status === 'payment_pending') {
            $payment->update(['status' => 'cancelled']);
        }
    }

    public function consume(Exchange $exchange, ExchangeVersion $version, $lines, $products): void
    {
        if (! $this->active($exchange, $version, $lines)) {
            app(ExchangeRules::class)->fail('exchange', 'Prenotazione scaduta o non valida.');
        }
        foreach ($this->rows($version) as $r) {
            $p = $products->get($r->inventory_item_id);
            if (! $p || $p->quantity_milliunits < $r->quantity_milliunits) {
                throw new \LogicException('Reserved physical stock is inconsistent.');
            }
            $q = $r->quantity_milliunits;
            $changed = DB::table('inventory_items')->where('id', $p->id)->where('quantity_milliunits', '>=', $q)->update(['quantity_milliunits' => DB::raw('quantity_milliunits - '.$q), 'stock_version' => DB::raw('stock_version + 1'), 'updated_at' => now()]);
            if ($changed !== 1) {
                throw new \LogicException('Exchange stock consumption failed.');
            }
            $r->update(['status' => 'consumed', 'consumed_at' => now()]);
        }
        $exchange->update(['status' => 'confirmed']);
    }
}
