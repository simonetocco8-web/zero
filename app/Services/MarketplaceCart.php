<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MarketplaceCart
{
    public function cart(User $user): Cart
    {
        Gate::forUser($user)->authorize('purchase', User::class);

        return Cart::firstOrCreate(['user_id' => $user->id]);
    }

    public function quantity(mixed $value): int
    {
        if (! is_int($value) && ! is_string($value) || ! preg_match('/^[0-9]{1,9}(?:[.,][0-9]{1,3})?$/D', (string) $value)) {
            $this->fail('quantity', 'Quantità non valida.');
        }
        $q = BigDecimal::of(str_replace(',', '.', (string) $value))->multipliedBy(1000)->toBigInteger()->toInt();
        if ($q <= 0) {
            $this->fail('quantity', 'La quantità deve essere positiva.');
        }

        return $q;
    }

    public function validate(User $user, InventoryItem $product, int $q, string $mode): void
    {
        if (! app(PublicInventory::class)->isAvailable($product) || $product->retailer->user_id === $user->id) {
            $this->fail('cart', 'Prodotto non acquistabile o appartenente al tuo account.');
        }
        if ($product->currency !== 'EUR' || $q <= 0 || $q > app(StockReservations::class)->available($product)) {
            $this->fail('quantity', 'Quantità disponibile insufficiente o valuta non supportata.');
        }
        if (! in_array($mode, ['pickup', 'shipping'], true) || ! $product->{$mode.'_available'}) {
            $this->fail('delivery_mode', 'Modalità di consegna non disponibile.');
        }
    }

    public function save(User $user, InventoryItem $product, mixed $quantity, string $mode, bool $add = true): CartItem
    {
        Gate::forUser($user)->authorize('purchase', User::class);
        $q = $this->quantity($quantity);

        return DB::transaction(function () use ($user, $product, $q, $mode, $add) {
            User::lockForUpdate()->findOrFail($user->id);
            Retailer::lockForUpdate()->findOrFail($product->retailer_id);
            $product = InventoryItem::lockForUpdate()->findOrFail($product->id);
            $cart = $this->cart($user);
            $existing = $cart->items()->where('inventory_item_id', $product->id)->lockForUpdate()->first();
            if (! $existing && $cart->items()->count() >= 100) {
                $this->fail('cart', 'Il carrello può contenere al massimo 100 articoli distinti.');
            }
            $quantity = $q + ($add ? ($existing?->quantity_milliunits ?? 0) : 0);
            $this->validate($user, $product, $quantity, $mode);
            $existing ??= $cart->items()->make(['inventory_item_id' => $product->id]);
            $existing->fill(['quantity_milliunits' => $quantity, 'delivery_mode' => $mode])->save();

            return $existing;
        }, 5);
    }

    public function remove(User $user, CartItem $item): void
    {
        Gate::forUser($user)->authorize('delete', $item);
        DB::transaction(function () use ($user, $item) {
            User::lockForUpdate()->findOrFail($user->id);
            $item->delete();
        });
    }

    public function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
