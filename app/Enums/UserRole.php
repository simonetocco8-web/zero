<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Retailer = 'retailer';
    case Admin = 'admin';
}
