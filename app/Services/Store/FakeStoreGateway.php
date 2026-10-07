<?php

namespace App\Services\Store;

use App\Contracts\StoreGatewayInterface;
use App\Models\FakeStoreProduct;
use Illuminate\Support\Facades\DB;

class FakeStoreGateway implements StoreGatewayInterface
{
    public function createProduct(string $productKey, array $data, int $revision): array
    {
        $id = 'fake-'.$productKey;
        FakeStoreProduct::firstOrCreate(['id' => $id], ['revision' => $revision, 'payload' => $data, 'published' => false]);

        return $this->updateProduct($id, $data, $revision) + ['operation' => 'createProduct'];
    }

    public function updateProduct(string $externalId, array $data, int $revision): array
    {
        return DB::transaction(function () use ($externalId, $data, $revision) {
            $product = FakeStoreProduct::lockForUpdate()->findOrFail($externalId);
            if ($revision >= $product->revision) {
                $product->update(['payload' => $data, 'revision' => $revision, 'archived' => $revision > $product->revision ? false : $product->archived]);
            }

            return ['provider' => 'fake', 'external_product_id' => $externalId, 'revision' => $product->revision, 'simulated' => true];
        });
    }

    public function publishProduct(string $externalId, int $revision): array
    {
        return DB::transaction(function () use ($externalId, $revision) {
            $product = FakeStoreProduct::lockForUpdate()->findOrFail($externalId);
            if ($revision === $product->revision && ! $product->archived) {
                $product->update(['published' => true]);
            }

            return ['provider' => 'fake', 'external_product_id' => $externalId, 'published' => $product->published, 'revision' => $product->revision, 'simulated' => true];
        });
    }

    public function archiveProduct(string $externalId, int $revision): array
    {
        return DB::transaction(function () use ($externalId, $revision) {
            $product = FakeStoreProduct::lockForUpdate()->findOrFail($externalId);
            if ($revision >= $product->revision) {
                $product->update(['published' => false, 'archived' => true, 'revision' => $revision]);
            }

            return ['provider' => 'fake', 'external_product_id' => $externalId, 'published' => $product->published, 'revision' => $product->revision, 'simulated' => true];
        });
    }
}
