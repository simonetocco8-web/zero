<?php

namespace App\Exceptions;

use RuntimeException;

class EmailDeliveryNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email delivery is not configured.');
    }
}
