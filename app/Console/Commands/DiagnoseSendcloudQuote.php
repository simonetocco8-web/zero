<?php

namespace App\Console\Commands;

use App\Services\Shipping\SendcloudShippingProvider;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class DiagnoseSendcloudQuote extends Command
{
    protected $signature = 'shipping:diagnose-sendcloud {--input= : File JSON locale con retailer_id, origin, destination e parcels normalizzati}';

    protected $description = 'Richiede manualmente sole quotazioni Sendcloud v3, senza creare etichette o salvare indirizzi';

    public function handle(SendcloudShippingProvider $provider): int
    {
        $path = $this->option('input');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 65536) {
            $this->error('Specifica un file JSON locale leggibile (massimo 64 KiB).');

            return self::FAILURE;
        }
        try {
            $shipment = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($shipment)) {
                throw new \JsonException;
            }
            $rates = $provider->quote($shipment);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        } catch (\JsonException) {
            $this->error('File JSON non valido.');

            return self::FAILURE;
        }
        $this->info('Quotazioni informative; IVA da verificare. Nessuna spedizione o etichetta creata.');
        $this->table(['Opzione', 'Corriere', 'Servizio', 'Importo EUR', 'Supplementi stimati'], array_map(fn ($rate) => [
            $rate['shipping_option_code'], $rate['carrier'], $rate['service'],
            (string) BigDecimal::of($rate['final_cents'])->dividedBy(100, 2), count($rate['estimated_surcharges']),
        ], $rates));

        return self::SUCCESS;
    }
}
