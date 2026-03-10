<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\SagaOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * SagaController – manages the distributed "Place Order" transaction.
 *
 * Routes:
 *   POST /api/saga/place-order         Execute full Saga
 *   POST /api/saga/{sagaId}/rollback   Manually trigger compensating transactions
 *   GET  /api/saga/{sagaId}/status     Read saga execution log
 */
class SagaController extends Controller
{
    public function __construct(private readonly SagaOrchestrator $orchestrator) {}

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/saga/place-order
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Execute the Place Order Saga.
     *
     * Request body example:
     * {
     *   "customer_email": "alice@example.com",
     *   "customer_name":  "Alice Smith",
     *   "items": [
     *     { "product_id": 1, "product_name": "Widget A", "quantity": 2, "unit_price": 29.99 },
     *     { "product_id": 3, "product_name": "Gadget C", "quantity": 1, "unit_price": 99.00 }
     *   ]
     * }
     *
     * Success response (HTTP 201):
     * {
     *   "saga_id": "uuid",
     *   "status":  "completed",
     *   "order":   { ... },
     *   "log":     [ { "step": "create_order", "result": "success", ... }, ... ]
     * }
     *
     * Failure response (HTTP 422 / 402 / 503 depending on failing service):
     * {
     *   "saga_id": "uuid",
     *   "status":  "rolled_back",
     *   "error":   "Payment processing failed: insufficient funds",
     *   "order":   { "status": "failed", ... },
     *   "log":     [ ... compensating steps ... ]
     * }
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_email'         => 'required|email',
            'customer_name'          => 'required|string|max:255',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer',
            'items.*.product_name'   => 'required|string',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.unit_price'     => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->orchestrator->execute($validator->validated());

            return response()->json($result, 201);

        } catch (Throwable $e) {
            // Find the order by saga_id for the response
            $sagaId = null;
            $order  = null;

            // The orchestrator may have partially created an order; surface its log
            if (str_contains($e->getMessage(), 'saga_id:')) {
                preg_match('/saga_id:([a-f0-9-]+)/', $e->getMessage(), $m);
                $sagaId = $m[1] ?? null;
            }

            if (! $sagaId) {
                $sagaId = $request->input('_saga_id_debug');
            }

            $order = $sagaId
                ? Order::where('saga_id', $sagaId)->first()
                : null;

            $statusCode = match (true) {
                $e->getCode() === 402 => 402,
                $e->getCode() === 422 => 422,
                $e->getCode() === 503 => 503,
                default               => 500,
            };

            return response()->json([
                'saga_id' => $sagaId,
                'status'  => 'rolled_back',
                'error'   => $e->getMessage(),
                'order'   => $order ? $order->load('items') : null,
                'log'     => $order?->saga_log,
            ], $statusCode);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/saga/{sagaId}/rollback
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Manually trigger compensating transactions for a saga that is
     * stuck in a partially-completed state (useful in demos / admin tools).
     *
     * Body may include:
     * {
     *   "completed_steps": ["create_order", "reserve_inventory", "process_payment"],
     *   "payment_id":      "pay_abc123",
     *   "reservation_ids": [1, 2]
     * }
     */
    public function rollback(string $sagaId, Request $request): JsonResponse
    {
        $order = Order::where('saga_id', $sagaId)->firstOrFail();

        // Inject compensating context from the request (admin must supply IDs)
        $completedSteps  = $request->input('completed_steps', []);
        $paymentId       = $request->input('payment_id');
        $reservationIds  = $request->input('reservation_ids', []);

        // Build a temporary orchestrator instance with the provided context
        $orchestrator = app(SagaOrchestrator::class);

        // Expose private properties via reflection (demo only)
        $ref = new \ReflectionClass($orchestrator);

        $stepsProperty = $ref->getProperty('completedSteps');
        $stepsProperty->setAccessible(true);
        $stepsProperty->setValue($orchestrator, $completedSteps);

        $contextProperty = $ref->getProperty('context');
        $contextProperty->setAccessible(true);
        $contextProperty->setValue($orchestrator, [
            'saga_id'      => $sagaId,
            'payment_id'   => $paymentId,
            'reservations' => $reservationIds,
        ]);

        $orchestrator->rollback($order);

        return response()->json([
            'saga_id' => $sagaId,
            'status'  => 'rolled_back',
            'order'   => $order->fresh()->load('items'),
            'log'     => $order->fresh()->saga_log,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/saga/{sagaId}/status
    // ─────────────────────────────────────────────────────────────────────

    public function status(string $sagaId): JsonResponse
    {
        $order = Order::with('items')
            ->where('saga_id', $sagaId)
            ->firstOrFail();

        return response()->json([
            'saga_id'      => $sagaId,
            'order_status' => $order->status,
            'order'        => $order,
            'log'          => $order->saga_log ?? [],
        ]);
    }
}
