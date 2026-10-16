<?php

namespace App\Enums;

enum ExchangeStatus: string
{
    case Proposed = 'proposed';
    case Countered = 'countered';
    case AwaitingAcceptance = 'awaiting_acceptance';
    case Accepted = 'accepted';
    case AwaitingDifferencePayment = 'awaiting_difference_payment';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposto', self::Countered => 'Controproposta', self::AwaitingAcceptance => 'Da accettare', self::Accepted => 'Accettato', self::AwaitingDifferencePayment => 'In pagamento', self::Confirmed => 'Confermato', self::Rejected => 'Rifiutato', self::Cancelled => 'Annullato', self::Expired => 'Scaduto', self::Completed => 'Completato',
        };
    }

    public function open(): bool
    {
        return in_array($this, [self::Proposed, self::Countered, self::AwaitingAcceptance, self::Accepted, self::AwaitingDifferencePayment], true);
    }
}
