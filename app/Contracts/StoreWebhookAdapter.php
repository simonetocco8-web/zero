<?php

namespace App\Contracts;

use App\Data\StoreEventData;
use Illuminate\Http\Request;

interface StoreWebhookAdapter
{
    public function verifyAndNormalize(Request $request, string $provider): StoreEventData;
}
