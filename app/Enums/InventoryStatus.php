<?php

namespace App\Enums;

enum InventoryStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case ChangePending = 'change_pending';
    case Rejected = 'rejected';
    case Archived = 'archived';
}
