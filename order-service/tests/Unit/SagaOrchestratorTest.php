<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\InventoryClient;
use App\Services\NotificationClient;
use App\Services\PaymentClient;
use App\Services\SagaOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for the SagaOrchestrator.
 *
 * Mocks all external HTTP clients so the tests run without live services.
 */
class SagaOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private SagaOrchestrator $orchestrator;
    private InventoryClient    $inventory;
    private PaymentClient      $payment;
    private NotificationClient $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory     = Mockery::mock(InventoryClient::class);
        $this->payment       = Mockery::mock(PaymentClient::class);
        $this->notification  = Mockery::mock(NotificationClient::class);

        $this->orchestrator = new SagaOrchestrator(
            $this->inventory,
            $this->payment,
            $this->notification,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function samplePayload(): array
    {
        return [
            'customer_email' => 'alice@example.com',
            'customer_name'  => 'Alice Smith',
            'items'          => [
                [
                    'product_id'   => 1,
                    'product_name' => 'Widget A',
                    'quantity'     => 2,
                    'unit_price'   => 29.99,
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Happy-path
    // ─────────────────────────────────────────────────────────────────────

    public function test_successful_saga_creates_confirmed_order(): void
    {
        $this->inventory
            ->shouldReceive('reserve')
            ->once()
            ->andReturn(['reservation_ids' => [42]]);

        $this->payment
            ->shouldReceive('charge')
            ->once()
            ->andReturn([
                'payment_id' => 'pay_test_001',
                'amount'     => 59.98,
                'status'     => 'completed',
            ]);

        $this->notification
            ->shouldReceive('send')
            ->once();

        $result = $this->orchestrator->execute($this->samplePayload());

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals('confirmed', $result['order']->status);
        $this->assertNotEmpty($result['saga_id']);
        $this->assertCount(5, $result['log']); // 4 steps + saga_completed
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rollback: inventory fails
    // ─────────────────────────────────────────────────────────────────────

    public function test_inventory_failure_rolls_back_order(): void
    {
        $this->inventory
            ->shouldReceive('reserve')
            ->once()
            ->andThrow(new \RuntimeException('Out of stock', 422));

        // Payment should NOT be called
        $this->payment->shouldNotReceive('charge');
        $this->notification->shouldNotReceive('send');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Out of stock');

        try {
            $this->orchestrator->execute($this->samplePayload());
        } catch (\RuntimeException $e) {
            // Order must be in "failed" state after rollback
            $order = Order::first();
            $this->assertNotNull($order);
            $this->assertEquals('failed', $order->status);

            // Saga log must contain compensate_order entry
            $steps = array_column($order->saga_log, 'step');
            $this->assertContains('compensate_order', $steps);

            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rollback: payment fails
    // ─────────────────────────────────────────────────────────────────────

    public function test_payment_failure_releases_inventory_and_cancels_order(): void
    {
        $this->inventory
            ->shouldReceive('reserve')
            ->once()
            ->andReturn(['reservation_ids' => [10, 11]]);

        $this->payment
            ->shouldReceive('charge')
            ->once()
            ->andThrow(new \RuntimeException('Insufficient funds', 402));

        // Notification not called on failure
        $this->notification->shouldNotReceive('send');

        // Inventory release MUST be called as compensation
        $this->inventory
            ->shouldReceive('releaseReservations')
            ->once();

        $this->expectException(\RuntimeException::class);

        try {
            $this->orchestrator->execute($this->samplePayload());
        } catch (\RuntimeException $e) {
            $order = Order::first();
            $this->assertEquals('failed', $order->status);

            $steps = array_column($order->saga_log, 'step');
            $this->assertContains('compensate_inventory', $steps);
            $this->assertContains('compensate_order', $steps);

            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Saga log completeness
    // ─────────────────────────────────────────────────────────────────────

    public function test_saga_log_records_all_steps(): void
    {
        $this->inventory->shouldReceive('reserve')->andReturn(['reservation_ids' => [1]]);
        $this->payment->shouldReceive('charge')->andReturn([
            'payment_id' => 'pay_x',
            'amount'     => 59.98,
            'status'     => 'completed',
        ]);
        $this->notification->shouldReceive('send');

        $result = $this->orchestrator->execute($this->samplePayload());

        $steps = array_column($result['log'], 'step');
        foreach (['create_order', 'reserve_inventory', 'process_payment', 'send_notification', 'saga_completed'] as $expected) {
            $this->assertContains($expected, $steps, "Expected step '{$expected}' in saga log");
        }
    }
}
