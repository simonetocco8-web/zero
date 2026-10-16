<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Pending = 'pending';
    case PaymentPending = 'payment_pending';
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
