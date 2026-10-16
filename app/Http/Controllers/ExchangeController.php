<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExchangeDecisionRequest;
use App\Http\Requests\ExchangeProposalRequest;
use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceCatalog;
use App\Services\Payments\SimulateExchangeDifferencePayment;
use App\Services\Payments\StartExchangeDifferencePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ExchangeController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('viewAny', Exchange::class);
        $owner = $r->user()->retailer->id;
        $tab = $r->validate(['tab' => ['nullable', 'in:all,received,sent,to_accept,payment,completed,closed']])['tab'] ?? 'all';
        $q = Exchange::where(fn ($q) => $q->where('retailer_a_id', $owner)->orWhere('retailer_b_id', $owner))->with(['retailerA', 'retailerB', 'currentVersion']);
        match ($tab) {
            'received' => $q->where('retailer_b_id', $owner), 'sent' => $q->where('retailer_a_id', $owner),
            'to_accept' => $q->whereIn('status', ['proposed', 'countered', 'awaiting_acceptance'])->where('expires_at', '>', now())->whereHas('currentVersion', fn ($v) => $v->whereDoesntHave('acceptances', fn ($a) => $a->where('retailer_id', $owner))),
            'payment' => $q->where('status', 'awaiting_difference_payment')->where('expires_at', '>', now()), 'completed' => $q->where('status', 'completed'),
            'closed' => $q->where(fn ($closed) => $closed->whereIn('status', ['rejected', 'cancelled', 'expired'])->orWhere(fn ($expired) => $expired->whereIn('status', ['proposed', 'countered', 'awaiting_acceptance', 'accepted', 'awaiting_difference_payment'])->where('expires_at', '<=', now()))), default => $q,
        };

        return view('exchanges.index', ['exchanges' => $q->latest('id')->paginate(15)->withQueryString(), 'tab' => $tab]);
    }

    public function create(Request $r, InventoryItem $item, MarketplaceCatalog $catalog)
    {
        Gate::authorize('create', Exchange::class);
        Gate::authorize('viewPublic', $item);
        abort_unless($catalog->canExchange($r->user(), $item), 403);

        return $this->form($r, null, $item);
    }

    private function form(Request $r, ?Exchange $exchange, ?InventoryItem $item = null)
    {
        $a = $exchange?->retailerA ?? $r->user()->retailer;
        $b = $exchange?->retailerB ?? $item->retailer;
        $products = InventoryItem::whereIn('retailer_id', [$a->id, $b->id])->where('status', 'published')->whereNotNull('published_at')->where('exchange_available', true)->withSum(['stockReservations as reserved_milliunits' => fn ($q) => $q->where('status', 'active')->where('expires_at', '>', now())], 'quantity_milliunits')->orderBy('name')->get();

        return view('exchanges.form', ['exchange' => $exchange, 'requested' => $item, 'version' => $exchange?->currentVersion?->load('lines'), 'a' => $a, 'b' => $b, 'products' => $products, 'key' => (string) Str::uuid()]);
    }

    public function edit(Request $r, Exchange $exchange)
    {
        Gate::authorize('negotiate', $exchange);
        abort_unless($exchange->status->open() && $exchange->expires_at > now(), 409);

        return $this->form($r, $exchange->load('retailerA', 'retailerB', 'currentVersion'));
    }

    public function store(ExchangeProposalRequest $r, ExchangeWorkflow $flow)
    {
        $data = $r->validated();
        $item = InventoryItem::findOrFail($data['requested_item_id']);
        $e = $flow->create($r->user(), $item, $data);

        return to_route('retailer.exchanges.show', $e)->with('status', 'Proposta creata. Entrambe le parti devono accettare il riepilogo.');
    }

    public function counter(ExchangeProposalRequest $r, Exchange $exchange, ExchangeWorkflow $flow)
    {
        $e = $flow->counter($r->user(), $exchange, $r->validated());

        return to_route('retailer.exchanges.show', $e)->with('status', 'Nuova versione creata. Le accettazioni precedenti non valgono per questa versione.');
    }

    public function show(Exchange $exchange)
    {
        Gate::authorize('view', $exchange);

        $exchange->load('retailerA', 'retailerB', 'versions.lines', 'versions.acceptances', 'versions.completions', 'versions.payment', 'events');
        $version = $exchange->versions->firstWhere('id', $exchange->current_version_id);
        abort_unless($version, 404);
        $exchange->setRelation('currentVersion', $version);

        return view('exchanges.show', compact('exchange', 'version'));
    }

    public function accept(ExchangeDecisionRequest $r, Exchange $exchange, ExchangeWorkflow $flow)
    {
        $e = $flow->accept($r->user(), $exchange, (int) $r->validated('version_id'), $r->validated('summary_hash'));
        if ($e->status->value === 'awaiting_difference_payment') {
            try {
                app(StartExchangeDifferencePayment::class)->handle($e->currentVersion);
            } catch (\Throwable) {
                return to_route('retailer.exchanges.show', $e)->with('status', 'Accettazioni salvate e quantità prenotate. Intento fake da riprovare prima della scadenza.');
            }
        }

        return to_route('retailer.exchanges.show', $e)->with('status', 'Accettazione registrata per la versione indicata.');
    }

    public function reject(ExchangeDecisionRequest $r, Exchange $exchange, ExchangeWorkflow $flow)
    {
        return $this->stop($r, $exchange, $flow, 'rejected');
    }

    public function cancel(ExchangeDecisionRequest $r, Exchange $exchange, ExchangeWorkflow $flow)
    {
        return $this->stop($r, $exchange, $flow, 'cancelled');
    }

    private function stop($r, $exchange, $flow, $state)
    {
        $e = $flow->stop($r->user(), $exchange, (int) $r->validated('version_id'), $r->validated('summary_hash'), $state);

        return to_route('retailer.exchanges.show', $e)->with('status', 'Scambio chiuso; eventuali prenotazioni attive rilasciate.');
    }

    public function complete(ExchangeDecisionRequest $r, Exchange $exchange, ExchangeWorkflow $flow)
    {
        $e = $flow->complete($r->user(), $exchange, (int) $r->validated('version_id'), $r->validated('summary_hash'));

        return to_route('retailer.exchanges.show', $e)->with('status', 'Ricezione merci registrata. Lo scambio sarà completato dopo la conferma di entrambi.');
    }

    public function simulate(ExchangeDecisionRequest $r, Exchange $exchange)
    {
        $outcome = app(SimulateExchangeDifferencePayment::class)->handle($r->user(), $exchange, (int) $r->validated('version_id'), $r->validated('summary_hash'));
        $message = match ($outcome) {
            'confirmed','already_paid' => 'Conguaglio simulato riuscito; nessun addebito reale.','tax_unresolved' => 'IVA da definire: nessun incasso simulato. Lo scambio resta in attesa fino alla scadenza.','reconciliation_required' => 'Scambio non confermato: pagamento tardivo o versione non più valida.',default => 'Esito simulato registrato.'
        };

        return to_route('retailer.exchanges.show', $exchange)->with('status', $message);
    }
}
