<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Standard CRUD controller for Order resources.
 *
 * Supports filtering by status, customer_email, date range, and pagination.
 */
class OrderController extends Controller
{
    // ── List orders ───────────────────────────────────────────────────────

    /**
     * GET /api/orders
     *
     * Query params:
     *   status         (pending|confirmed|failed|cancelled)
     *   customer_email
     *   from_date      (Y-m-d)
     *   to_date        (Y-m-d)
     *   min_amount
     *   max_amount
     *   search         (searches order_number and customer_name)
     *   per_page       (default 15)
     *   page           (default 1)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with('items');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($email = $request->query('customer_email')) {
            $query->where('customer_email', $email);
        }
        if ($from = $request->query('from_date')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to_date')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($min = $request->query('min_amount')) {
            $query->where('total_amount', '>=', (float) $min);
        }
        if ($max = $request->query('max_amount')) {
            $query->where('total_amount', '<=', (float) $max);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $orders  = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($orders);
    }

    // ── Create order ──────────────────────────────────────────────────────

    /**
     * POST /api/orders
     *
     * Basic order creation (no Saga). Use /api/saga/place-order for the
     * full distributed transaction workflow.
     */
    public function store(Request $request): JsonResponse
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

        $data = $validator->validated();

        $totalAmount = 0;
        $order       = Order::create([
            'order_number'   => 'ORD-' . strtoupper(substr(uniqid(), -8)),
            'customer_email' => $data['customer_email'],
            'customer_name'  => $data['customer_name'],
            'status'         => 'pending',
            'total_amount'   => 0,
        ]);

        foreach ($data['items'] as $item) {
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

        return response()->json($order->load('items'), 201);
    }

    // ── Get single order ──────────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);
        return response()->json($order);
    }

    // ── Update order ──────────────────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'customer_email' => 'sometimes|email',
            'customer_name'  => 'sometimes|string|max:255',
            'status'         => 'sometimes|in:pending,confirmed,failed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $order->update($validator->validated());

        return response()->json($order->load('items'));
    }

    // ── Delete order ──────────────────────────────────────────────────────

    public function destroy(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $order->items()->delete();
        $order->delete();

        return response()->json(['message' => 'Order deleted.'], 200);
    }

    // ── List items for a specific order ───────────────────────────────────

    public function items(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        return response()->json($order->items);
    }
}
