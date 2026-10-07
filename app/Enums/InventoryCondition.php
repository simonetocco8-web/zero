<?php

namespace App\Enums;

enum InventoryCondition: string
{
    case New = 'new';
    case EndOfLine = 'end_of_line';
    case OldStock = 'old_stock';
    case DamagedPackaging = 'damaged_packaging';
}
