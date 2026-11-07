<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingQuoteContextProviderInterface;
use App\Contracts\ShippingQuoteProviderInterface;
use App\Models\Retailer;
use App\Models\ShippingQuote;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShippingQuoteService
{
    public function __construct(private ShippingQuoteProviderInterface $provider, private ShippingParcelBuilder $parcels) {}

    public function address(array $data): array
    {
        $fields = ['recipient_name', 'company', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'province', 'country_code', 'phone'];
        $data = array_intersect_key($data, array_flip($fields));
        foreach ($data as $key => $value) {
            if (! is_string($value) && $value !== null) {
                $this->fail('Indirizzo non valido.');
            }
            $data[$key] = trim((string) $value);
        }
        $data['country_code'] = strtoupper($data['country_code'] ?? '');
        $data['province'] = mb_strtoupper($data['province'] ?? '');
        Validator::make($data, [
            'recipient_name' => ['required', 'string', 'max:255'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'regex:/^[A-Za-z0-9 -]{2,32}$/D'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:80'],
            'country_code' => ['required', 'regex:/^[A-Z]{2}$/D'],
            'company' => ['nullable', 'string', 'max:255'], 'address_line_2' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
        ], [], ['recipient_name' => 'destinatario', 'address_line_1' => 'indirizzo e numero civico', 'postal_code' => 'CAP', 'city' => 'città', 'province' => 'provincia', 'country_code' => 'paese', 'phone' => 'telefono'])->validate();
        if ($data['country_code'] === 'IT' && (! preg_match('/^[0-9]{5}$/D', $data['postal_code']) || ! preg_match('/^[A-Z]{2}$/D', $data['province']))) {
            $this->fail('Per l’Italia indica CAP di cinque cifre e sigla provincia di due lettere.');
        }

        // Stable keys, including absent optional fields, for reliable destination comparisons.
        return array_replace(array_fill_keys($fields, ''), $data);
    }

    public function origin(Retailer $retailer): array
    {
        return $this->address(['recipient_name' => $retailer->company_name, 'address_line_1' => $retailer->address, 'postal_code' => $retailer->postal_code, 'city' => $retailer->city, 'province' => $retailer->province, 'country_code' => $retailer->country_code]);
    }

    public function snapshot(Retailer $retailer, array $lines, array $destination, string $context): array
    {
        usort($lines, fn ($a, $b) => $a['product']->id <=> $b['product']->id);
        $products = [];
        foreach ($lines as $line) {
            $p = $line['product'];
            if ($p->retailer_id !== $retailer->id || ! $p->shipping_available) {
                $this->fail('Origine o modalità non coerente.');
            }
            $products[] = ['id' => $p->id, 'quantity_milliunits' => $line['quantity_milliunits'], 'unit' => $p->unit, 'packing_rule' => $p->packing_rule, 'units_per_parcel_milliunits' => $p->units_per_parcel_milliunits];
        }

        return ($this->provider instanceof ShippingQuoteContextProviderInterface ? ['provider_context' => $this->provider->context($retailer->id)] : []) + ['context' => $context, 'retailer_id' => $retailer->id, 'origin' => $this->origin($retailer), 'destination' => $this->address($destination), 'lines' => $products, 'parcels' => $this->parcels->build($lines)];
    }

    public function quote(User $user, Retailer $origin, array $lines, array $destination, string $context = 'sale'): array
    {
        Gate::forUser($user)->authorize('create', ShippingQuote::class);
        if (! config('shipping.enabled')) {
            $this->fail('Quotazione dinamica disattivata.');
        }
        $snapshot = $this->snapshot($origin, $lines, $destination, $context);
        $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        // User-scoped cache avoids cross-user reuse; lock also deduplicates concurrent requests.
        $key = 'shipping:'.$this->provider->name().':'.$user->id.':'.$fingerprint;

        try {
            return Cache::lock($key.':lock', 30)->block(3, function () use ($key, $user, $origin, $snapshot, $fingerprint, $context) {
                $cached = Cache::get($key);
                if ($cached !== null) {
                    $rows = ShippingQuote::where('user_id', $user->id)->whereIn('id', $cached)->where('status', 'valid')->where('expires_at', '>', now())->get();
                    if ($rows->count() === count($cached)) {
                        return $this->sortQuotes($rows->all());
                    }
                }
                $rates = $this->provider->quote($snapshot);
                if (count($rates) > 1000) {
                    $this->fail('Risposta del provider non valida.');
                }
                $cleanRates = [];
                foreach ($rates as $rate) {
                    if (! is_array($rate)) {
                        $this->fail('Risposta del provider non valida.');
                    }
                    foreach (['original_cents', 'final_cents'] as $field) {
                        if (! isset($rate[$field]) || ! is_int($rate[$field]) || $rate[$field] < 0 || $rate[$field] > 100000000) {
                            $this->fail('Importo del provider non valido.');
                        }
                    }
                    Validator::make($rate, ['carrier' => ['required', 'string', 'max:255'], 'service' => ['required', 'string', 'max:255'], 'currency' => ['required', 'in:EUR'], 'tax_treatment' => ['nullable', 'in:included,excluded,not_applicable'], 'tax_rate_basis_points' => ['nullable', 'integer', 'between:0,10000'], 'conditions' => ['nullable', 'string', 'max:2000'], 'eta' => ['nullable', 'string', 'max:255'], 'shipping_option_code' => ['nullable', 'string', 'max:255'], 'carrier_code' => ['nullable', 'string', 'max:255'], 'contract_id' => ['nullable', 'integer'], 'quote_source' => ['nullable', 'string', 'max:40'], 'price_breakdown' => ['sometimes', 'array', 'max:100'], 'estimated_surcharges' => ['sometimes', 'array', 'max:100'], 'lead_time_hours' => ['nullable', 'integer', 'between:0,87600']])->validate();
                    $rate = array_intersect_key($rate, array_flip(['carrier', 'service', 'original_cents', 'final_cents', 'currency', 'tax_treatment', 'tax_rate_basis_points', 'conditions', 'eta', 'shipping_option_code', 'carrier_code', 'contract_id', 'quote_source', 'price_breakdown', 'estimated_surcharges', 'lead_time_hours']));
                    $cleanRates[] = $rate;
                }
                $quotes = [];
                foreach ($cleanRates as $rate) {
                    $quotes[] = ShippingQuote::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'retailer_id' => $origin->id, 'provider' => $this->provider->name(), 'context' => $context, 'fingerprint' => $fingerprint, 'request_snapshot' => $snapshot, 'rate' => $rate, 'expires_at' => now()->addSeconds(config('shipping.ttl_seconds'))]);
                }
                $quotes = $this->sortQuotes($quotes);
                Cache::put($key, array_map(fn ($q) => $q->id, $quotes), min(config('shipping.cache_seconds'), config('shipping.ttl_seconds')));

                return $quotes;
            });
        } catch (LockTimeoutException) {
            $this->fail('Una quotazione per questi dati è già in corso. Attendi e riprova.');
        }
    }

    private function sortQuotes(array $quotes): array
    {
        $key = fn ($q) => [$q->rate['final_cents'], $q->rate['shipping_option_code'] ?? '', $q->rate['carrier'], $q->rate['service'], json_encode($q->rate, JSON_THROW_ON_ERROR), $q->id];
        usort($quotes, fn ($a, $b) => $key($a) <=> $key($b));

        return $quotes;
    }

    public function valid(User $user, string $id, Retailer $origin, array $lines, array $destination, string $context = 'sale', bool $locking = false): ShippingQuote
    {
        $query = ShippingQuote::where('user_id', $user->id)->whereKey($id);
        if ($locking) {
            $query->lockForUpdate();
        }
        $quote = $query->first();
        if (! $quote || $quote->status !== 'valid' || $quote->expires_at <= now() || $quote->provider !== $this->provider->name() || $quote->context !== $context) {
            $this->fail('Preventivo assente, scaduto o non autorizzato. Ricalcola la spedizione.');
        }
        Gate::forUser($user)->authorize('view', $quote);
        $snapshot = $this->snapshot($origin, $lines, $destination, $context);
        if (! hash_equals($quote->fingerprint, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)))) {
            $this->fail('Prodotti, quantità, imballaggi o indirizzi sono cambiati. Ricalcola la spedizione.');
        }

        return $quote;
    }

    public function checkoutAllowed(): void
    {
        if (! config('shipping.checkout_verified') || ($this->provider->name() === 'fake' && ! app()->environment('testing'))) {
            $this->fail('La tariffa è indicativa. L’acquisto con spedizione non è ancora disponibile. Puoi scegliere il ritiro, se previsto.');
        }
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['shipping' => $message]);
    }
}
