<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PaymentPending => 'In attesa di pagamento', self::Paid => 'Pagato', self::Preparing => 'In preparazione', self::Shipped => 'Spedito', self::ReadyForPickup => 'Pronto per il ritiro', self::Completed => 'Completato', self::Cancelled => 'Annullato', self::Refunded => 'Rimborsato'
        };
    }
}
