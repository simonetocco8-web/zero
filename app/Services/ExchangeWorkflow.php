<?php

namespace App\Services;

use App\Models\Exchange;
use App\Models\ExchangeEvent;
use App\Models\ExchangeLine;
use App\Models\ExchangeVersion;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ExchangeWorkflow
{
    public function __construct(private ExchangeRules $rules, private ExchangeStock $stock) {}

    private function expiry()
    {
        $hours = config('marketplace.exchange_proposal_hours');
        if ((! is_int($hours) && ! is_string($hours)) || ! preg_match('/^[0-9]{1,3}$/D', (string) $hours) || $hours < 1 || $hours > 168) {
            throw new \LogicException('Invalid exchange proposal duration.');
        }

        return now()->addHours((int) $hours);
    }

    private function hash(User $u, array $input): string
    {
        return hash('sha256', json_encode([$u->id, $input], JSON_THROW_ON_ERROR));
    }

    public function event(Exchange $e, string $action, ?User $u = null, ?int $version = null): void
    {
        ExchangeEvent::create(['exchange_id' => $e->id, 'exchange_version_id' => $version ?? $e->current_version_id, 'user_id' => $u?->id, 'action' => $action]);
        app(MarketplaceNotices::class)->exchangeEvent($e, $action, $u);
    }

    public function create(User $actor, InventoryItem $requested, array $input): Exchange
    {
        Gate::forUser($actor)->authorize('create', Exchange::class);
        $key = $input['key'] ?? '';
        if (! Str::isUuid($key)) {
            $this->rules->fail('key', 'Chiave proposta non valida.');
        }
        $hash = $this->hash($actor, $input);
        $aId = $actor->retailer->id;
        $bId = $requested->retailer_id;
        if ($aId === $bId) {
            $this->rules->fail('exchange', 'Non puoi scambiare con te stesso.');
        }

        return DB::transaction(function () use ($actor, $requested, $input, $aId, $bId, $key, $hash) {
            $retailers = Retailer::whereIn('id', [$aId, $bId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($retailers->count() !== 2) {
                $this->rules->fail('exchange', 'Controparte non valida.');
            }
            $existing = Exchange::where('request_key', $key)->lockForUpdate()->first();
            if ($existing) {
                Gate::forUser($actor)->authorize('view', $existing);
                if ($existing->requested_item_id !== $requested->id || ! hash_equals($existing->request_hash, $hash)) {
                    $this->rules->fail('key', 'Chiave già usata per una proposta differente.');
                }

                return $existing;
            }
            $plans = ['a' => $this->rules->eligible($retailers[$aId]), 'b' => $this->rules->eligible($retailers[$bId])];
            $ids = $this->inputIds($input);
            if (! in_array($requested->id, array_map('intval', array_column($input['lines']['b'] ?? [], 'inventory_item_id')), true)) {
                $this->rules->fail('lines', 'La proposta iniziale deve includere il prodotto richiesto.');
            }
            $products = $this->products($ids, [$aId, $bId], $retailers);
            $expiry = $this->expiry();
            [$snapshot,$lines] = $this->rules->proposal($input, $retailers[$aId], $retailers[$bId], $products, $plans, $expiry);
            $e = Exchange::create(['uuid' => (string) Str::uuid(), 'request_key' => $key, 'request_hash' => $hash, 'retailer_a_id' => $aId, 'retailer_b_id' => $bId, 'requested_item_id' => $requested->id, 'status' => 'proposed', 'expires_at' => $expiry]);
            $this->version($e, $actor, $input, $snapshot, $lines, 1);
            $this->event($e, 'proposed', $actor);

            return $e->refresh();
        }, 5);
    }

    private function inputIds(array $input): array
    {
        $ids = [];
        foreach (['a', 'b'] as $side) {
            if (! is_array($input['lines'][$side] ?? null) || count($input['lines'][$side]) < 1 || count($input['lines'][$side]) > 20) {
                $this->rules->fail('lines', 'Seleziona da 1 a 20 prodotti per lato.');
            }
            foreach ($input['lines'][$side] as $line) {
                $id = $line['inventory_item_id'] ?? null;
                if (! filter_var($id, FILTER_VALIDATE_INT) || $id < 1) {
                    $this->rules->fail('lines', 'Prodotto non valido.');
                } $ids[] = (int) $id;
            }
        }

        return array_unique($ids);
    }

    private function products(array $ids, array $owners, $retailers)
    {
        // Never acquire a third party's stock locks for a forged proposal.
        $items = InventoryItem::withTrashed()->whereIn('id', $ids)->whereIn('retailer_id', $owners)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($items->count() !== count(array_unique($ids))) {
            $this->rules->fail('lines', 'Prodotti non appartenenti ai partecipanti.');
        }
        foreach ($items as $p) {
            $p->setRelation('retailer', $retailers[$p->retailer_id]);
        }

        return $items;
    }

    private function version(Exchange $e, User $actor, array $input, array $snapshot, array $lines, int $number): ExchangeVersion
    {
        $summary = hash('sha256', json_encode([$e->uuid, $number, $snapshot, $lines], JSON_THROW_ON_ERROR));
        $v = ExchangeVersion::create($snapshot + ['exchange_id' => $e->id, 'number' => $number, 'proposed_by' => $actor->retailer->id, 'request_key' => $input['key'], 'request_hash' => $this->hash($actor, $input), 'summary_hash' => $summary]);
        foreach ($lines as $line) {
            $v->lines()->create($line);
        }
        DB::table('exchange_versions')->where('id', $v->id)->update(['sealed_at' => now()]);
        $v->refresh();
        $e->update(['current_version_id' => $v->id, 'expires_at' => $v->expires_at]);

        return $v;
    }

    /** Retailer locks precede all exchange/product locks, sharing marketplace's stock mutex. */
    public function locked(Exchange $exchange, callable $callback, array $extraIds = []): mixed
    {
        return DB::transaction(function () use ($exchange, $callback, $extraIds) {
            $rs = Retailer::whereIn('id', [$exchange->retailer_a_id, $exchange->retailer_b_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $e = Exchange::lockForUpdate()->findOrFail($exchange->id);
            $v = ExchangeVersion::lockForUpdate()->findOrFail($e->current_version_id);
            $lines = ExchangeLine::where('exchange_version_id', $v->id)->orderBy('inventory_item_id')->lockForUpdate()->get();
            $products = $this->products(array_unique([...$lines->pluck('inventory_item_id')->all(), ...$extraIds]), [$e->retailer_a_id, $e->retailer_b_id], $rs);

            return $callback($e, $v, $lines, $products, $rs);
        }, 5);
    }

    private function current(Exchange $e, ExchangeVersion $v, int $id, string $summary): void
    {
        if ($v->id !== $id || ! hash_equals($v->summary_hash, $summary)) {
            $this->rules->fail('version', 'La versione è cambiata. Rivedi e accetta il riepilogo corrente.');
        }
    }

    private function open(Exchange $e): void
    {
        if (! $e->status->open() || $e->expires_at <= now()) {
            $this->rules->fail('exchange', 'Lo scambio non è modificabile oppure è scaduto.');
        }
    }

    public function counter(User $actor, Exchange $exchange, array $input): Exchange
    {
        Gate::forUser($actor)->authorize('negotiate', $exchange);
        if (! Str::isUuid($input['key'] ?? '')) {
            $this->rules->fail('key', 'Chiave controproposta non valida.');
        }
        $ids = $this->inputIds($input);

        return $this->locked($exchange, function ($e, $v, $lines, $products, $rs) use ($actor, $input) {
            $old = ExchangeVersion::where('request_key', $input['key'])->lockForUpdate()->first();
            if ($old) {
                if ($old->exchange_id !== $e->id || ! hash_equals($old->request_hash, $this->hash($actor, $input))) {
                    $this->rules->fail('key', 'Chiave già usata per un riepilogo differente.');
                }

                return $e;
            }
            $this->current($e, $v, (int) ($input['base_version_id'] ?? 0), $input['base_summary_hash'] ?? '');
            $this->open($e);
            $plans = ['a' => $this->rules->eligible($rs[$e->retailer_a_id]), 'b' => $this->rules->eligible($rs[$e->retailer_b_id])];
            $this->stock->release($v);
            $expiry = $this->expiry();
            [$snapshot,$rows] = $this->rules->proposal($input, $rs[$e->retailer_a_id], $rs[$e->retailer_b_id], $products, $plans, $expiry);
            $new = $this->version($e, $actor, $input, $snapshot, $rows, $v->number + 1);
            $e->update(['status' => 'countered']);
            $this->event($e, 'countered', $actor, $new->id);

            return $e->refresh();
        }, $ids);
    }

    public function accept(User $actor, Exchange $exchange, int $versionId, string $summary): Exchange
    {
        Gate::forUser($actor)->authorize('negotiate', $exchange);

        return $this->locked($exchange, function ($e, $v, $lines, $products, $rs) use ($actor, $versionId, $summary) {
            $this->current($e, $v, $versionId, $summary);
            $accepted = $v->acceptances()->where('retailer_id', $actor->retailer->id)->lockForUpdate()->first();
            if ($accepted) {
                return $e;
            }
            app(MarketplaceEconomicRules::class)->exchange();
            $this->open($e);
            if ($v->difference_cents === 0 && $v->payment_provisional) {
                $this->rules->fail('tax', 'I totali provvisori non dimostrano un conguaglio zero. Crea una nuova versione con fiscalità e importi definiti prima di accettare.');
            }
            foreach ($rs as $r) {
                $this->rules->eligible($r);
            }
            foreach ($lines as $line) {
                $this->rules->product($products[$line->inventory_item_id], $line->retailer_id, $line->quantity_milliunits);
                $delivery = $line->side === 'a' ? $v->delivery_a : $v->delivery_b;
                if (! $products[$line->inventory_item_id]->{$delivery['mode'].'_available'}) {
                    $this->rules->fail('delivery', 'La modalità di consegna non è più disponibile. Crea una nuova versione.');
                }
            }
            $v->acceptances()->create(['retailer_id' => $actor->retailer->id, 'user_id' => $actor->id, 'summary_hash' => $v->summary_hash]);
            $this->event($e, 'accepted_by', $actor);
            if ($v->acceptances()->lockForUpdate()->get()->count() < 2) {
                $e->update(['status' => 'awaiting_acceptance']);

                return $e;
            }
            $e->update(['status' => 'accepted']);
            $this->event($e, 'accepted');
            $this->stock->reserve($e, $v, $lines, $products);
            if ($v->difference_cents > 0) {
                $credit = app(RetailerCredit::class)->reserve($v);
                $v->payment()->create(['retailer_credit_cents' => $credit, 'card_cents' => $v->difference_cents - $credit, 'funding_snapshot' => ['policy' => 'matured_ledger_v1', 'retailer_credit_cents' => $credit, 'card_cents' => $v->difference_cents - $credit], 'amount_cents' => $v->difference_cents, 'currency' => 'EUR', 'expires_at' => $e->expires_at]);
                $e->update(['status' => 'awaiting_difference_payment']);
                $this->event($e, 'awaiting_difference_payment');
            } else {
                $this->stock->consume($e, $v, $lines, $products);
                $this->event($e, 'confirmed');
            }

            return $e->refresh();
        });
    }

    public function stop(User $actor, Exchange $exchange, int $versionId, string $summary, string $state): Exchange
    {
        Gate::forUser($actor)->authorize('cancel', $exchange);
        if (! in_array($state, ['rejected', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid exchange terminal state.');
        }

        return $this->locked($exchange, function ($e, $v) use ($actor, $versionId, $summary, $state) {
            app(MarketplaceEconomicRules::class)->exchange();
            $this->current($e, $v, $versionId, $summary);
            if ($e->status->value === $state) {
                return $e;
            }
            $this->open($e);
            $this->stock->release($v);
            $e->update(['status' => $state]);
            $this->event($e, $state, $actor);

            return $e;
        });
    }

    public function complete(User $actor, Exchange $exchange, int $versionId, string $summary): Exchange
    {
        Gate::forUser($actor)->authorize('view', $exchange);

        return $this->locked($exchange, function ($e, $v) use ($actor, $versionId, $summary) {
            $this->current($e, $v, $versionId, $summary);
            if (! in_array($e->status->value, ['confirmed', 'completed'], true)) {
                $this->rules->fail('exchange', 'Conferma lo scambio prima di dichiarare ricevute le merci.');
            }
            if (! $v->completions()->where('retailer_id', $actor->retailer->id)->lockForUpdate()->first()) {
                $v->completions()->create(['retailer_id' => $actor->retailer->id, 'user_id' => $actor->id, 'summary_hash' => $v->summary_hash]);
                $this->event($e, 'received', $actor);
            }
            if ($v->completions()->lockForUpdate()->get()->count() === 2 && $e->status->value !== 'completed') {
                $e->update(['status' => 'completed']);
                $this->event($e, 'completed');
            }

            return $e;
        });
    }

    public function expire(): int
    {
        $count = 0;
        Exchange::whereIn('status', ['proposed', 'countered', 'awaiting_acceptance', 'accepted', 'awaiting_difference_payment'])->where('expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $expired = $this->locked($row, function ($e, $v) {
                    if (! $e->status->open() || $e->expires_at > now()) {
                        return false;
                    }
                    $this->stock->release($v);
                    $e->update(['status' => 'expired']);
                    $payment = $v->payment()->lockForUpdate()->first();
                    if ($payment && $payment->status !== 'paid') {
                        $payment->update(['status' => 'expired']);
                    }
                    $this->event($e, 'expired');

                    return true;
                });
                $count += (int) $expired;
            }
        });

        return $count;
    }
}
