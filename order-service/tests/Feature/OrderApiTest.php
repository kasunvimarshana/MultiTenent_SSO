<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the Order CRUD API.
 */
class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'order_number'   => 'ORD-TEST001',
            'customer_email' => 'test@example.com',
            'customer_name'  => 'Test User',
            'status'         => 'pending',
            'total_amount'   => 59.98,
        ], $overrides));

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => 1,
            'product_name' => 'Widget A',
            'quantity'     => 2,
            'unit_price'   => 29.99,
            'subtotal'     => 59.98,
        ]);

        return $order;
    }

    // ── GET /api/orders ───────────────────────────────────────────────────

    public function test_can_list_orders(): void
    {
        $this->createOrder();
        $this->createOrder([
            'order_number' => 'ORD-TEST002',
            'status'       => 'confirmed',
            'total_amount' => 99.0,
        ]);

        $response = $this->getJson('/api/orders');
        $response->assertOk()
                 ->assertJsonStructure(['data', 'total', 'per_page', 'current_page']);
    }

    public function test_can_filter_orders_by_status(): void
    {
        $this->createOrder(['order_number' => 'ORD-PEND1', 'status' => 'pending']);
        $this->createOrder(['order_number' => 'ORD-CONF1', 'status' => 'confirmed']);

        $response = $this->getJson('/api/orders?status=confirmed');
        $response->assertOk();
        $data = $response->json('data');
        foreach ($data as $order) {
            $this->assertEquals('confirmed', $order['status']);
        }
    }

    public function test_can_filter_orders_by_amount_range(): void
    {
        $this->createOrder(['order_number' => 'ORD-LOW1', 'total_amount' => 10.00]);
        $this->createOrder(['order_number' => 'ORD-HIGH1', 'total_amount' => 200.00]);

        $response = $this->getJson('/api/orders?min_amount=50&max_amount=150');
        $response->assertOk();
        $data = $response->json('data');
        foreach ($data as $order) {
            $this->assertGreaterThanOrEqual(50, $order['total_amount']);
            $this->assertLessThanOrEqual(150, $order['total_amount']);
        }
    }

    // ── POST /api/orders ──────────────────────────────────────────────────

    public function test_can_create_order(): void
    {
        $payload = [
            'customer_email' => 'alice@example.com',
            'customer_name'  => 'Alice Smith',
            'items'          => [
                ['product_id' => 1, 'product_name' => 'Widget A', 'quantity' => 2, 'unit_price' => 29.99],
            ],
        ];

        $response = $this->postJson('/api/orders', $payload);
        $response->assertCreated()
                 ->assertJsonPath('customer_email', 'alice@example.com')
                 ->assertJsonPath('status', 'pending')
                 ->assertJsonCount(1, 'items');
    }

    public function test_create_order_validates_required_fields(): void
    {
        $response = $this->postJson('/api/orders', []);
        $response->assertStatus(422)
                 ->assertJsonStructure(['errors']);
    }

    // ── GET /api/orders/{id} ──────────────────────────────────────────────

    public function test_can_get_single_order(): void
    {
        $order    = $this->createOrder();
        $response = $this->getJson("/api/orders/{$order->id}");
        $response->assertOk()
                 ->assertJsonPath('id', $order->id)
                 ->assertJsonStructure(['items']);
    }

    public function test_returns_404_for_missing_order(): void
    {
        $this->getJson('/api/orders/99999')->assertNotFound();
    }

    // ── PUT /api/orders/{id} ──────────────────────────────────────────────

    public function test_can_update_order_status(): void
    {
        $order    = $this->createOrder();
        $response = $this->putJson("/api/orders/{$order->id}", ['status' => 'confirmed']);
        $response->assertOk()
                 ->assertJsonPath('status', 'confirmed');
    }

    // ── DELETE /api/orders/{id} ───────────────────────────────────────────

    public function test_can_delete_order(): void
    {
        $order = $this->createOrder();
        $this->deleteJson("/api/orders/{$order->id}")->assertOk();
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    // ── GET /api/orders/{id}/items ────────────────────────────────────────

    public function test_can_get_order_items(): void
    {
        $order    = $this->createOrder();
        $response = $this->getJson("/api/orders/{$order->id}/items");
        $response->assertOk()
                 ->assertJsonCount(1);
    }
}
