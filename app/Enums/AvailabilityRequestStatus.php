<?php

namespace App\Enums;

enum AvailabilityRequestStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Closed = 'closed';
}
