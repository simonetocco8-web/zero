<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingQuoteContextProviderInterface;
use App\Contracts\ShippingQuoteProviderInterface;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SendcloudShippingProvider implements ShippingQuoteContextProviderInterface, ShippingQuoteProviderInterface
{
    public const ENDPOINT = 'https://panel.sendcloud.sc/api/v3/shipping-options';

    public function __construct(private SendcloudAccountResolver $accounts) {}

    public function name(): string
    {
        return 'sendcloud';
    }

    public function context(int $retailerId): string
    {
        return $this->accounts->context($retailerId);
    }

    public function quote(array $shipment): array
    {
        // A transaction guard also protects future callers from accidentally doing HTTP under locks.
        if (DB::transactionLevel() !== 0) {
            $this->fail('Il preventivo Sendcloud deve essere richiesto prima della transazione di conferma.');
        }
        $payload = $this->payload($shipment);
        $credentials = $this->accounts->credentials($shipment['retailer_id']);
        if (! is_string($credentials['public_key']) || $credentials['public_key'] === '' || ! is_string($credentials['private_key']) || $credentials['private_key'] === '') {
            $this->fail('Quotazione Sendcloud non configurata. Contatta l’assistenza.');
        }
        $timeout = config('shipping.sendcloud.timeout');
        if (! is_int($timeout) || $timeout < 1 || $timeout > 20) {
            $this->fail('Timeout Sendcloud non configurato correttamente.');
        }
        $scope = 'sendcloud:'.$this->context($shipment['retailer_id']);
        $this->throttle($scope);
        try {
            // No POST retry, no redirects, TLS verification on, fixed destination only.
            $response = Http::withBasicAuth($credentials['public_key'], $credentials['private_key'])
                ->acceptJson()->asJson()->connectTimeout(min(5, $timeout))->timeout($timeout)
                ->withoutRedirecting()->withOptions(['verify' => true])->post(self::ENDPOINT, $payload);
        } catch (ConnectionException) {
            $this->fail('Sendcloud non risponde entro il tempo previsto. Riprova tra poco.');
        }
        if ($response->status() === 429) {
            $retry = $response->header('Retry-After');
            $seconds = ctype_digit((string) $retry) ? (int) $retry : ((strtotime((string) $retry) ?: time() + 60) - time());
            Cache::put($scope.':cooldown', true, max(1, min(3600, $seconds)));
        }
        if (! $response->successful()) {
            $this->fail(match ($response->status()) {
                400, 422 => 'Sendcloud non può quotare questi indirizzi o imballaggi. Verifica i dati di spedizione.',
                401, 403 => 'Quotazione Sendcloud non autorizzata. Contatta l’assistenza.',
                429 => 'Limite di richieste Sendcloud raggiunto. Attendi prima di riprovare.',
                default => 'Servizio di quotazione Sendcloud temporaneamente non disponibile. Riprova tra poco.',
            });
        }
        if (strlen($response->body()) > 2097152) {
            $this->fail('Risposta Sendcloud non valida.');
        }
        try {
            $root = json_decode($response->body(), false, 64, JSON_THROW_ON_ERROR);
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('Risposta Sendcloud non valida.');
        }
        if (! $root instanceof \stdClass || ! property_exists($root, 'data') || ($root->data !== null && ! is_array($root->data)) || ! is_array($body) || ! array_key_exists('data', $body) || ($body['data'] !== null && (! is_array($body['data']) || ! array_is_list($body['data']))) || count($body['data'] ?? []) > 1000) {
            $this->fail('Risposta Sendcloud non valida.');
        }
        $rates = [];
        foreach ($body['data'] ?? [] as $option) {
            if (! is_array($option) || ! $this->compatible($option, $shipment['parcels'])) {
                continue;
            }
            foreach ($option['quotes'] ?? [] as $quote) {
                $rate = $this->rate($option, $quote);
                if ($rate !== null) {
                    $rates[hash('sha256', json_encode($rate, JSON_THROW_ON_ERROR))] = $rate;
                }
            }
        }

        return array_values($rates);
    }

    public function payload(array $shipment): array
    {
        if (! isset($shipment['retailer_id']) || ! is_int($shipment['retailer_id']) || ! isset($shipment['parcels']) || ! is_array($shipment['parcels']) || ! array_is_list($shipment['parcels']) || count($shipment['parcels']) < 1 || count($shipment['parcels']) > 50) {
            $this->fail('Sendcloud richiede da 1 a 50 colli per spedizione.');
        }
        $parcels = [];
        foreach ($shipment['parcels'] as $parcel) {
            foreach (['length_mm', 'width_mm', 'height_mm', 'weight_g'] as $key) {
                if (! is_array($parcel) || ! isset($parcel[$key]) || ! is_int($parcel[$key]) || $parcel[$key] <= 0 || $parcel[$key] > ($key === 'weight_g' ? 1000000 : 10000)) {
                    $this->fail('Dimensioni o peso dei colli non validi per la quotazione.');
                }
            }
            $parcels[] = ['dimensions' => [
                'length' => $this->decimal($parcel['length_mm'], 10, 1), 'width' => $this->decimal($parcel['width_mm'], 10, 1), 'height' => $this->decimal($parcel['height_mm'], 10, 1), 'unit' => 'cm',
            ], 'weight' => ['value' => $this->decimal($parcel['weight_g'], 1000, 3), 'unit' => 'kg']];
        }
        if (! is_array($shipment['origin'] ?? null) || ! is_array($shipment['destination'] ?? null)) {
            $this->fail('Indirizzi Sendcloud non validi.');
        }

        return ['from_address' => $this->address($shipment['origin'] ?? []), 'to_address' => $this->address($shipment['destination'] ?? []), 'parcels' => $parcels, 'calculate_quotes' => true];
    }

    private function address(array $address): array
    {
        $validator = Validator::make($address, ['country_code' => ['required', 'regex:/^[A-Z]{2}$/D'], 'postal_code' => ['required', 'string', 'max:32'], 'city' => ['required', 'string', 'max:255'], 'province' => ['required', 'string', 'max:80'], 'address_line_1' => ['required', 'string', 'max:255'], 'address_line_2' => ['nullable', 'string', 'max:255']]);
        if ($validator->fails()) {
            $this->fail('Completa Paese, CAP, città, provincia e indirizzo per la quotazione Sendcloud.');
        }
        $province = strtoupper(trim($address['province']));
        $country = $address['country_code'];
        if (preg_match('/^[A-Z0-9]{1,3}$/D', $province)) {
            $province = $country.'-'.$province;
        }
        if (! preg_match('/^'.preg_quote($country, '/').'-[A-Z0-9]{1,3}$/D', $province)) {
            $this->fail('Indica la provincia con sigla o codice ISO 3166-2 per Sendcloud.');
        }

        return ['country_code' => $country, 'postal_code' => $address['postal_code'], 'city' => $address['city'], 'state_province_code' => $province, 'address_line_1' => trim($address['address_line_1'].(! empty($address['address_line_2']) ? ', '.$address['address_line_2'] : ''))];
    }

    private function throttle(string $scope): void
    {
        try {
            Cache::lock($scope.':limiter', 5)->block(1, function () use ($scope) {
                if (Cache::has($scope.':cooldown') || RateLimiter::tooManyAttempts($scope.':minute', 80) || RateLimiter::tooManyAttempts($scope.':second', 8)) {
                    $this->fail('Limite di richieste Sendcloud raggiunto. Attendi prima di riprovare.');
                }
                RateLimiter::hit($scope.':minute', 60);
                RateLimiter::hit($scope.':second', 1);
            });
        } catch (LockTimeoutException) {
            $this->fail('Altre quotazioni Sendcloud sono in corso. Riprova tra poco.');
        }
    }

    private function compatible(array $option, array $parcels): bool
    {
        if (($option['quote_error'] ?? null) !== null || ! is_array($option['quotes'] ?? null) || ! array_is_list($option['quotes']) || count($option['quotes']) > 1000) {
            return false;
        }
        if (($option['requirements']['is_service_point_required'] ?? false) === true || ($option['functionalities']['returns'] ?? false) === true) {
            return false;
        }
        if (count($parcels) > 1 && ($option['functionalities']['multicollo'] ?? null) === false) {
            return false;
        }
        // Explicit provider limits, when returned, must fit every physical parcel.
        foreach ($parcels as $parcel) {
            foreach (['min' => -1, 'max' => 1] as $bound => $direction) {
                if (! isset($option['weight'][$bound])) {
                    continue;
                }
                $limit = $this->measurement($option['weight'][$bound], true);
                if ($limit === null || BigDecimal::of($parcel['weight_g'])->compareTo($limit) === $direction) {
                    return false;
                }
            }
            if (isset($option['max_dimensions'])) {
                foreach (['length', 'width', 'height'] as $dimension) {
                    $max = $option['max_dimensions'];
                    $limit = is_array($max) ? $this->measurement(['value' => $max[$dimension] ?? null, 'unit' => $max['unit'] ?? null], false) : null;
                    if ($limit === null || BigDecimal::of($parcel[$dimension.'_mm'])->isGreaterThan($limit)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function measurement(mixed $value, bool $weight): ?BigDecimal
    {
        if (! is_array($value) || ! is_string($value['unit'] ?? null) || ! is_string($value['value'] ?? null) || ! preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,12})?$/D', $value['value'])) {
            return null;
        }
        $units = $weight ? ['g' => '1', 'kg' => '1000', 'lbs' => '453.59237', 'oz' => '28.349523125'] : ['mm' => '1', 'cm' => '10', 'm' => '1000', 'in' => '25.4', 'ft' => '304.8', 'yd' => '914.4'];
        $factor = $units[$value['unit'] ?? ''] ?? null;

        return $factor !== null ? BigDecimal::of($value['value'])->multipliedBy($factor) : null;
    }

    private function rate(array $option, mixed $quote): ?array
    {
        if (! is_array($quote)) {
            return null;
        }
        $cents = $this->money($quote['price']['total'] ?? null);
        if ($cents === null) {
            return null;
        }
        foreach ([$option['code'] ?? null, $option['name'] ?? null, $option['carrier']['code'] ?? null, $option['carrier']['name'] ?? null] as $value) {
            if (! is_string($value) || $value === '' || mb_strlen($value) > 255) {
                return null;
            }
        }
        $breakdown = $this->components($quote['price']['breakdown'] ?? []);
        $surcharges = $this->components($quote['estimated_surcharges'] ?? []);
        if ($breakdown === null || $surcharges === null) {
            return null;
        }
        $leadTime = $quote['lead_time'] ?? null;
        if ($leadTime !== null && (! is_int($leadTime) || $leadTime < 0 || $leadTime > 87600)) {
            return null;
        }

        return [
            'shipping_option_code' => $option['code'], 'carrier_code' => $option['carrier']['code'],
            'carrier' => $option['carrier']['name'], 'service' => $option['name'],
            'original_cents' => $cents, 'final_cents' => $cents, 'currency' => 'EUR',
            'tax_treatment' => null, 'tax_rate_basis_points' => null,
            'price_breakdown' => $breakdown, 'estimated_surcharges' => $surcharges,
            'contract_id' => is_int($option['contract']['id'] ?? null) ? $option['contract']['id'] : null,
            'quote_source' => in_array($option['quote_source'] ?? null, ['sendcloud_prices', 'csv_upload', 'carrier_api', 'pricing_form', 'sendcloud_prices_api'], true) ? $option['quote_source'] : null,
            'lead_time_hours' => $leadTime,
            'eta' => $leadTime !== null ? 'Tempo indicativo: '.$leadTime.' ore' : null,
            'conditions' => 'Tariffa indicativa Sendcloud; IVA da verificare. I supplementi stimati sono separati dal totale e possono modificare l’importo fatturato.',
        ];
    }

    private function components(mixed $components): ?array
    {
        if (! is_array($components) || ! array_is_list($components) || count($components) > 100) {
            return null;
        }
        $clean = [];
        foreach ($components as $component) {
            if (! is_array($component) || ! is_string($component['type'] ?? null) || ! is_string($component['label'] ?? null) || mb_strlen($component['type']) > 80 || mb_strlen($component['label']) > 255) {
                return null;
            }
            $cents = $this->money($component['price'] ?? null, true);
            if ($cents === null) {
                return null;
            }
            $clean[] = ['type' => $component['type'], 'label' => $component['label'], 'amount_cents' => $cents, 'currency' => 'EUR'];
        }

        return $clean;
    }

    private function money(mixed $price, bool $signed = false): ?int
    {
        if (! is_array($price) || ($price['currency'] ?? null) !== 'EUR' || ! is_string($price['value'] ?? null) || ! preg_match($signed ? '/^-?[0-9]{1,9}(?:\.[0-9]{1,12})?$/D' : '/^[0-9]{1,9}(?:\.[0-9]{1,12})?$/D', $price['value'])) {
            return null;
        }
        try {
            $cents = BigDecimal::of($price['value'])->multipliedBy(100)->toBigInteger()->toInt();
        } catch (\Throwable) {
            return null;
        }

        return abs($cents) <= 100000000 ? $cents : null;
    }

    private function decimal(int $value, int $factor, int $scale): string
    {
        return (string) BigDecimal::of($value)->dividedBy($factor, $scale)->strippedOfTrailingZeros();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['shipping' => $message]);
    }
}
