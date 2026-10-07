<?php

namespace App\Enums;

enum AdministrativeAction: string
{
    case RetailerApproved = 'retailer_approved';
    case RetailerRejected = 'retailer_rejected';
    case RetailerSuspended = 'retailer_suspended';
    case InventoryApproved = 'inventory_approved';
    case InventoryRejected = 'inventory_rejected';
    case InventoryPublished = 'inventory_published';
    case PayoutPaid = 'payout_paid';
    case PayoutRejected = 'payout_rejected';
}
