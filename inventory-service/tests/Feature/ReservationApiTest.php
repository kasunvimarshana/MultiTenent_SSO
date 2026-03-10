<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the Inventory Service reservation API.
 * Specifically validates the compensating-transaction behaviour used by Sagas.
 */
class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'sku'               => 'TEST-' . rand(1000, 9999),
            'name'              => 'Test Product',
            'category'          => 'test',
            'description'       => 'A test product',
            'price'             => 19.99,
            'stock_quantity'    => 50,
            'reserved_quantity' => 0,
            'active'            => true,
        ], $overrides));
    }

    // ── Successful reservation ────────────────────────────────────────────

    public function test_can_reserve_stock(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 10]);

        $sagaId = (string) \Illuminate\Support\Str::uuid();

        $response = $this->postJson('/api/reservations', [
            'order_id' => 1,
            'saga_id'  => $sagaId,
            'items'    => [['product_id' => $product->id, 'quantity' => 3]],
        ]);

        $response->assertCreated()
                 ->assertJsonStructure(['reservation_ids', 'message']);

        $product->refresh();
        $this->assertEquals(3, $product->reserved_quantity);
        $this->assertEquals(7, $product->available_quantity);
    }

    // ── Insufficient stock triggers 422 ───────────────────────────────────

    public function test_reservation_fails_when_insufficient_stock(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 2]);

        $sagaId = (string) \Illuminate\Support\Str::uuid();

        $response = $this->postJson('/api/reservations', [
            'order_id' => 2,
            'saga_id'  => $sagaId,
            'items'    => [['product_id' => $product->id, 'quantity' => 5]],
        ]);

        $response->assertStatus(422)
                 ->assertJsonStructure(['error']);

        // Stock should not have changed
        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity);
    }

    // ── Release (compensating transaction) ────────────────────────────────

    public function test_can_release_reservations(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 10]);
        $sagaId  = (string) \Illuminate\Support\Str::uuid();

        // First reserve
        $reserveResponse = $this->postJson('/api/reservations', [
            'order_id' => 3,
            'saga_id'  => $sagaId,
            'items'    => [['product_id' => $product->id, 'quantity' => 4]],
        ]);
        $reserveResponse->assertCreated();
        $reservationIds = $reserveResponse->json('reservation_ids');

        // Now release (compensating transaction)
        $releaseResponse = $this->deleteJson('/api/reservations/release', [
            'order_id'        => 3,
            'saga_id'         => $sagaId,
            'reservation_ids' => $reservationIds,
        ]);

        $releaseResponse->assertOk()
                        ->assertJsonStructure(['message', 'released']);

        // Stock should be fully available again
        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(10, $product->available_quantity);

        // Reservation status should be 'released'
        $reservation = StockReservation::find($reservationIds[0]);
        $this->assertEquals('released', $reservation->status);
    }

    // ── Product CRUD ──────────────────────────────────────────────────────

    public function test_can_list_products_filtered_by_category(): void
    {
        $this->makeProduct(['sku' => 'W1', 'category' => 'widgets']);
        $this->makeProduct(['sku' => 'G1', 'category' => 'gadgets']);

        $response = $this->getJson('/api/products?category=widgets');
        $response->assertOk();
        $data = $response->json('data');
        foreach ($data as $p) {
            $this->assertEquals('widgets', $p['category']);
        }
    }

    public function test_can_filter_in_stock_products(): void
    {
        $this->makeProduct(['sku' => 'INSTOCK', 'stock_quantity' => 10, 'reserved_quantity' => 0]);
        $this->makeProduct(['sku' => 'OUTSTOCK', 'stock_quantity' => 5, 'reserved_quantity' => 5]);

        $response = $this->getJson('/api/products?in_stock=1');
        $response->assertOk();
        $data = $response->json('data');
        foreach ($data as $p) {
            $this->assertGreaterThan(0, $p['available_quantity']);
        }
    }
}
