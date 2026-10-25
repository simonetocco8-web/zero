<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\StockReservation;
use App\Support\DisplayMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class InventoryProductPresentationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::factory()->create(array_merge(['retailer_id' => Retailer::factory()->approved()], $attributes));
    }

    public static function states(): array
    {
        return [['draft', 'Bozza', false], ['pending', 'In verifica', false], ['published', 'Pubblicato', true], ['change_pending', 'Modifica in verifica', true], ['rejected', 'Rifiutato', false], ['archived', 'Archiviato', false]];
    }

    #[DataProvider('states')]
    public function test_state_and_policy_actions(string $status, string $label, bool $public): void
    {
        $item = $this->item(['status' => $status, 'published_at' => $public ? now() : null]);
        $response = $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertOk()->assertSee($label)->assertSee('Codici prodotto')->assertSee('Dati fiscali')->assertDontSee('>Visualizza<', false)->assertDontSee('aria-label="Visualizza"', false);
        $response->assertSee('Elimina articolo');
        if ($public) {
            $response->assertSee('Vedi nel marketplace')->assertSee('Apri richiesta pubblica');
        } else {
            $response->assertDontSee('Vedi nel marketplace')->assertDontSee('Apri richiesta pubblica');
        }
        if ($status === 'archived') {
            $response->assertDontSee('Modifica prodotto')->assertDontSee('Gestisci foto');
        } else {
            $response->assertSee('Modifica prodotto')->assertSee('Gestisci foto');
        }
        if (in_array($status, ['draft', 'pending', 'rejected'])) {
            $response->assertSee('>Archivia<', false);
        } else {
            $response->assertDontSee('>Archivia<', false);
        }
    }

    public static function photos(): array
    {
        return [[0], [1], [3]];
    }

    #[DataProvider('photos')]
    public function test_gallery_uses_protected_urls_and_preserves_positions(int $count): void
    {
        $item = $this->item();
        for ($index = 0; $index < $count; $index++) {
            $item->images()->create(['disk' => 'local', 'path' => 'inventory/private-'.$index.'.jpg', 'position' => $index + 2]);
        }
        $response = $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertOk()->assertDontSee('private-');
        if ($count === 0) {
            $response->assertSee('Nessuna foto disponibile');
        } else {
            $response->assertSee(route('inventory.image', [$item, 2]), false)->assertSee('object-contain');
        }
        if ($count > 1) {
            $response->assertSee('Mostra foto 3 di 3')->assertSee('x-on:click="active = 2"', false)->assertSee('aria-pressed=', false);
        } else {
            $response->assertDontSee('Selezione foto');
        }
    }

    public static function commercialData(): array
    {
        return [[true, false, null, false, null, null], [false, true, 30000, true, 79900, 2200], [true, true, 0, true, 0, 0]];
    }

    #[DataProvider('commercialData')]
    public function test_delivery_tax_exchange_list_price_and_description(bool $pickup, bool $shipping, ?int $cost, bool $exchange, ?int $list, ?int $rate): void
    {
        $item = $this->item(['pickup_available' => $pickup, 'shipping_available' => $shipping, 'shipping_cost_cents' => $cost, 'exchange_available' => $exchange, 'list_price_cents' => $list, 'vat_treatment' => $rate === null ? null : 'included', 'vat_rate_basis_points' => $rate, 'shipping_vat_treatment' => $rate === null ? null : 'excluded', 'shipping_vat_rate_basis_points' => $rate, 'description' => "Descrizione completa\n".str_repeat('Dettaglio ', 200), 'delivery_notes' => 'Chiamare prima della consegna']);
        $response = $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertOk()->assertSee($item->vatLabel())->assertSee($item->shippingVatLabel())->assertSee($item->description)->assertSee($item->delivery_notes)->assertSee($exchange ? 'Disponibile per scambio' : 'Scambio non disponibile')->assertSee($shipping ? 'Spedizione disponibile' : 'Spedizione non disponibile')->assertSee($pickup ? 'Ritiro in sede' : 'Ritiro non disponibile');
        $response->assertSee($cost === null ? 'Da definire' : DisplayMoney::euros($cost));
        if ($list === null) {
            $response->assertDontSee('Prezzo di listino');
        } else {
            $response->assertSee('Prezzo di listino')->assertSee(DisplayMoney::euros($list));
        }
    }

    public function test_proposals_are_separate_and_reading_does_not_mutate_any_inventory_data(): void
    {
        $item = $this->item(['status' => 'change_pending', 'published_at' => now(), 'name' => 'Versione approvata']);
        $item->forceFill(['proposed_data' => ['name' => 'Nuovo nome proposto', 'zero_price_cents' => 59900, 'quantity_milliunits' => 4000, 'description' => 'Descrizione proposta', 'vat_treatment' => 'excluded', 'vat_rate_basis_points' => 1000, 'shipping_available' => true, 'shipping_cost_cents' => 3000, 'exchange_available' => true], 'proposed_images' => [4 => ['disk' => 'local', 'path' => 'inventory/secret-proposed.jpg']]])->save();
        $before = $item->fresh()->getRawOriginal();
        $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertOk()->assertSee('Versione approvata')->assertSee('Versione pubblicata')->assertSee('Nuovo nome proposto')->assertSee('Descrizione proposta')->assertSee('Nuove foto proposte')->assertSee('Foto attualmente pubblicate')->assertSee(route('inventory.image', [$item, 4, 'proposed' => 1]))->assertDontSee('secret-proposed')->assertSee('599,00')->assertSee('IVA esclusa')->assertSee('10%');
        $this->assertSame($before, $item->fresh()->getRawOriginal());
    }

    public function test_image_only_and_explicit_empty_proposals_are_visible(): void
    {
        $item = $this->item();
        $item->forceFill(['proposed_images' => []])->save();
        $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertOk()->assertSee('Modifiche proposte')->assertSee('Nuove foto proposte')->assertSee('Versione attuale');
    }

    public function test_rejection_has_existing_correction_link_and_other_retailer_is_denied(): void
    {
        $item = $this->item(['status' => 'rejected', 'rejection_reason' => 'Inserire una descrizione più precisa']);
        $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertOk()->assertSee('Motivo di rifiuto')->assertSee($item->rejection_reason)->assertSee('Correggi prodotto')->assertSee(route('retailer.stock.edit', $item));
        $this->actingAs(Retailer::factory()->approved()->create()->user)->get(route('retailer.stock.show', $item))->assertForbidden();
    }

    public function test_available_quantity_accounts_for_existing_reservations(): void
    {
        $item = $this->item(['quantity_milliunits' => 4000]);
        StockReservation::create(['inventory_item_id' => $item->id, 'quantity_milliunits' => 1000, 'expires_at' => now()->addMinutes(10), 'idempotency_key' => 'presentation-reservation']);
        $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertOk()->assertSee('Quantità disponibile')->assertSee('3 '.$item->unit)->assertSee('Quantità registrata: 4 '.$item->unit);
        $this->assertSame(4000, $item->fresh()->quantity_milliunits);
    }

    public function test_suspended_retailer_cannot_open_inventory_detail(): void
    {
        $item = $this->item(['status' => 'published', 'published_at' => now()]);
        $item->retailer->update(['status' => 'suspended']);
        $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertForbidden();
    }

    public function test_data_only_proposal_keeps_current_photos_separate(): void
    {
        $item = $this->item(['status' => 'change_pending', 'published_at' => now()]);
        $item->forceFill(['proposed_data' => ['name' => 'Nuovo nome'], 'proposed_images' => null])->save();
        $this->actingAs($item->retailer->user)->get(route('retailer.stock.show', $item))->assertOk()->assertSee('Nuovo nome')->assertSee('Nessuna modifica alle foto proposta')->assertDontSee('Nuove foto proposte');
    }

    public function test_list_keeps_view_action(): void
    {
        $item = $this->item();
        $this->actingAs($item->retailer->user)->get(route('retailer.stock'))->assertOk()->assertSee('aria-label="Visualizza"', false)->assertSee(route('retailer.stock.show', $item));
    }
}
