<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case SaleCredit = 'sale_credit';
    case Commission = 'commission';
    case Refund = 'refund';
    case PayoutReservation = 'payout_reservation';
    case PayoutRelease = 'payout_release';
    case Payout = 'payout';
    case PayoutReversal = 'payout_reversal';
    case Adjustment = 'adjustment';
}
