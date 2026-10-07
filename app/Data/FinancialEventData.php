<?php

namespace App\Data;

use App\Support\ExactInteger;
use App\Support\MoneyMath;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class FinancialEventData
{
    private function __construct(public string $type, private array $data) {}

    public function toArray(): array
    {
        return $this->data;
    }

    public function toInput(): array
    {
        $data = $this->data;
        if ($this->type === 'order.paid') {
            foreach ($data['items'] as &$line) {
                $line['quantity'] = (string) BigDecimal::of($line['quantity_milliunits'])->dividedBy(1000, 3);
                unset($line['quantity_milliunits']);
            }
        }

        return $data;
    }

    public static function fromArray(string $type, array $input): self
    {
        if (! in_array($type, ['order.paid', 'refund'], true)) {
            self::invalid('type', 'Tipo evento non supportato.');
        }
        $refund = $type === 'refund';
        $integer = function ($attribute, $value, $fail) {
            try {
                if (ExactInteger::parse($value) < 0) {
                    $fail('Importo non valido.');
                }
            } catch (\Throwable) {
                $fail('Usare centesimi interi, mai float.');
            }
        };
        $rules = ['currency' => ['sometimes', 'in:EUR'], 'provider' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'], 'external_order_id' => ['required', 'string', 'max:191'], 'external_event_id' => ['sometimes', 'string', 'max:191'], 'timestamp' => ['required', 'date'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.external_line_id' => ['required', 'string', 'max:191', 'distinct:strict']];
        if ($refund) {
            $rules += ['external_refund_id' => ['required', 'string', 'max:191'], 'items.*.amount_cents' => ['required', $integer]];
        } else {
            $rules += ['items.*.inventory_item_id' => ['required', 'integer', 'min:1'], 'items.*.retailer_id' => ['required', 'integer', 'min:1'], 'items.*.quantity' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/'], 'items.*.unit_price_cents' => ['required', $integer], 'items.*.discount_cents' => ['sometimes', $integer]];
        }
        $valid = Validator::make($input, $rules)->validate();
        $data = ['provider' => $valid['provider'], 'external_order_id' => $valid['external_order_id'], 'timestamp' => CarbonImmutable::parse($valid['timestamp'])->utc()->format('Y-m-d H:i:s'), 'items' => []];
        if ($refund) {
            $data['external_refund_id'] = $valid['external_refund_id'];
        }
        foreach ($valid['items'] as $line) {
            if ($refund) {
                $amount = ExactInteger::parse($line['amount_cents']);
                if ($amount === 0) {
                    self::invalid('amount_cents', 'Il rimborso deve essere positivo.');
                }
                $data['items'][] = ['external_line_id' => $line['external_line_id'], 'amount_cents' => $amount];
            } else {
                if (is_float($line['quantity'])) {
                    self::invalid('quantity', 'Usare una quantità decimale esatta.');
                }
                try {
                    $quantity = BigDecimal::of($line['quantity'])->multipliedBy(1000)->toBigInteger()->toInt();
                    $price = ExactInteger::parse($line['unit_price_cents']);
                    MoneyMath::lineTotalCents($price, $quantity);
                } catch (\Throwable) {
                    self::invalid('items', 'Quantità o importo fuori intervallo.');
                }
                if ($quantity <= 0) {
                    self::invalid('quantity', 'La quantità deve essere positiva.');
                }
                $data['items'][] = ['external_line_id' => $line['external_line_id'], 'inventory_item_id' => (int) $line['inventory_item_id'], 'retailer_id' => (int) $line['retailer_id'], 'quantity_milliunits' => $quantity, 'unit_price_cents' => $price, 'discount_cents' => ExactInteger::parse($line['discount_cents'] ?? 0)];
            }
        }
        usort($data['items'], fn ($a, $b) => strcmp($a['external_line_id'], $b['external_line_id']));
        if (isset($valid['external_event_id'])) {
            $data['external_event_id'] = $valid['external_event_id'];
        }

        return new self($type, $data);
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
