<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * ReservationController – manages stock reservations for Saga support.
 *
 * POST   /api/reservations            Reserve stock (Saga step 2)
 * DELETE /api/reservations/release    Release reserved stock (compensating action)
 * GET    /api/reservations            List reservations
 */
class ReservationController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // POST /api/reservations
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Reserve stock for all items in one atomic DB transaction.
     *
     * Request:
     * {
     *   "order_id": 42,
     *   "saga_id":  "uuid",
     *   "items": [
     *     { "product_id": 1, "quantity": 2 },
     *     { "product_id": 3, "quantity": 1 }
     *   ]
     * }
     *
     * Success (201):
     * { "reservation_ids": [10, 11], "message": "Stock reserved" }
     *
     * Failure (422):
     * { "error": "Insufficient stock for product 'Widget A' (requested 5, available 3)" }
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id'           => 'required|integer',
            'saga_id'            => 'required|uuid',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity'   => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data            = $validator->validated();
        $reservationIds  = [];

        DB::beginTransaction();
        try {
            foreach ($data['items'] as $item) {
                // Lock the product row to prevent race conditions
                $product = Product::lockForUpdate()->find($item['product_id']);

                if (! $product) {
                    throw new \RuntimeException("Product {$item['product_id']} not found.", 404);
                }

                if ($product->available_quantity < $item['quantity']) {
                    throw new \RuntimeException(
                        "Insufficient stock for product '{$product->name}' "
                        . "(requested {$item['quantity']}, available {$product->available_quantity}).",
                        422
                    );
                }

                $product->reserved_quantity += $item['quantity'];
                $product->save();

                $reservation      = StockReservation::create([
                    'product_id' => $product->id,
                    'order_id'   => $data['order_id'],
                    'saga_id'    => $data['saga_id'],
                    'quantity'   => $item['quantity'],
                    'status'     => 'active',
                ]);

                $reservationIds[] = $reservation->id;
            }

            DB::commit();

            return response()->json([
                'reservation_ids' => $reservationIds,
                'message'         => 'Stock reserved successfully.',
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // DELETE /api/reservations/release  (compensating transaction)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Release reserved stock – compensating action for Saga rollback.
     *
     * Request:
     * {
     *   "order_id":        42,
     *   "saga_id":         "uuid",
     *   "reservation_ids": [10, 11]
     * }
     */
    public function release(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id'         => 'required|integer',
            'saga_id'          => 'required|string',
            'reservation_ids'  => 'required|array|min:1',
            'reservation_ids.*'=> 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        DB::beginTransaction();
        try {
            $reservations = StockReservation::lockForUpdate()
                ->whereIn('id', $data['reservation_ids'])
                ->where('saga_id', $data['saga_id'])
                ->where('status', 'active')
                ->get();

            foreach ($reservations as $reservation) {
                $product = Product::lockForUpdate()->find($reservation->product_id);
                if ($product) {
                    $product->reserved_quantity = max(
                        0,
                        $product->reserved_quantity - $reservation->quantity
                    );
                    $product->save();
                }

                $reservation->status = 'released';
                $reservation->save();
            }

            DB::commit();

            return response()->json([
                'message'  => 'Reservations released successfully.',
                'released' => $reservations->pluck('id'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/reservations
    // ─────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = StockReservation::with('product');

        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', $orderId);
        }
        if ($sagaId = $request->query('saga_id')) {
            $query->where('saga_id', $sagaId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }
}
