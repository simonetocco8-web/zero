<?php

namespace App\Services\InventoryImport;

use App\Enums\InventoryCondition;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class ImportFields
{
    public const LABELS = [
        'name' => 'Nome prodotto', 'brand' => 'Marca', 'sku' => 'Codice articolo / SKU', 'ean' => 'Codice EAN', 'description' => 'Descrizione', 'category' => 'Categoria',
        'condition' => 'Condizione articolo', 'unit' => 'Unità di vendita', 'province' => 'Provincia', 'quantity' => 'Quantità disponibile', 'zero_price' => 'Prezzo ZeroMagazzino', 'list_price' => 'Prezzo di listino',
        'pickup_available' => 'Ritiro in sede', 'shipping_available' => 'Spedizione disponibile', 'shipping_cost' => 'Costo spedizione', 'delivery_notes' => 'Note consegna',
        'vat_treatment' => 'Trattamento IVA merce', 'vat_rate' => 'Aliquota IVA merce', 'shipping_vat_treatment' => 'Trattamento IVA spedizione', 'shipping_vat_rate' => 'Aliquota IVA spedizione', 'exchange_available' => 'Scambio disponibile',
    ];

    public const REQUIRED = ['name', 'description', 'condition', 'unit', 'province', 'quantity', 'zero_price'];

    public const NUMBERS = ['quantity' => 3, 'zero_price' => 2, 'list_price' => 2, 'shipping_cost' => 2, 'vat_rate' => 2, 'shipping_vat_rate' => 2];

    public function suggestions(array $headers): array
    {
        $aliases = ['name' => ['nome', 'prodotto', 'descr_art', 'articolo'], 'description' => ['descrizione', 'description'], 'brand' => ['marca', 'brand'], 'sku' => ['codice', 'sku', 'cod_art', 'codice articolo'], 'ean' => ['ean', 'barcode'], 'quantity' => ['qta', 'quantita', 'giacenza', 'quantity'], 'zero_price' => ['prezzo', 'prezzo zero', 'price'], 'list_price' => ['listino'], 'category' => ['categoria', 'famiglia'], 'province' => ['provincia']];
        $result = [];
        $used = [];
        foreach ($aliases as $field => $names) {
            foreach ($headers as $i => $header) {
                $header = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', trim($header)));
                if (! isset($used[$i]) && in_array($header, $names, true)) {
                    $result[$field] = (string) $i;
                    $used[$i] = true;
                    break;
                }
            }
        }

        return $result;
    }

    public function mapping(array $sources, array $fixed, int $width): array
    {
        $mapping = [];
        $used = [];
        foreach (self::LABELS as $field => $label) {
            $source = $sources[$field] ?? '';
            if (! is_string($source)) {
                $this->fail($field, 'Associazione non valida.');
            }
            if ($source === 'fixed') {
                if ($field === 'category') {
                    $this->fail($field, 'Configura le categorie nel passaggio successivo.');
                }
                $value = $fixed[$field] ?? '';
                if (! is_string($value) || mb_strlen($value) > 10000) {
                    $this->fail($field, 'Valore fisso non valido.');
                }
                $mapping[$field] = ['fixed' => trim($value)];
            } elseif ($source !== '') {
                if (! ctype_digit($source) || (int) $source >= $width || isset($used[(int) $source])) {
                    $this->fail($field, 'Seleziona una colonna esistente e non già associata.');
                }
                $used[(int) $source] = true;
                $mapping[$field] = ['column' => (int) $source];
            } elseif (in_array($field, self::REQUIRED, true)) {
                $this->fail($field, "$label: associa una colonna o un valore fisso esplicito.");
            }
        }

        return $mapping;
    }

    public function value(array $cells, ?array $mapping): ?string
    {
        return $mapping === null ? null : trim(isset($mapping['column']) ? $cells[$mapping['column']] : $mapping['fixed']);
    }

    public function normalize(array $cells, array $settings, ?array $categoryIndex = null): array
    {
        $data = [];
        foreach (self::LABELS as $field => $label) {
            $value = $this->value($cells, $settings['mapping'][$field] ?? null);
            if ($value === null || $value === '') {
                continue;
            }
            if ($field === 'category') {
                continue;
            }
            if (isset(self::NUMBERS[$field])) {
                $value = $this->number($value, $settings['number_format'], self::NUMBERS[$field], $field);
            }
            if (str_ends_with($field, '_available')) {
                $value = match (mb_strtolower($value)) {
                    'sì', 'si', 'yes', 'true', '1' => true, 'no', 'false', '0' => false, default => throw ValidationException::withMessages([$field => "$label: usa sì/no, yes/no, true/false o 1/0."])
                };
            }
            if ($field === 'condition') {
                $value = ['nuovo' => 'new', 'fine serie' => 'end_of_line', 'fondo magazzino' => 'old_stock', 'imballo danneggiato' => 'damaged_packaging'][mb_strtolower($value)] ?? $value;
                if (! InventoryCondition::tryFrom($value)) {
                    $this->fail($field, 'Condizione articolo non riconosciuta.');
                }
            }
            if (in_array($field, ['sku', 'ean']) && preg_match('/^[+-]?[0-9.,]+e[+-]?[0-9]+$/iD', $value)) {
                $this->fail($field, 'Codice in notazione scientifica: riesporta il codice come testo dal gestionale.');
            }
            $data[$field] = $value;
        }
        if ($settings['category_mode'] === 'single') {
            $data['category_id'] = $settings['category_id'];
        } else {
            $value = $this->value($cells, $settings['mapping']['category'] ?? null) ?? '';
            $categoryIndex ??= array_column($settings['category_links'] ?? [], 'id', 'source');
            $data['category_id'] = $categoryIndex[$value] ?? null;
        }
        $data['intent'] = 'draft';

        return $data;
    }

    public function number(string $value, string $format, int $scale, string $field): string
    {
        $decimal = $format === 'italian' ? ',' : '.';
        $group = $format === 'italian' ? '.' : ',';
        $pattern = '/^(?:[0-9]{1,9}|[0-9]{1,3}(?:'.preg_quote($group, '/').'[0-9]{3})+)(?:'.preg_quote($decimal, '/').'[0-9]{1,'.$scale.'})?$/D';
        if (! preg_match($pattern, $value)) {
            $this->fail($field, 'Numero non valido per il formato scelto o precisione eccessiva.');
        }
        $value = str_replace([$group, $decimal], ['', '.'], $value);
        $parts = explode('.', $value);
        $parts[0] = ltrim($parts[0], '0') ?: '0';

        return implode('.', $parts);
    }

    public function numberSuggestion(iterable $rows, array $mapping): string
    {
        $possible = ['italian' => true, 'international' => true];
        $ambiguous = false;
        foreach ($rows as $row) {
            if (! is_array($row->cells)) {
                continue;
            }
            foreach (self::NUMBERS as $field => $scale) {
                $value = $this->value($row->cells, $mapping[$field] ?? null);
                if ($value === null || $value === '') {
                    continue;
                }
                $results = [];
                foreach (array_keys($possible) as $format) {
                    try {
                        $results[$format] = $this->number($value, $format, $scale, $field);
                    } catch (ValidationException) {
                        unset($possible[$format]);
                    }
                }
                if (count(array_unique($results)) > 1) {
                    $ambiguous = true;
                }
            }
        }
        if (count($possible) === 1) {
            return 'Formato suggerito dai campioni: '.(isset($possible['italian']) ? 'italiano' : 'internazionale').'. Controlla e conferma la scelta sotto.';
        }

        return ! $possible ? 'I campioni contengono formati misti o numeri non validi. Controlla i dati prima di confermare.' : ($ambiguous ? 'Separatore ambiguo nei campioni: conferma esplicitamente il formato usato dal gestionale.' : 'I campioni non distinguono i due formati: conferma quello usato dal gestionale.');
    }

    public static function euros(string $millicents): string
    {
        $parts = explode('.', (string) BigDecimal::of($millicents)->dividedBy(100000, 2, RoundingMode::HalfUp));

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $parts[0]).','.$parts[1];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
