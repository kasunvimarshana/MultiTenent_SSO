<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Str;
use Throwable;

/**
 * SagaOrchestrator – Orchestration-based Saga for the "Place Order" workflow.
 *
 * ## Steps
 *   1. create_order        – persist the order record locally (Order Service DB)
 *   2. reserve_inventory   – call Inventory Service to lock stock for every item
 *   3. process_payment     – call Payment Service to charge the customer
 *   4. send_notification   – call Notification Service to e-mail the customer
 *
 * ## Compensating transactions (rollback order: reverse of execution)
 *   - compensate_notification  – mark notification as cancelled (idempotent)
 *   - compensate_payment       – issue a refund via Payment Service
 *   - compensate_inventory     – release the stock reservation via Inventory Service
 *   - compensate_order         – mark order status as "failed"
 *
 * Each step appends an entry to Order::saga_log so the full execution
 * history is visible at any time.
 */
class SagaOrchestrator
{
    /** Steps executed successfully so far (used for rollback). */
    private array $completedSteps = [];

    /** Context data shared across steps (reservation IDs, payment IDs, …). */
    private array $context = [];

    public function __construct(
        private readonly InventoryClient     $inventory,
        private readonly PaymentClient       $payment,
        private readonly NotificationClient  $notification,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC ENTRY POINT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Execute the full "Place Order" Saga.
     *
     * @param  array  $payload  Validated order data
     * @return array            Result with order, saga_id, status, log
     * @throws Throwable
     */
    public function execute(array $payload): array
    {
        $sagaId = (string) Str::uuid();
        $this->context['saga_id'] = $sagaId;

        $order = null;

        try {
            // ── Step 1: Create order locally ──────────────────────────────
            $order = $this->stepCreateOrder($payload, $sagaId);
            $this->completedSteps[] = 'create_order';

            // ── Step 2: Reserve inventory ─────────────────────────────────
            $reservations = $this->stepReserveInventory($order);
            $this->context['reservations'] = $reservations;
            $this->completedSteps[] = 'reserve_inventory';

            // ── Step 3: Process payment ───────────────────────────────────
            $paymentResult = $this->stepProcessPayment($order, $sagaId);
            $this->context['payment_id'] = $paymentResult['payment_id'];
            $this->completedSteps[] = 'process_payment';

            // ── Step 4: Send notification ─────────────────────────────────
            $this->stepSendNotification($order, $paymentResult);
            $this->completedSteps[] = 'send_notification';

            // ── All steps succeeded ───────────────────────────────────────
            $order->status = 'confirmed';
            $order->save();
            $order->appendSagaLog('saga_completed', 'success', ['saga_id' => $sagaId]);

            return [
                'saga_id' => $sagaId,
                'status'  => 'completed',
                'order'   => $order->fresh()->load('items'),
                'log'     => $order->saga_log,
            ];

        } catch (Throwable $e) {
            // ── Saga failed – run compensating transactions ────────────────
            if ($order) {
                $order->appendSagaLog('saga_failed', 'error', [
                    'message' => $e->getMessage(),
                    'failed_at_step' => end($this->completedSteps) ?: 'create_order',
                ]);
            }
            $this->rollback($order);

            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // FORWARD STEPS
    // ─────────────────────────────────────────────────────────────────────

    private function stepCreateOrder(array $payload, string $sagaId): Order
    {
        $order = Order::create([
            'order_number'   => 'ORD-' . strtoupper(Str::random(8)),
            'customer_email' => $payload['customer_email'],
            'customer_name'  => $payload['customer_name'],
            'status'         => 'pending',
            'total_amount'   => 0,
            'saga_id'        => $sagaId,
            'saga_log'       => [],
        ]);

        $totalAmount = 0;
        foreach ($payload['items'] as $item) {
            $subtotal = $item['quantity'] * $item['unit_price'];
            $totalAmount += $subtotal;

            OrderItem::create([
                'order_id'     => $order->id,
                'product_id'   => $item['product_id'],
                'product_name' => $item['product_name'],
                'quantity'     => $item['quantity'],
                'unit_price'   => $item['unit_price'],
                'subtotal'     => $subtotal,
            ]);
        }

        $order->total_amount = $totalAmount;
        $order->save();

        $order->appendSagaLog('create_order', 'success', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'total_amount' => $totalAmount,
        ]);

        return $order;
    }

    private function stepReserveInventory(Order $order): array
    {
        $items = $order->items->map(fn($i) => [
            'product_id' => $i->product_id,
            'quantity'   => $i->quantity,
        ])->toArray();

        $result = $this->inventory->reserve([
            'order_id'   => $order->id,
            'saga_id'    => $this->context['saga_id'],
            'items'      => $items,
        ]);

        $order->appendSagaLog('reserve_inventory', 'success', [
            'reservation_ids' => $result['reservation_ids'] ?? [],
        ]);

        return $result['reservation_ids'] ?? [];
    }

    private function stepProcessPayment(Order $order, string $sagaId): array
    {
        $result = $this->payment->charge([
            'saga_id'        => $sagaId,
            'order_id'       => $order->id,
            'order_number'   => $order->order_number,
            'amount'         => $order->total_amount,
            'currency'       => 'USD',
            'customer_email' => $order->customer_email,
        ]);

        $order->appendSagaLog('process_payment', 'success', [
            'payment_id' => $result['payment_id'],
            'amount'     => $result['amount'],
            'status'     => $result['status'],
        ]);

        return $result;
    }

    private function stepSendNotification(Order $order, array $paymentResult): void
    {
        $this->notification->send([
            'type'           => 'order_confirmed',
            'recipient'      => $order->customer_email,
            'customer_name'  => $order->customer_name,
            'order_number'   => $order->order_number,
            'total_amount'   => $order->total_amount,
            'payment_id'     => $paymentResult['payment_id'],
        ]);

        $order->appendSagaLog('send_notification', 'success', [
            'recipient' => $order->customer_email,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // COMPENSATING TRANSACTIONS (rollback)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Run compensating transactions in reverse order of completed steps.
     */
    public function rollback(?Order $order): void
    {
        $steps = array_reverse($this->completedSteps);

        foreach ($steps as $step) {
            try {
                match ($step) {
                    'send_notification' => $this->compensateNotification($order),
                    'process_payment'   => $this->compensatePayment($order),
                    'reserve_inventory' => $this->compensateInventory($order),
                    'create_order'      => $this->compensateOrder($order),
                    default             => null,
                };
            } catch (Throwable $e) {
                // Log compensation failure but continue rolling back other steps
                if ($order) {
                    $order->appendSagaLog("compensate_{$step}", 'error', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function compensateNotification(?Order $order): void
    {
        // Notification is fire-and-forget; we mark it as cancelled in the log
        $order?->appendSagaLog('compensate_notification', 'success', [
            'note' => 'Notification send cancelled or suppressed',
        ]);
    }

    private function compensatePayment(?Order $order): void
    {
        if (! $order || empty($this->context['payment_id'])) {
            return;
        }

        $result = $this->payment->refund($this->context['payment_id'], [
            'reason'   => 'saga_rollback',
            'order_id' => $order->id,
        ]);

        $order->appendSagaLog('compensate_payment', 'success', [
            'refund_id'  => $result['refund_id'] ?? null,
            'payment_id' => $this->context['payment_id'],
        ]);
    }

    private function compensateInventory(?Order $order): void
    {
        if (! $order || empty($this->context['reservations'])) {
            return;
        }

        $this->inventory->releaseReservations([
            'order_id'        => $order->id,
            'saga_id'         => $this->context['saga_id'],
            'reservation_ids' => $this->context['reservations'],
        ]);

        $order->appendSagaLog('compensate_inventory', 'success', [
            'released' => $this->context['reservations'],
        ]);
    }

    private function compensateOrder(?Order $order): void
    {
        if (! $order) {
            return;
        }
        $order->status = 'failed';
        $order->save();
        $order->appendSagaLog('compensate_order', 'success', [
            'new_status' => 'failed',
        ]);
    }
}
