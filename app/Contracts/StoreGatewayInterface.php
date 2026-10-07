<?php

namespace App\Contracts;

interface StoreGatewayInterface
{
    public function createProduct(string $productKey, array $data, int $revision): array;

    public function updateProduct(string $externalId, array $data, int $revision): array;

    public function publishProduct(string $externalId, int $revision): array;
}
