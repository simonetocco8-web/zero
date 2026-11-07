<?php

namespace App\Services\Shipping;

/** Centralized today; replace this binding to resolve encrypted merchant credentials/OAuth later. */
class SendcloudAccountResolver
{
    public function credentials(int $retailerId): array
    {
        return [
            'public_key' => config('shipping.sendcloud.public_key'),
            'private_key' => config('shipping.sendcloud.private_key'),
        ];
    }

    public function context(int $retailerId): string
    {
        // Opaque scope changes on credential rotation; no credential is exposed or logged.
        return hash('sha256', json_encode($this->credentials($retailerId), JSON_THROW_ON_ERROR));
    }
}
