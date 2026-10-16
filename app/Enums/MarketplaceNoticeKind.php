<?php

namespace App\Enums;

enum MarketplaceNoticeKind: string
{
    case OrderReceived = 'order_received';
    case PaymentConfirmed = 'payment_confirmed';
    case ExchangeProposed = 'exchange_proposed';
    case ExchangeCountered = 'exchange_countered';
    case ExchangeAccepted = 'exchange_accepted';
    case ExchangeRejected = 'exchange_rejected';
    case ExchangeExpired = 'exchange_expired';
    case DifferenceDue = 'difference_due';
    case DifferenceReceived = 'difference_received';
    case OrderShipped = 'order_shipped';
    case ReadyForPickup = 'ready_for_pickup';
    case DeliveryCompleted = 'delivery_completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function title(): string
    {
        return match ($this) {
            self::OrderReceived => 'Nuovo ordine ricevuto',self::PaymentConfirmed => 'Pagamento confermato',self::ExchangeProposed => 'Nuova proposta di scambio',self::ExchangeCountered => 'Nuova controproposta',self::ExchangeAccepted => 'Scambio accettato',self::ExchangeRejected => 'Scambio rifiutato',self::ExchangeExpired => 'Scambio scaduto',self::DifferenceDue => 'Conguaglio da pagare',self::DifferenceReceived => 'Conguaglio ricevuto',self::OrderShipped => 'Ordine spedito',self::ReadyForPickup => 'Pronto al ritiro',self::DeliveryCompleted => 'Consegna completata',self::Cancelled => 'Annullamento',self::Refunded => 'Rimborso registrato'
        };
    }
}
